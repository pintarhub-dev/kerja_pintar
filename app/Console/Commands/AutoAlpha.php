<?php

namespace App\Console\Commands;

use App\Models\AttendanceSummary;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleOverride;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AutoAlpha extends Command
{
    protected $signature = 'attendance:auto-alpha';
    protected $description = 'Generate status Alpha untuk karyawan yang tidak hadir tanpa kabar';

    public function handle()
    {
        $today = Carbon::today()->toDateString();
        $totalAlpha = 0;
        $this->info("Run Auto Alpha untuk tanggal: $today...");

        // Ambil semua karyawan aktif (kecuali resign)
        $employees = Employee::whereNotIn('employment_status', ['resigned', 'terminated', 'retired'])
            ->whereHas('scheduleAssignments')
            ->get();

        foreach ($employees as $employee) {

            // 1. Cek Apakah Sudah Ada Absensi/Cuti/Sakit?
            // Kalau sudah ada record di summary (entah itu present, sick, leave, atau permit), SKIP.
            $exists = AttendanceSummary::where('employee_id', $employee->id)
                ->where('date', $today)
                ->exists();

            if ($exists) {
                continue;
            }

            // 2. Cek Apakah Hari Ini Dia LIBUR?
            $isWorkingDay = false;

            // A. Cek Override
            $override = ScheduleOverride::where('employee_id', $employee->id)->where('date', $today)->first();
            if ($override) {
                if ($override->shift_id) {
                    $isWorkingDay = true;
                    $shift = $override->shift;
                }
            } else {
                // B. Cek Pattern Reguler
                $assignment = EmployeeScheduleAssignment::where('employee_id', $employee->id)
                    ->whereDate('effective_date', '<=', $today)
                    ->orderBy('effective_date', 'desc')
                    ->first();

                if ($assignment) {
                    $shift = $assignment->getShiftOnDate($today);
                    if ($shift) {
                        $isWorkingDay = true;
                    }
                }
            }

            // 3. Kalau Hari Kerja TAPI Gak Ada Data -> ALPHA
            if ($isWorkingDay && isset($shift)) {

                AttendanceSummary::create([
                    'tenant_id' => $employee->tenant_id,
                    'employee_id' => $employee->id,
                    'date' => $today,
                    'schedule_id' => $assignment->schedule_pattern_id ?? null,
                    'shift_id' => $shift->id,
                    'schedule_in' => $shift->is_flexible ? null : $shift->start_time,
                    'schedule_out' => $shift->is_flexible ? null : $shift->end_time,
                    'status' => 'alpha',
                    'late_minutes' => 0,
                    'early_leave_minutes' => 0,
                    'overtime_minutes' => 0,
                ]);

                $totalAlpha++;

                $this->info("Vonis Alpha: {$employee->full_name}");
            }
        }

        $this->info("Selesai. Total diproses: " . $totalAlpha);
    }
}
