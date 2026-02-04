<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;
use App\Traits\Blameable;

class Shift extends Model
{
    use HasFactory, SoftDeletes;
    use BelongsToTenant, Blameable;

    protected $guarded = ['id'];

    public function isLocked(): bool
    {
        // Cek 1: Apakah Shift ini bagian dari Pattern yang SEDANG dipakai karyawan?
        $isAssigned = DB::table('schedule_pattern_details')
            ->join('employee_schedule_assignments', 'schedule_pattern_details.schedule_pattern_id', '=', 'employee_schedule_assignments.schedule_pattern_id')
            ->where('schedule_pattern_details.shift_id', $this->id)
            ->whereNull('employee_schedule_assignments.deleted_at') // Cek assignment aktif
            ->exists();

        if ($isAssigned) {
            return true;
        }

        // Cek 2: Apakah Shift ini sudah pernah dipakai untuk generate absen (History)?
        // Jika shift diubah, history lama bisa rusak perhitungannya.
        $hasHistory = DB::table('attendance_summaries')
            ->where('shift_id', $this->id)
            ->exists();

        return $hasHistory;
    }

    protected $fillable = [
        'name',
        'is_day_off',
        'is_flexible',
        'start_time',
        'end_time',
        'break_duration_minutes',
        'daily_target_minutes',
        'late_tolerance_minutes',
    ];

    // Kebutuhan Flexible Shift
    protected $casts = [
        'is_flexible' => 'boolean',
    ];

    public function getLabelAttribute(): string
    {
        $text = $this->name;

        if ($this->is_day_off) {
            return $text . ' (LIBUR / OFF)';
        }

        if ($this->is_flexible) {
            return $text . ' (' . $this->daily_target_minutes . ' minutes)';
        }

        if ($this->start_time && $this->end_time) {
            // Potong detik (08:00:00 -> 08:00) biar rapi
            $start = substr($this->start_time, 0, 5);
            $end = substr($this->end_time, 0, 5);
            return $text . " ($start - $end)";
        }

        return $text;
    }
}
