<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use App\Models\AttendanceSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class OvertimeRequestController extends Controller
{
    // 1. HISTORY LEMBUR
    public function index(Request $request)
    {
        $employee = $request->user()->employee;

        $requests = OvertimeRequest::where('employee_id', $employee->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    // 2. PENGAJUAN LEMBUR (DENGAN JEBAKAN)
    public function store(Request $request)
    {
        $employee = $request->user()->employee;

        // Validasi Input Dasar
        $validator = Validator::make($request->all(), [
            'date' => 'required|date',
            'duration_minutes' => 'required|integer|min:1',
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $date = $request->date;
        $requestedDuration = $request->duration_minutes;

        // --- VALIDASI 1: CEK DUPLIKAT ---
        $exists = OvertimeRequest::where('employee_id', $employee->id)
            ->where('date', $date)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Anda sudah memiliki pengajuan lembur pada tanggal ini.'], 422);
        }

        // --- VALIDASI 2: CEK ABSENSI (JEBAKAN UTAMA) ---
        $summary = AttendanceSummary::with('employee.workLocation')
            ->where('employee_id', $employee->id)
            ->where('date', $date)
            ->first();

        // Syarat A: Harus ada data absen, jadwal pulang, dan jam pulang aktual
        if (!$summary || !$summary->schedule_out || !$summary->clock_out) {
            return response()->json([
                'message' => 'Data kehadiran tidak lengkap. Pastikan Anda sudah Clock Out sebelum mengajukan lembur.'
            ], 422);
        }

        // Syarat B: Status tidak boleh Cuti/Sakit/Izin/Alpha
        if (in_array($summary->status, ['leave', 'sick', 'permit', 'alpha'])) {
            return response()->json(['message' => 'Tidak bisa lembur saat status Cuti/Izin.'], 422);
        }

        $timezone = $summary->employee->workLocation->timezone ?? 'Asia/Jakarta';

        // Bersihkan format
        $dateString = $summary->date instanceof Carbon
            ? $summary->date->format('Y-m-d')
            : Carbon::parse($summary->date)->format('Y-m-d');

        $scheduleTimeString = Carbon::parse($summary->schedule_out)->format('H:i:s');
        $actualTimeString   = Carbon::parse($summary->clock_out)->format('H:i:s');

        // Parse Waktu
        $scheduleOut = Carbon::parse("{$dateString} {$scheduleTimeString}", $timezone);

        // Aktual (UTC -> Lokal)
        $actualOut = Carbon::parse("{$dateString} {$actualTimeString}", 'UTC')
            ->setTimezone($timezone);

        // --- JEBAKAN 3: CEK APAKAH BENAR PULANG TELAT? ---
        if ($actualOut->lte($scheduleOut)) {
            return response()->json([
                'message' => 'Berdasarkan data absensi, Anda pulang tepat waktu atau lebih awal. Tidak bisa mengajukan lembur.'
            ], 422);
        }

        // --- JEBAKAN 4: CEK DURASI NGELUNJAK (Opsional) ---
        $maxAllowedMinutes = $actualOut->diffInMinutes($scheduleOut);

        if ($requestedDuration > $maxAllowedMinutes) {
            return response()->json([
                'message' => "Durasi yang diajukan ($requestedDuration menit) melebihi durasi keterlambatan aktual ($maxAllowedMinutes menit).",
                'meta' => [
                    'max_allowed' => $maxAllowedMinutes,
                    'actual_out_local' => $actualOut->toTimeString(),
                    'schedule_out_local' => $scheduleOut->toTimeString()
                ]
            ], 422);
        }

        // --- LOLOS SEMUA VALIDASI -> SIMPAN ---
        $overtime = OvertimeRequest::create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'date' => $date,
            'duration_minutes' => $requestedDuration,
            'reason' => $request->reason,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil dikirim.',
            'data' => $overtime
        ], 201);
    }

    // BATALKAN PENGAJUAN LEMBUR (DELETE/CANCEL)
    public function destroy(Request $request, $id)
    {
        $employee = $request->user()->employee;

        // Cari data request berdasarkan ID & Employee ID (Security: Biar gak hapus punya orang lain)
        $overtimeRequest = OvertimeRequest::where('id', $id)
            ->where('employee_id', $employee->id)
            ->first();

        // 1. Cek Apakah Data Ada?
        if (!$overtimeRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Data pengajuan lembur tidak ditemukan.'
            ], 404);
        }

        // 2. Cek Status (Hanya boleh hapus jika PENDING)
        if ($overtimeRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Pengajuan tidak dapat dibatalkan karena sudah diproses (Disetujui/Ditolak).'
            ], 403);
        }

        // 3. Lakukan Penghapusan (Soft Delete)
        // Karena di Model kamu pakai trait SoftDeletes, data ini gak hilang permanen,
        // cuma kolom deleted_at yang terisi. Aman buat audit.
        $overtimeRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil dibatalkan.'
        ]);
    }
}
