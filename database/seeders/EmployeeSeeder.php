<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $bambangUser = User::create([
            'tenant_id' => 2,
            'full_name' => 'Bambang',
            'role' => 'employee',
            'email' => 'bambang@gmail.com',
            'password' => Hash::make('12345678'),
            'status' => 'active',
        ]);

        $icaUser = User::create([
            'tenant_id' => 2,
            'full_name' => 'Ica',
            'role' => 'employee',
            'email' => 'ica@gmail.com',
            'password' => Hash::make('12345678'),
            'status' => 'active',
        ]);

        $ciaUser = User::create([
            'tenant_id' => 2,
            'full_name' => 'Cia',
            'role' => 'employee',
            'email' => 'cia@gmail.com',
            'password' => Hash::make('12345678'),
            'status' => 'active',
        ]);

        $abyUser = User::create([
            'tenant_id' => 2,
            'full_name' => 'Aby',
            'role' => 'employee',
            'email' => 'aby@gmail.com',
            'password' => Hash::make('12345678'),
            'status' => 'active',
        ]);

        Employee::create([
            'tenant_id' => 2,
            'user_id' => $bambangUser->id,
            'full_name' => 'Bambang',
            'nik' => '202601',
            'job_title' => 'Direktur',
            'department' => 'BOD',
            'is_access_web' => 1,
            'is_attendance_required' => 0,
            'join_date' => '2026-01-20',
            'place_of_birth' => 'Tangerang',
            'date_of_birth' => '1970-02-18',
            'gender' => 'male',
            'phone' => '081782992091',
            'address' => 'Jl. Bambang',
            'identity_number' => '317404180270004',
        ]);

        Employee::create([
            'tenant_id' => 2,
            'user_id' => $icaUser->id,
            'full_name' => 'Ica',
            'nik' => '202602',
            'job_title' => 'Manager HRD',
            'department' => 'HR',
            'is_access_web' => 1,
            'is_hr' => 1,
            'is_attendance_required' => 0,
            'join_date' => '2026-01-20',
            'place_of_birth' => 'Depok',
            'date_of_birth' => '1990-02-18',
            'gender' => 'female',
            'phone' => '088256255111',
            'address' => 'Jl. Ica',
            'identity_number' => '317404180290004',
            'employee_id_supervisor' => 1,
        ]);

        Employee::create([
            'tenant_id' => 2,
            'user_id' => $ciaUser->id,
            'full_name' => 'Cia',
            'nik' => '202603',
            'job_title' => 'Staff HRD',
            'department' => 'HR',
            'is_access_web' => 1,
            'is_attendance_required' => 0,
            'join_date' => '2026-01-20',
            'place_of_birth' => 'Bandung',
            'date_of_birth' => '1997-02-18',
            'gender' => 'female',
            'phone' => '081282771009',
            'address' => 'Jl. Cia',
            'identity_number' => '317404180297004',
            'employee_id_supervisor' => 2,
            'employee_id_manager' => 1,
        ]);

        Employee::create([
            'tenant_id' => 2,
            'user_id' => $abyUser->id,
            'full_name' => 'Aby',
            'nik' => '202604',
            'job_title' => 'Staff IT Programmer',
            'department' => 'IT',
            'join_date' => '2026-01-20',
            'place_of_birth' => 'Jakarta',
            'date_of_birth' => '1992-02-18',
            'gender' => 'male',
            'phone' => '085692421602',
            'address' => 'Jl. Aby',
            'identity_number' => '317404180292004',
            'employee_id_supervisor' => 3,
            'employee_id_manager' => 2,
        ]);
    }
}
