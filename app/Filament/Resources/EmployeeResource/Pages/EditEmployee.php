<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = User::find($data['user_id']);
        if ($user) {
            $data['full_name'] = $user->full_name;
            $data['email'] = $user->email;
        }
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Jangan pakai $data['user_id'], karena field itu gak ada di form edit.
        // Pakai $this->getRecord()->user_id (Ambil langsung dari data karyawan yang sedang diedit)
        $employee = $this->getRecord();
        $user = User::find($employee->user_id);

        if ($user) {
            $userUpdate = [
                'full_name' => $data['full_name'],
                'email' => $data['email'],
            ];

            // Update password hanya jika diisi
            if (!empty($data['password'])) {
                $userUpdate['password'] = Hash::make($data['password']);
            }

            $user->update($userUpdate);
        }

        // Hapus data virtual agar tidak error saat Filament update tabel employees
        unset($data['email']);
        unset($data['password']);
        // Pastikan password_confirmation juga dibuang kalau kamu pakai field 'confirmed' di form
        unset($data['password_confirmation']);

        if ($data['is_flexible_location']) {
            $data['work_location_id'] = null;
        }

        return $data;
    }
}
