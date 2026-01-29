<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Collection;
use Filament\Tables\Actions\DeleteBulkAction;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users'; // Ganti ikon biar lebih relevan
    protected static ?string $navigationGroup = 'Pengaturan Sistem';
    protected static ?int $navigationSort = 13;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user->role === 'superadmin') {
            return $query;
        }
        $query->where('tenant_id', $user->tenant_id);
        if ($user->role === 'tenant_owner') {
            return $query;
        }
        if ($user->role === 'employee' && $user->employee) {
            if ($user->employee->is_access_web) {
                return $query;
            }
        }
        return $query->where('id', $user->id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Akun')
                    ->schema([
                        // Foto Profil
                        Forms\Components\FileUpload::make('avatar')
                            ->avatar()
                            ->image()
                            ->disk('public')
                            ->directory(fn($record) => 'avatars/' . $record->tenant_id . '/' . $record->id)
                            ->columnSpanFull(),

                        // --- TENANT ID (PERUSAHAAN) ---
                        Forms\Components\Select::make('tenant_id')
                            ->label('Perusahaan')
                            ->relationship('tenant', 'name')
                            ->default(fn() => auth()->user()->tenant_id)
                            ->disabled(fn() => auth()->user()?->role !== 'superadmin') // Cuma Superadmin yg bisa ganti
                            ->dehydrated() // Tetap kirim data meski disabled
                            ->preload()
                            ->searchable()
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn() => auth()->user()?->role === 'superadmin'), // Sembunyikan dari owner biar bersih

                        Forms\Components\TextInput::make('full_name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        // Helper Hidden Tenant ID buat Owner (Jaga-jaga logic visible diatas)
                        Forms\Components\Hidden::make('tenant_id')
                            ->default(fn() => auth()->user()->tenant_id)
                            ->visible(fn() => auth()->user()?->role !== 'superadmin'),

                    ])->columns(2),

                Forms\Components\Section::make('Keamanan & Role')
                    ->schema([
                        // --- ROLE SELECTION ---
                        Forms\Components\Select::make('role')
                            ->label('Role Akses')
                            ->options(function () {
                                // Opsi Role Dinamis
                                $roles = [
                                    // 'employee' => 'Employee (Karyawan)',
                                    'tenant_owner' => 'Tenant Owner (Pemilik)',
                                ];

                                // Jika yang login Superadmin, tambah opsi Superadmin
                                if (auth()->user()?->role === 'superadmin') {
                                    $roles['superadmin'] = 'Superadmin';
                                }

                                return $roles;
                            })
                            ->default('tenant_owner')
                            ->required()
                            // Owner tidak boleh bikin Superadmin, tapi boleh bikin Employee atau Owner lain
                            ->native(false),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn($state) => Hash::make($state))
                            ->dehydrated(fn($state) => filled($state))
                            ->required(fn(string $context): bool => $context === 'create')
                            ->label(fn(string $context) => $context === 'edit' ? 'Password Baru (Opsional)' : 'Password'),

                        Forms\Components\Select::make('status')
                            ->options([
                                'active' => 'Active',
                                'inactive' => 'Inactive',
                            ])
                            ->default('active')
                            ->required()
                            ->native(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama')
                    ->description(fn($record) => $record->employee?->nik)
                    ->searchable()
                    ->sortable(),

                // Tampilkan Tenant hanya untuk Superadmin (Owner sudah pasti tahu tenantnya sendiri)
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Perusahaan')
                    ->sortable()
                    ->visible(fn() => auth()->user()?->role === 'superadmin'),

                Tables\Columns\TextColumn::make('email')
                    ->icon('heroicon-m-envelope')
                    ->searchable(),

                // Badge Role (Updated: Hapus Tenant Admin)
                Tables\Columns\TextColumn::make('role')
                    ->badge()
                    ->colors([
                        'danger' => 'superadmin',  // Merah
                        'warning' => 'tenant_owner', // Kuning/Oranye
                        'info' => 'employee',      // Biru
                    ])
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        'tenant_owner' => 'Owner',
                        'employee' => 'Employee',
                        'superadmin' => 'Superadmin',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'success' => 'active',
                        'gray' => 'inactive',
                    ]),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
                // Filter Role Sederhana
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'tenant_owner' => 'Owner',
                        'employee' => 'Employee',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn($record) => $record->role !== 'superadmin')
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $records
                                ->reject(fn($record) => $record->role === 'superadmin')
                                ->each
                                ->delete();
                        })
                        ->requiresConfirmation()
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
