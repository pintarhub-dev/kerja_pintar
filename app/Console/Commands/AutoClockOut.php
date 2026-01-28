<?php

namespace App\Console\Commands;

use App\Models\AttendanceSummary;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoClockOut extends Command
{
    protected $signature = 'attendance:auto-clockout';
    protected $description = 'Otomatis clock out karyawan yang lupa absen pulang';

    public function handle()
    {
        $now = Carbon::now();
        $today = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();

        $this->info("Run Auto Clock Out untuk tanggal: $today...");

        // ==========================================================
        // BERSIHKAN SISA KEMARIN (Termasuk Shift Malam Kemarin)
        // ==========================================================
        // Logika: Karyawan shift malam (22:00 - 07:00) harusnya sudah pulang tadi pagi.
        // Kalau sampai jam 23:55 malam ini belum clockout, berarti dia LUPA.

        $yesterdaySummaries = AttendanceSummary::where('date', $yesterday)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->get();

        foreach ($yesterdaySummaries as $summary) {
            $this->forceClockOut($summary, "SYSTEM_AUTO_LOG (Lupa H+1)");
        }

        // ==========================================================
        // BERSIHKAN HARI INI (Hati-hati Shift Malam)
        // ==========================================================

        $todaySummaries = AttendanceSummary::where('date', $today)
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->get();

        foreach ($todaySummaries as $summary) {

            // --- CEK SHIFT MALAM (CROSS DAY) ---
            // Kita cek apakah jadwal pulang dia lebih kecil dari jadwal masuk?
            // Contoh: Masuk 22:00, Pulang 07:00. (07:00 < 22:00) -> CROSS DAY

            $isCrossDay = false;
            if ($summary->schedule_in && $summary->schedule_out) {
                if ($summary->schedule_out < $summary->schedule_in) {
                    $isCrossDay = true;
                }
            }

            if ($isCrossDay) {
                // Skip jangan di-clockout. Biarkan sampai besok pagi.
                $this->info("Skip (Shift Malam): {$summary->employee->full_name}");
                continue;
            }

            // Kalau bukan shift malam (Shift Pagi/Siang).
            $this->forceClockOut($summary, "SYSTEM_AUTO_LOG");
        }

        $this->info("Selesai. Total diproses: " . $todaySummaries->count());
    }

    private function forceClockOut($summary, $deviceId)
    {
        DB::transaction(function () use ($summary, $deviceId) {
            // Tentukan jam checkout paksa
            // Default: Jam jadwal pulang dia. Kalau gak ada jadwal, set 23:59:59
            $forceTime = $summary->schedule_out ?? '23:59:59';

            // A. Update Detail Sesi
            $summary->details()
                ->whereNull('clock_out_time')
                ->update([
                    'clock_out_time' => $forceTime,
                    'clock_out_device_id' => $deviceId,
                ]);

            // B. Update Summary Induk
            $summary->update([
                'clock_out' => $forceTime,
                'clock_out_device_id' => $deviceId,
            ]);

            $this->info("Force Out: {$summary->employee->full_name} ({$deviceId})");
        });
    }
}
