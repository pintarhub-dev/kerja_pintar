<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json(['message' => 'Data karyawan tidak ditemukan.'], 404);
        }

        $currentYear = now()->year;

        // 1. Ambil SEMUA Jenis Cuti
        $allTypes = LeaveType::all();

        // 2. Map data types dan gabungkan dengan info saldo
        $data = $allTypes->map(function ($type) use ($employee, $currentYear) {

            // Cari saldo user untuk tipe ini di tahun ini
            $balance = LeaveBalance::where('employee_id', $employee->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $currentYear)
                ->first();

            // Logic Sisa:
            // Jika tipe cuti memotong kuota (Tahunan), ambil dari DB.
            // Jika tidak memotong kuota (Sakit/Izin), set 0 atau 'Unlimited' (tergantung UI mau nampilin apa).
            $remaining = 0;
            if ($balance) {
                $remaining = (int) $balance->remaining;
            } else {
                // Jika belum ada record saldo tapi tipe ini punya kuota default, bisa ditampilkan
                // Atau biarkan 0.
                $remaining = $type->deducts_quota ? 0 : 0;
            }

            return [
                'id' => $balance ? $balance->id : null,
                'leave_type_id' => $type->id, // PENTING: Ini yang dikirim saat submit form
                'leave_type_name' => $type->name,
                'code' => $type->code,
                'entitlement' => $balance ? (int)$balance->entitlement : 0,
                'carried_over' => $balance ? (int) $balance->carried_over : 0,
                'taken' => $balance ? (int)$balance->taken : 0,
                'remaining' => $remaining,
                'deducts_quota' => (bool) $type->deducts_quota,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
