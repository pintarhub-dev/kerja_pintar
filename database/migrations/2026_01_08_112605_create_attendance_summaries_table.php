<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            // --- SAFETY HISTORY ---
            // Jika Shift dihapus dari Master, data absen JANGAN dihapus.
            // Tapi link-nya diputus (set NULL). Biar audit tetap jalan.
            $table->foreignId('schedule_id')->nullable()->constrained('employee_schedule_assignments')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->time('schedule_in')->nullable();
            $table->time('schedule_out')->nullable();

            // --- BAGIAN 2: REALISASI (Diisi saat Absen) ---
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();

            // Lokasi & Bukti
            $table->string('clock_in_location')->nullable();
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->string('clock_in_device_id')->nullable(); // Opsional: catat ID HP
            $table->string('clock_in_image')->nullable();

            $table->string('clock_out_location')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->string('clock_out_device_id')->nullable(); // Opsional: catat ID HP
            $table->string('clock_out_image')->nullable();

            // --- BAGIAN 3: HASIL KALKULASI (Diisi System) ---
            $table->integer('late_minutes')->default(0);       // Telat (menit)
            $table->integer('early_leave_minutes')->default(0); // Pulang cepat (menit)
            $table->integer('overtime_minutes')->default(0);   // Lembur (menit)

            // --- BAGIAN 4: STATUS FINAL ---
            // 'alpha' adalah default. Jika sampai malam tidak ada clock_in, tetap alpha.
            $table->enum('status', [
                'present',  // Hadir
                'alpha',    // Mangkir
                'late',    // Telat
                'sick',     // Sakit
                'permit',   // Izin
                'leave',    // Cuti
                'off',      // Libur Jadwal
                'holiday'   // Libur Nasional
            ])->default('alpha')->index();

            // Audit
            $table->timestamps(); // created_at = kapan row ini digenerate, updated_at = kapan terakhir absen
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->unique(['tenant_id', 'employee_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_summaries');
    }
};
