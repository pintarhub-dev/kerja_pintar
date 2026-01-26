<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeResource\Pages;
use App\Filament\Resources\EmployeeResource\RelationManagers;
use App\Models\Employee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Validation\Rules\Unique;

class EmployeeResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Manajemen HR';
    protected static ?int $navigationSort = 5;

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
                // --- SECTION 1: AKUN LOGIN (Virtual Fields) ---
                Forms\Components\Section::make('Akun Login App')
                    ->description('User ini akan otomatis dibuatkan akses login.')
                    ->schema([
                        Forms\Components\TextInput::make('email')
                            ->label('Email Login')
                            ->email()
                            ->required()
                            ->rule(function ($record) {
                                // $record adalah model Employee yang sedang diedit
                                // Kita ambil user_id-nya untuk dikecualikan
                                $userId = $record?->user_id;
                                return Rule::unique('users', 'email')->ignore($userId);
                            })
                            ->maxLength(255),

                        Forms\Components\TextInput::make('password')
                            ->password()
                            ->required(fn($livewire) => $livewire instanceof Pages\CreateEmployee) // Wajib saat create saja
                            ->dehydrated(fn($state) => filled($state)) // Hanya kirim jika diisi
                            ->minLength(8),

                        Forms\Components\TextInput::make('registered_device_id')
                            ->label('Device ID Terdaftar')
                            ->helperText('ID Perangkat Keras HP Karyawan. Kosongkan jika ingin mereset/ganti HP.')
                            ->maxLength(255)
                            // Kalau mau reset, mereka tinggal hapus isinya.
                            ->placeholder('Belum ada perangkat terdaftar'),
                    ])->columns(1),

                // --- SECTION 2: DATA PRIBADI ---
                Forms\Components\Section::make('Data Pribadi')
                    ->schema([
                        Forms\Components\TextInput::make('full_name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(150),

                        Forms\Components\TextInput::make('nickname')
                            ->label('Nama Panggilan')
                            ->maxLength(20),

                        Forms\Components\TextInput::make('nik')
                            ->label('NIK Karyawan')
                            ->unique(
                                table: 'employees',
                                column: 'nik',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule) {
                                    return $rule
                                        ->where('tenant_id', auth()->user()->tenant_id)
                                        ->whereNull('deleted_at');
                                }
                            )
                            ->maxLength(20),

                        Forms\Components\TextInput::make('identity_number')
                            ->label('No. KTP / Identity Number')
                            ->unique(
                                table: 'employees',
                                column: 'identity_number',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule) {
                                    return $rule->where('tenant_id', auth()->user()->tenant_id);
                                }
                            )
                            ->maxLength(16)
                            ->required(),

                        Forms\Components\Select::make('gender')
                            ->options([
                                'male' => 'Laki-laki',
                                'female' => 'Perempuan',
                            ])
                            ->required(),

                        Forms\Components\TextInput::make('place_of_birth')
                            ->label('Tempat Lahir')
                            ->required(),

                        Forms\Components\DatePicker::make('date_of_birth')
                            ->label('Tanggal Lahir')
                            ->required(),

                        Forms\Components\TextInput::make('phone')
                            ->label('No. HP / WA')
                            ->tel()
                            ->required()
                            ->maxLength(15),

                        Forms\Components\Textarea::make('address')
                            ->label('Alamat Domisili')
                            ->columnSpanFull()
                            ->required(),
                    ])->columns(2),

                // --- SECTION 4: LOKASI & ABSENSI ---
                Forms\Components\Section::make('Pengaturan Lokasi & Absensi')
                    ->schema([
                        Forms\Components\Toggle::make('is_flexible_location')
                            ->label('Bebas Lokasi (Mobile/Remote)')
                            ->helperText('Jika aktif, karyawan bisa absen dari mana saja (GPS tetap dicatat). Jika mati, wajib dalam radius kantor.')
                            ->default(false)
                            ->onColor('success')
                            ->offColor('danger')
                            ->reactive()
                            ->afterStateUpdated(function (Set $set, $state) {
                                // Jika flexible diaktifkan, reset lokasi kantor
                                if ($state === true) {
                                    $set('work_location_id', null);
                                }
                            }),

                        Forms\Components\Select::make('work_location_id')
                            ->relationship('workLocation', 'name')
                            ->label('Lokasi Kantor Utama')
                            ->searchable()
                            ->preload()
                            ->placeholder('Pilih Kantor Pusat / Cabang')
                            ->nullable()
                            ->required(fn(Get $get) => $get('is_flexible_location') === false)
                            ->visible(fn(Get $get) => $get('is_flexible_location') === false)
                            ->dehydrated(fn(Get $get) => $get('is_flexible_location') === false)
                            ->helperText('Wajib dipilih jika karyawan tidak bebas lokasi'),
                    ])->columns(2),

                // --- SECTION 4: KEPEGAWAIAN ---
                Forms\Components\Section::make('Informasi Kepegawaian')
                    ->schema([
                        Forms\Components\TextInput::make('job_title')
                            ->label('Jabatan')
                            ->required(),

                        Forms\Components\TextInput::make('department')
                            ->label('Departemen')
                            ->required(),

                        Forms\Components\Toggle::make('is_top_level')
                            ->label('Posisi Puncak (Tanpa Atasan)')
                            ->helperText('Aktifkan hanya jika karyawan ini adalah Direktur Utama atau Owner yang tidak memiliki atasan.')
                            ->default(false)
                            ->reactive()
                            ->dehydrated(false)
                            ->afterStateHydrated(function (Forms\Components\Toggle $component, ?Employee $record) {
                                if ($record && is_null($record->employee_id_supervisor)) {
                                    $component->state(true);
                                }
                            }),

                        Forms\Components\Select::make('employee_id_supervisor')
                            ->label('Atasan Langsung')
                            ->relationship(
                                name: 'atasan',
                                titleAttribute: 'full_name',
                                modifyQueryUsing: function (Builder $query) {
                                    $query->whereNotIn('employment_status', ['resigned', 'terminated', 'retired']);
                                }
                            )
                            ->getOptionLabelFromRecordUsing(fn(Employee $record) => $record->label)
                            ->searchable([
                                'full_name',
                                'nik',
                            ])
                            ->nullable()
                            // Wajib diisi, KECUALI jika toggle 'Posisi Puncak' dicentang
                            ->required(fn(Forms\Get $get) => $get('is_top_level') === false)
                            ->placeholder(fn(Forms\Get $get) => $get('is_top_level') ? 'Posisi Puncak (Otomatis Kosong)' : 'Pilih Atasan')
                            ->disabled(fn(Forms\Get $get) => $get('is_top_level') === true) // Disable input kalau dicentang
                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                // Safety net: Kalau toggle mati, tapi user sempat milih null, paksa user milih lagi
                            }),

                        Forms\Components\Select::make('employee_id_manager')
                            ->label('Manager')
                            ->relationship(
                                name: 'manager',
                                titleAttribute: 'full_name',
                                modifyQueryUsing: function (Builder $query) {
                                    $query->whereNotIn('employment_status', ['resigned', 'terminated', 'retired']);
                                }
                            )
                            ->getOptionLabelFromRecordUsing(fn(Employee $record) => $record->label)
                            ->searchable([
                                'full_name',
                                'nik',
                            ]),

                        Forms\Components\DatePicker::make('join_date')
                            ->label('Tanggal Bergabung'),

                        Forms\Components\Select::make('employment_status')
                            ->label('Status Karyawan')
                            ->options([
                                'probation' => 'Probation (Masa Percobaan)',
                                'contract' => 'Kontrak (PKWT)',
                                'permanent' => 'Permanent (PKWTT)',
                                'internship' => 'Internship (Magang)',
                                'freelance' => 'Freelance',
                                'resigned' => 'Resigned (Mengundurkan Diri)',
                                'terminated' => 'Terminated (PHK)',
                                'retired' => 'Retired (Pensiun)',
                            ])
                            ->default('probation')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                // Jika status balik jadi aktif, kosongkan tanggal resign
                                if (in_array($state, ['probation', 'contract', 'permanent', 'internship', 'freelance'])) {
                                    $set('resignation_date', null);
                                    $set('resignation_note', null);
                                }
                            }),
                    ])->columns(2),

                // --- SECTION 5: HAK AKSES & ATURAN ABSENSI (NEW & IMPORTANT) ---
                Forms\Components\Section::make('Hak Akses & Pengaturan Absensi')
                    ->description('Tentukan apakah karyawan ini memiliki akses Admin/HR dan kewajiban absen.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                // IS HR (Penamnda final approval bisa untuk bypass approval spv dan manager)
                                Forms\Components\Toggle::make('is_hr')
                                    ->label('Akses Final Approval (HR)')
                                    ->helperText('Aktifkan untuk fitur final approval HRD.')
                                    ->default(false)
                                    ->onColor('success'),

                                // IS ACCESS WEB (Bisa Login Web Admin)
                                Forms\Components\Toggle::make('is_access_web')
                                    ->label('Akses Login Dashboard (Admin/Approver)')
                                    ->helperText('Aktifkan untuk HRD, Manager, atau Direktur yang butuh akses login ke web untuk melakukan Approval.')
                                    ->default(false)
                                    ->onColor('success'),

                                // WAJIB ABSEN
                                Forms\Components\Toggle::make('is_attendance_required')
                                    ->label('Wajib Melakukan Absensi')
                                    ->helperText('Matikan jika karyawan ini (misal: Boss/HR Senior) TIDAK PERLU melakukan Clock In/Out harian.')
                                    ->default(true) // Default WAJIB
                                    ->offColor('danger')
                                    ->onColor('primary'),
                            ]),
                    ]),

                // --- SECTION 6: PEMBERHENTIAN KARYAWAN ---
                Forms\Components\Section::make('Informasi Pemberhentian')
                    ->schema([
                        Forms\Components\DatePicker::make('resignation_date')
                            ->label('Tanggal Efektif Keluar')
                            ->required() // Wajib diisi jika status resign
                            ->native(false),

                        Forms\Components\Textarea::make('resignation_note')
                            ->label('Alasan / Catatan')
                            ->rows(3),
                    ])
                    // Section ini hanya muncul jika status termasuk dalam array ini:
                    ->visible(fn(Get $get) => in_array($get('employment_status'), ['resigned', 'terminated', 'retired']))
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->description(fn($record) => $record->nik),
                Tables\Columns\TextColumn::make('job_title')
                    ->label('Jabatan')
                    ->searchable()
                    ->description(fn($record) => $record->department),
                Tables\Columns\TextColumn::make('user.email')->label('Email Login')->searchable(), // Relasi ke User

                Tables\Columns\TextColumn::make('join_date')
                    ->label('Tanggal Join')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('employment_status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'retired' => 'danger',
                        'terminated' => 'danger',
                        'resigned' => 'danger',
                        'permanent' => 'success',
                        'contract' => 'warning',
                        'probation' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_access_web')
                    ->label('Akses Web?')
                    ->boolean()
                    ->trueIcon('heroicon-o-computer-desktop')
                    ->falseIcon('heroicon-o-device-tablet')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_hr')
                    ->label('HR?')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_attendance_required')
                    ->label('Wajib Absen')
                    ->boolean()
                    ->trueIcon('heroicon-o-clock')
                    ->falseIcon('heroicon-o-no-symbol') // Simbol dilarang kalau gak wajib
                    ->trueColor('primary')
                    ->falseColor('danger') // Merah artinya "Gak Wajib" (Pengecualian)
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('is_flexible_location')->label('Lokasi Fleksibel?')->sortable()->badge()
                    ->color(fn(string $state): string => match ($state) {
                        '0' => 'danger',
                        '1' => 'success'
                    })->formatStateUsing(fn(string $state): string => match ($state) {
                        '0' => 'NO',
                        '1' => 'YES',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('work_location_id')
                    ->label('Lokasi Kerja')
                    ->sortable()
                    ->getStateUsing(
                        fn($record) =>
                        $record->work_location_id
                            ? \App\Models\WorkLocation::find($record->work_location_id)?->name
                            : 'Not set yet'
                    )
                    ->badge(
                        fn($state) =>
                        $state ? 'Not set yet' : 'Not set yet'
                    )
                    ->colors([
                        'danger' => fn($state) => $state === 'Not set yet',
                        'gray' => fn($state) => $state && $state !== 'Not set yet',
                    ])
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('identity_number')
                    ->label('Nomor KTP')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Nomor HP/WA')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('employment_status')
                    ->options([
                        'permanent' => 'Permanent',
                        'contract' => 'Contract',
                        'probation' => 'Probation',
                        'resigned' => 'Resigned',
                        'terminated' => 'Terminated',
                        'retired' => 'Retired',
                    ]),
                Tables\Filters\TernaryFilter::make('is_access_web')->label('Hanya Akses Web'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                // Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
