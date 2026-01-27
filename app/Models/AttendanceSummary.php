<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToTenant;
use App\Traits\Blameable;

class AttendanceSummary extends Model
{
    use BelongsToTenant, Blameable;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'schedule_in' => 'datetime:H:i:s',
        'schedule_out' => 'datetime:H:i:s',
        'clock_in' => 'datetime:H:i:s',
        'clock_out' => 'datetime:H:i:s',

        'clock_in_latitude' => 'double',
        'clock_in_longitude' => 'double',
        'clock_out_latitude' => 'double',
        'clock_out_longitude' => 'double',

        'late_minutes' => 'integer',
        'early_leave_minutes' => 'integer',
        'overtime_minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function details()
    {
        return $this->hasMany(AttendanceDetail::class, 'attendance_summary_id');
    }
}
