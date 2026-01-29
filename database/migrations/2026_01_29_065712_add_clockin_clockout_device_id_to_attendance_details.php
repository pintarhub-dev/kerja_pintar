<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_details', function (Blueprint $table) {
            $table->string('clock_in_device_id')->nullable()->after('clock_in_longitude');
            $table->string('clock_out_device_id')->nullable()->after('clock_out_longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_details', function (Blueprint $table) {
            $table->dropColumn('clock_in_device_id');
            $table->dropColumn('clock_out_device_id');
        });
    }
};
