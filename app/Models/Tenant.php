<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Blameable;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;
    use Blameable;
    protected $guarded = ['id'];

    protected $casts = [
        'subscription_expired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            // Default saat daftar: Plan Free, Expired 1 bulan dari sekarang
            if (empty($tenant->subscription_plan)) {
                $tenant->subscription_plan = 'free';
            }

            if (empty($tenant->subscription_expired_at)) {
                $tenant->subscription_expired_at = now()->addMonth();
            }
        });
    }

    public function getHasActiveSubscriptionAttribute(): bool
    {
        // Jika tanggal expired masih di masa depan, berarti aktif
        // Jika null, kita anggap tidak aktif
        return $this->subscription_expired_at && $this->subscription_expired_at->isFuture();
    }

    public function getNextInvoiceAmountAttribute(): int
    {
        $employeeCount = $this->employees()
            ->whereNotIn('employment_status', ['resigned', 'terminated', 'retired'])
            ->count();

        return $employeeCount * 3000;
    }

    // Relasi ke User (Owner & Admin)
    public function users()
    {
        return $this->hasMany(User::class);
    }

    // Relasi ke Karyawan
    public function employees()
    {
        return $this->hasMany(Employee::class);
    }
}
