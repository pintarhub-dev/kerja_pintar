<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use PDO;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        // 1. Validasi Input
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'nullable|string',
        ]);

        // 2. Cek Kredensial (Email & Password)
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau Password salah.',
            ], 401);
        }

        // 3. Ambil User & Data Karyawan
        $user = User::where('email', $request->email)->first();
        $user->load(['employee.workLocation', 'employee.attendanceSummaries.shift']);

        // Cek apakah user ini punya data karyawan?
        if (!$user->employee) {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak terhubung dengan data Karyawan.',
            ], 403);
        }

        if ($user->employee->is_attendance_required == 0) {
            $user->tokens()->delete();

            return response()->json([
                'success' => false,
                'message' => 'Mohon maaf, akun Anda disetting tidak memerlukan absensi mobile.',
            ], 403);
        }

        // 4. Generate Token Sanctum
        // Kita beri nama tokennya sesuai device, atau default 'mobile-app'
        $deviceName = $request->device_name ?? 'mobile-app';

        // Hapus token lama jika ingin single-device login (Opsional)
        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login Berhasil',
            'data' => [
                'token' => $token,
                'user' => $this->formatUserProfile($user), // Kita buat private function biar rapi
            ]
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $user->load(['employee.workLocation', 'employee.attendanceSummaries.shift']);

        return response()->json([
            'success' => true,
            'message' => 'Data Profil Berhasil Diambil',
            'data' => [
                'user' => $this->formatUserProfile($user),
            ]
        ]);
    }

    private function formatUserProfile($user)
    {
        $emp = $user->employee;

        // Logic Avatar: Jika null, pakai default placeholder atau null
        $avatarUrl = $user->avatar
            ? Storage::url($user->avatar)
            : 'https://ui-avatars.com/api/?name=' . urlencode($emp->full_name);

        return [
            // A. AKUN (User Table) - Editable: Avatar
            'id'            => $user->id,
            'email'         => $user->email,
            'role'          => $user->role,
            'avatar_url'    => $avatarUrl,

            // B. PERSONAL (Employee Table) - Editable: Phone, Address, Nickname
            'full_name'     => $emp->full_name,
            'nickname'      => $emp->nickname,
            'phone'         => $emp->phone,
            'address'       => $emp->address,
            'gender'        => $emp->gender,
            'birth_place'   => $emp->place_of_birth,
            'birth_date'    => $emp->date_of_birth,

            // C. PEKERJAAN (Employee Table) - Read Only
            'nik'               => $emp->nik,
            'job_title'         => $emp->job_title,
            'department'        => $emp->department,
            'status'            => $emp->employment_status,
            'join_date'         => $emp->join_date,
            'tenure_months'     => $emp->join_date ? \Carbon\Carbon::parse($emp->join_date)->diffInMonths(now()) : 0, // Lama bekerja (bulan)

            // D. KONFIGURASI ABSENSI (Logic System)
            'is_flexible'       => (bool) $emp->is_flexible_location,
            'tenant'            => $user->tenant ? $user->tenant->name : '-',
            'work_location'     => $emp->workLocation ? $emp->workLocation->name : '-',
            'latitude'          => $emp->workLocation ? $emp->workLocation->latitude : null,
            'longitude'         => $emp->workLocation ? $emp->workLocation->longitude : null,
        ];
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $employee = $user->employee;

        // 1. Validasi Input
        $request->validate([
            // Data Employee
            'nickname' => 'nullable|string|max:20',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string|max:500',
            // Data User
            'avatar'   => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        try {
            DB::beginTransaction();

            // 2. Update Data Employee (Personal Info)
            $employee->update([
                'nickname' => $request->nickname ?? $employee->nickname,
                'phone'    => $request->phone ?? $employee->phone,
                'address'  => $request->address ?? $employee->address,
            ]);

            // 3. Update Avatar (Jika ada upload baru)
            if ($request->hasFile('avatar')) {
                // Hapus avatar lama jika ada (dan bukan url eksternal)
                if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                    Storage::disk('public')->delete($user->avatar);
                }
                $path = $request->file('avatar')->store("avatars/{$user->tenant_id}/{$user->id}", 'public');
                $user->update(['avatar' => $path]);
            }

            DB::commit();

            // 4. Return data profile terbaru
            $user->load(['employee.workLocation']);

            return response()->json([
                'success' => true,
                'message' => 'Profil berhasil diperbarui.',
                'data'    => [
                    'user' => $this->formatUserProfile($user)
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal update profil', 'error' => $e->getMessage()], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout Berhasil',
        ]);
    }
}
