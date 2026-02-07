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
    // --- HELPER FUNCTION UNTUK VALIDASI LOGIC (BIAR GAK DUPLIKAT DI STORE & UPDATE) ---
    private function validateLeaveLogic($employee, $leaveTypeId, $startDate, $endDate, $excludeRequestId = null)
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $duration = $start->diffInDays($end) + 1;

        // 1. Cek Bentrok Tanggal (Overlap)
        $queryOverlap = LeaveRequest::where('employee_id', $employee->id)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->where(function ($query) use ($startDate, $endDate) {
                $query->where('start_date', '<=', $endDate)
                    ->where('end_date', '>=', $startDate);
            });

        if ($excludeRequestId) {
            $queryOverlap->where('id', '!=', $excludeRequestId);
        }

        if ($queryOverlap->exists()) {
            return ['error' => 'Anda sudah memiliki pengajuan cuti pada rentang tanggal ini.'];
        }

        // 2. Cek Data Absensi (Clock In)
        $hasAttendance = AttendanceSummary::where('employee_id', $employee->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('clock_in')
            ->first();

        if ($hasAttendance) {
            return ['error' => 'Pengajuan ditolak. Anda tercatat sudah hadir pada tanggal ' . Carbon::parse($hasAttendance->date)->format('d-m-Y') . '.'];
        }

        $leaveType = LeaveType::find($leaveTypeId);

        // 3. Validasi Masa Kerja (Hanya Cek saat Create, saat Update asumsinya user sama)
        if (!$excludeRequestId) {
            $minMonths = $leaveType->min_months_of_service ?? 0;
            if ($minMonths > 0) {
                if (!$employee->join_date) {
                    return ['error' => 'Tanggal bergabung belum diatur HRD.'];
                }
                $monthsWorked = Carbon::parse($employee->join_date)->diffInMonths(now());
                if ($monthsWorked < $minMonths) {
                    return ['error' => 'Masa kerja belum cukup. Minimal: ' . $minMonths . ' bulan.'];
                }
            }
        }

        // 4. Cek Saldo
        if ($leaveType->deducts_quota) {
            $year = $start->year;
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->first();

            if (!$balance) {
                return ['error' => "Saldo cuti tahun $year belum tersedia. Hubungi HRD."];
            }

            if ($balance->remaining < $duration) {
                return ['error' => "Sisa saldo tidak mencukupi. Sisa: {$balance->remaining}, Diminta: $duration."];
            }
        }

        return ['success' => true, 'duration' => $duration];
    }


    public function index(Request $request)
    {
        $employee = $request->user()->employee;

        $query = LeaveRequest::with('leaveType')
            ->where('employee_id', $employee->id);

        // Filter Bulan & Tahun
        if ($request->has('month') && $request->has('year')) {
            $query->whereMonth('start_date', $request->month)
                ->whereYear('start_date', $request->year);
        }

        $requests = $query->orderBy('created_at', 'desc')
            ->paginate(10);

        $data = $requests->through(function ($item) {
            return [
                'id' => $item->id,
                'leave_type_id' => $item->leave_type_id,
                'leave_type' => $item->leaveType->name,
                'start_date' => $item->start_date,
                'end_date' => $item->end_date,
                'duration_days' => $item->duration_days,
                'reason' => $item->reason,
                'attachment_url' => $item->attachment ? url(Storage::url($item->attachment)) : null,
                'status' => $item->status,
                'status_label' => $this->getStatusLabel($item->status),
                'rejection_reason' => $item->rejection_reason,
                'created_at' => $item->created_at->format('d M Y H:i'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function detail($id)
    {
        $user = auth()->user();

        $leaveRequest = LeaveRequest::with('leaveType')
            ->where('id', $id)
            ->where('employee_id', $user->employee->id)
            ->first();

        if (!$leaveRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data cuti tidak ditemukan atau Anda tidak memiliki akses.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $leaveRequest->id,
                'status' => $leaveRequest->status,
                'start_date' => $leaveRequest->start_date->format('Y-m-d'),
                'end_date' => $leaveRequest->end_date->format('Y-m-d'),
                'duration_days' => $leaveRequest->duration_days,
                'reason' => $leaveRequest->reason,
                'leave_type' => [
                    'name' => $leaveRequest->leaveType->name ?? 'Cuti',
                ],
                'attachment_url' => $leaveRequest->attachment
                    ? asset('storage/' . $leaveRequest->attachment)
                    : null,
                'created_at' => $leaveRequest->created_at->format('d M Y H:i'),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $employee = $request->user()->employee;

        $validator = Validator::make($request->all(), [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        // --- PAKAI HELPER VALIDASI ---
        $logicCheck = $this->validateLeaveLogic(
            $employee,
            $request->leave_type_id,
            $request->start_date,
            $request->end_date
        );

        if (isset($logicCheck['error'])) {
            return response()->json(['message' => $logicCheck['error']], 422);
        }

        $duration = $logicCheck['duration'];
        $leaveType = LeaveType::find($request->leave_type_id);

        // Cek Attachment Wajib
        if ($leaveType->requires_file && !$request->hasFile('attachment')) {
            return response()->json(['message' => 'Wajib upload lampiran untuk jenis cuti ini.'], 422);
        }

        try {
            DB::beginTransaction();

            // 1. POTONG SALDO DI AWAL (BOOKING QUOTA)
            if ($leaveType->deducts_quota) {
                $year = Carbon::parse($request->start_date)->year;

                // Lock row biar gak balapan (Race Condition)
                $balance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $request->leave_type_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                // Validasi ulang
                if (!$balance || $balance->remaining < $duration) {
                    throw new \Exception('Saldo tidak mencukupi saat proses pengajuan.');
                }

                $balance->increment('taken', $duration);
            }

            $attachmentPath = null;
            if ($request->hasFile('attachment')) {
                $attachmentPath = $request->file('attachment')
                    ->store("leave_attachments/{$employee->tenant_id}/" . date('Y-m'), 'public');
            }

            $leaveRequest = LeaveRequest::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $request->leave_type_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'duration_days' => $duration,
                'reason' => $request->reason,
                'attachment' => $attachmentPath,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan berhasil dikirim.',
                'data' => $leaveRequest
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        $employee = $user->employee;

        $leaveRequest = LeaveRequest::where('id', $id)
            ->where('employee_id', $employee->id)
            ->first();

        if (!$leaveRequest) return response()->json(['message' => 'Pengajuan tidak ditemukan'], 404);

        if ($leaveRequest->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan yang sudah diproses tidak bisa diedit.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:500',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

        // Kita exclude ID sendiri biar gak overlap sama diri sendiri
        $logicCheck = $this->validateLeaveLogic(
            $employee,
            $request->leave_type_id,
            $request->start_date,
            $request->end_date,
            $leaveRequest->id // Exclude ID
        );

        if (isset($logicCheck['error'])) {
            return response()->json(['message' => $logicCheck['error']], 422);
        }

        // Ambil Data Lama untuk Refund
        $oldDuration = $leaveRequest->duration_days;
        $oldLeaveTypeId = $leaveRequest->leave_type_id;
        $oldYear = Carbon::parse($leaveRequest->start_date)->year;
        $oldDeducts = $leaveRequest->leaveType->deducts_quota;

        // Hitung Durasi Baru
        $newStart = Carbon::parse($request->start_date);
        $newEnd = Carbon::parse($request->end_date);
        $newDuration = $newStart->diffInDays($newEnd) + 1;
        $newLeaveType = LeaveType::find($request->leave_type_id);
        $newYear = $newStart->year;

        try {
            DB::beginTransaction();

            // 1. REFUND SALDO LAMA DULU
            if ($oldDeducts) {
                $oldBalance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $oldLeaveTypeId)
                    ->where('year', $oldYear)
                    ->lockForUpdate()
                    ->first();

                if ($oldBalance) {
                    $oldBalance->decrement('taken', $oldDuration);
                }
            }

            // 2. POTONG SALDO BARU
            if ($newLeaveType->deducts_quota) {
                $newBalance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $newLeaveType->id)
                    ->where('year', $newYear)
                    ->lockForUpdate()
                    ->first();

                if (!$newBalance || $newBalance->remaining < $newDuration) {
                    throw new \Exception('Saldo tidak mencukupi untuk perubahan jadwal ini.');
                }

                $newBalance->increment('taken', $newDuration);
            }

            // Update Data
            if ($request->hasFile('attachment')) {
                if ($leaveRequest->attachment) {
                    Storage::disk('public')->delete($leaveRequest->attachment);
                }
                $leaveRequest->attachment = $request->file('attachment')
                    ->store("leave_attachments/{$employee->tenant_id}/" . date('Y-m'), 'public');
            }

            $leaveRequest->update([
                'leave_type_id' => $request->leave_type_id,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'duration_days' => $newDuration,
                'reason' => $request->reason,
            ]);

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pengajuan berhasil diperbarui.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $employee = $request->user()->employee;
        $leaveRequest = LeaveRequest::with('leaveType')->where('id', $id)->where('employee_id', $employee->id)->first();

        if (!$leaveRequest) return response()->json(['message' => 'Data tidak ditemukan.'], 404);
        if ($leaveRequest->status !== 'pending') return response()->json(['message' => 'Tidak bisa membatalkan.'], 403);

        try {
            DB::beginTransaction();

            // 1. REFUND SALDO (Kembalikan Booking)
            if ($leaveRequest->leaveType->deducts_quota) {
                $year = Carbon::parse($leaveRequest->start_date)->year;
                $balance = LeaveBalance::where('employee_id', $employee->id)
                    ->where('leave_type_id', $leaveRequest->leave_type_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if ($balance) {
                    $balance->decrement('taken', $leaveRequest->duration_days);
                }
            }

            // Delete File & Record
            if ($leaveRequest->attachment) {
                Storage::disk('public')->delete($leaveRequest->attachment);
            }
            $leaveRequest->delete();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Pengajuan berhasil dibatalkan.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // Helper Label Status (Biar di Flutter gak ribet if-else string)
    private function getStatusLabel($status)
    {
        return match ($status) {
            'pending' => 'Menunggu Persetujuan',
            'approved_by_supervisor' => 'Disetujui Supervisor',
            'approved_by_manager' => 'Disetujui Manager',
            'approved_by_hr' => 'Disetujui HRD (Final)',
            'rejected' => 'Ditolak',
            'cancelled' => 'Dibatalkan',
            default => 'Unknown',
        };
    }
}
