<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\AttendanceSummary;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class LeaveRequestController extends Controller
{
    // 1. LIST HISTORY CUTI
    public function index(Request $request)
    {
        $employee = $request->user()->employee;

        $requests = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $data = $requests->through(function ($item) {
            return [
                'id' => $item->id,
                'leave_type' => $item->leaveType->name,
                'start_date' => $item->start_date,
                'end_date' => $item->end_date,
                'duration_days' => $item->duration_days,
                'reason' => $item->reason,
                'status' => $item->status, // pending, approved_by_xx, rejected
                'rejection_reason' => $item->rejection_reason,
                'created_at' => $item->created_at->toDateTimeString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // PENGAJUAN CUTI (CREATE)
    public function store(Request $request)
    {
        $employee = $request->user()->employee;

        // --- VALIDASI INPUT DASAR ---
        $validator = Validator::make($request->all(), [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048', // Max 2MB
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $leaveTypeId = $request->leave_type_id;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        // Hitung Durasi (Logic sama dengan Filament)
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $duration = $start->diffInDays($end) + 1; // +1 karena inklusif

        // 1. Cek Bentrok Tanggal (Overlap)
        $isOverlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            })
            ->exists();

        if ($isOverlap) {
            return response()->json(['message' => 'Anda sudah memiliki pengajuan cuti pada rentang tanggal ini.'], 422);
        }

        // 1.5. Cek Data Absensi (Clock In)
        // Mencegah cuti di hari dimana karyawan SUDAH masuk kerja
        $hasAttendance = AttendanceSummary::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_in')
            ->first();

        if ($hasAttendance) {
            return response()->json([
                'message' => 'Pengajuan ditolak. Anda tercatat sudah hadir (Clock In) pada tanggal ' . Carbon::parse($hasAttendance->date)->format('d-m-Y') . '.',
            ], 422);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        // 2. VALIDASI MASA KERJA
        $minMonths = $leaveType->min_months_of_service ?? 0; // Default 0 jika null
        if ($minMonths > 0) {
            // Pastikan tanggal join ada
            if (!$employee->join_date) {
                return response()->json([
                    'message' => 'Tanggal bergabung (Join Date) Anda belum diatur oleh HRD, sehingga sistem tidak bisa memverifikasi kelayakan cuti.'
                ], 422);
            }

            $joinDate = Carbon::parse($employee->join_date);

            // Karyawan harus sudah bekerja X bulan SAAT mengajukan request.
            $monthsWorked = $joinDate->diffInMonths(now());
            if ($monthsWorked < $minMonths) {
                return response()->json([
                    'message' => 'Masa kerja Anda belum memenuhi syarat untuk jenis cuti ini.',
                    'meta' => [
                        'required_months' => $minMonths,
                        'current_months' => (int) $monthsWorked,
                        'join_date' => $employee->join_date,
                    ]
                ], 422);
            }
        }

        // Cek Attachment Wajib?
        if ($leaveType->requires_file && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Jenis cuti ini mewajibkan upload lampiran (Surat Dokter).'], 422);
        }

        // 3. Cek Saldo (Jika tipe cuti memotong kuota)
        if ($leaveType->deducts_quota) {
            $year = $start->year;
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->first();

            if (!$balance) {
                return response()->json(['message' => "Saldo cuti tahun $year belum tersedia. Hubungi HRD."], 422);
            }

            if ($balance->remaining < $duration) {
                return response()->json([
                    'message' => 'Sisa saldo cuti tidak mencukupi.',
                    'meta' => [
                        'remaining' => $balance->remaining,
                        'requested' => $duration
                    ]
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $attachmentPath = null;
            if ($request->hasFile('attachment')) {
                $attachmentPath = $request->file('attachment')
                    ->store('leave_attachments/' . $employee->tenant_id . '/' .
                        date('Y-m'), 'public');
            }

            $leaveRequest = LeaveRequest::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'duration_days' => $duration,
                'reason' => $request->reason,
                'attachment' => $attachmentPath,
                'status' => 'pending', // Default Pending
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan cuti berhasil dikirim.',
                'data' => $leaveRequest
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Terjadi kesalahan sistem.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $employee = $user->employee;

        // 1. Cari Data & Cek Kepemilikan
        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('employee_id', $user->employee->id) // Pastikan punya dia sendiri
            ->first();

        if (!$leaveRequest) {
            return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);
        }

        // 2. Cuma boleh edit kalau status masih Pending
        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'message' => 'Tidak dapat mengubah data. Status pengajuan sudah diproses (' . $leaveRequest->status . ')'
            ], 403);
        }

        // 3. Validasi Input Form
        $request->validate([
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'reason'        => 'required|string|max:255',
            'attachment'    => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        // 4. Update File Lampiran (Jika ada upload baru)
        if ($request->hasFile('attachment')) {
            // Hapus file lama
            if ($leaveRequest->attachment) {
                Storage::disk('public')->delete($leaveRequest->attachment);
            }
            // Simpan file baru
            $path = $request->file('attachment')->store('leave_attachments/' . $employee->tenant_id . '/' .
                date('Y-m'), 'public');
            $leaveRequest->attachment = $path;
        }

        // 5. Update Data Lainnya
        $leaveRequest->update([
            'leave_type_id' => $request->leave_type_id,
            'start_date'    => $request->start_date,
            'end_date'      => $request->end_date,
            'reason'        => $request->reason,
        ]);

        return response()->json([
            'message' => 'Pengajuan cuti berhasil diperbarui',
            'data' => $leaveRequest
        ]);
    }

    // BATALKAN CUTI (DELETE/CANCEL)
    public function destroy(Request $request, $id)
    {
        $employee = $request->user()->employee;

        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$leaveRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.'
            ], 404);
        }

        if ($leaveRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan tidak dapat dibatalkan karena sudah diproses (Disetujui/Ditolak)'
            ], 403);
        }

        $leaveRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan cuti berhasil dibatalkan.'
        ]);
    }
}
