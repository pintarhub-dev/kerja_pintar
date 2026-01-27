<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;
use App\Traits\Blameable;

class OvertimeRequest extends Model
{
    use HasFactory, SoftDeletes;
    use BelongsToTenant, Blameable;
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date'
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    // Siapa yang menyetujui?
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
