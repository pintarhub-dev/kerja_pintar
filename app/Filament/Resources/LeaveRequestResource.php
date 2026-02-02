<?php

namespace App\Filament\Resources;

use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Columns\Column;
use App\Filament\Resources\LeaveRequestResource\Pages;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\AttendanceSummary;
use App\Models\EmployeeScheduleAssignment;
use App\Models\ScheduleOverride;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Closure;

use function Laravel\Prompts\search;

class LeaveRequestResource extends Resource
{
    protected static ?string $model = LeaveRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Manajemen Waktu';
    protected static ?string $navigationLabel = 'Permohonan Cuti';
    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detail Pengajuan')
                    ->schema([
                        Forms\Components\Select::make('employee_id')
                            ->label('Karyawan')
                            ->relationship(
                                name: 'employee',
                                titleAttribute: 'full_name',
                                modifyQueryUsing: function (Builder $query) {
                                    $query->whereNotIn('employment_status', ['resigned', 'terminated', 'retired'])
                                        ->whereHas('scheduleAssignments');
                                }
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn(Employee $record) => $record->label
                            )
                            ->searchable(['full_name', 'nik'])
                            ->preload()
                            ->required(),

                        Forms\Components\Select::make('leave_type_id')
                            ->label('Jenis Cuti')
                            ->relationship(
                                name: 'leaveType',
                                titleAttribute: 'full_name',
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn(LeaveType $record) => $record->label
                            )
                            ->reactive() // Biar bisa cek 'requires_file' nanti
                            ->afterStateUpdated(function ($state, Set $set) {
                                // Logic tambahan jika perlu reset attachment
                            })
                            ->rules([
                                fn(Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                    // Ambil Data Karyawan & Tipe Cuti
                                    $employeeId = $get('employee_id');
                                    if (!$employeeId) return;

                                    $employee = Employee::find($employeeId);
                                    $leaveType = LeaveType::find($value);

                                    if (!$employee || !$leaveType) return;

                                    // Cek Tanggal Join
                                    if (!$employee->join_date) {
                                        $fail('Tanggal bergabung karyawan belum diset.');
                                        return;
                                    }

                                    // Hitung Masa Kerja (Bulan)
                                    $monthsWorked = Carbon::parse($employee->join_date)->diffInMonths(now());
                                    $minRequired = $leaveType->min_months_of_service;

                                    // Compare
                                    if ($monthsWorked < $minRequired) {
                                        $fail("Karyawan belum memenuhi syarat masa kerja. Minimal: {$minRequired} bulan. (Saat ini: {$monthsWorked} bulan)");
                                    }
                                },
                            ])
                            ->preload()
                            ->searchable(['code', 'name'])
                            ->required(),

                        Forms\Components\DatePicker::make('start_date')
                            ->label('Mulai Tanggal')
                            ->required()
                            ->live()
                            // Validasi: Start tidak boleh setelah End (jika End sudah diisi)
                            ->maxDate(fn(Get $get) => $get('end_date') ? \Carbon\Carbon::parse($get('end_date')) : null)
                            ->rules([
                                fn(Get $get, ?LeaveRequest $record) => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                    $employeeId = $get('employee_id');
                                    $startDate = $value;
                                    $endDate = $get('end_date');

                                    // Kalau data belum lengkap, skip dulu
                                    if (!$employeeId || !$startDate || !$endDate) return;

                                    // Cek Database
                                    $conflictingRequest = LeaveRequest::query()
                                        ->where('employee_id', $employeeId)
                                        // Abaikan status Rejected & Cancelled (Kalau ditolak, boleh ajukan lagi di tgl yg sama)
                                        ->whereNotIn('status', ['rejected', 'cancelled'])
                                        // Cek Tumbukan Tanggal (Overlap Logic)
                                        ->where(function ($query) use ($startDate, $endDate) {
                                            $query->where('start_date', '<=', $endDate)
                                                ->where('end_date', '>=', $startDate);
                                        });

                                    // Jika sedang Edit, jangan anggap diri sendiri sebagai bentrok
                                    if ($record) {
                                        $conflictingRequest->where('id', '!=', $record->id);
                                    }

                                    if ($conflictingRequest->exists()) {
                                        $fail('Anda sudah memiliki pengajuan cuti pada rentang tanggal ini.');
                                    }
                                },
                            ])
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                self::calculateDuration($get, $set);
                            }),

                        Forms\Components\DatePicker::make('end_date')
                            ->label('Sampai Tanggal')
                            ->required()
                            ->live()
                            // Tanggal di kalender sebelum Start Date gak bisa diklik
                            ->minDate(fn(Get $get) => $get('start_date') ? \Carbon\Carbon::parse($get('start_date')) : null)
                            // Validasi: End harus >= Start
                            ->afterOrEqual('start_date')
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                self::calculateDuration($get, $set);
                            }),

                        Forms\Components\TextInput::make('duration_days')
                            ->label('Total Hari')
                            ->numeric()
                            ->readOnly()
                            ->required()
                            ->minValue(1)
                            ->rules([
                                fn(Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $employeeId = $get('employee_id');
                                    $leaveTypeId = $get('leave_type_id');
                                    $startDate = $get('start_date');

                                    // Kalau data belum lengkap, skip dulu validasinya
                                    if (!$employeeId || !$leaveTypeId || !$startDate) return;

                                    // Cek Jenis Cuti: Apakah memotong saldo?
                                    $leaveType = LeaveType::find($leaveTypeId);
                                    if (!$leaveType || !$leaveType->deducts_quota) {
                                        return; // Kalau tipe cuti "Sakit/Izin" (gak potong saldo), loloskan.
                                    }

                                    // Tentukan Tahun Saldo (Berdasarkan Tanggal Mulai Cuti)
                                    // Misal request utk Januari 2026, berarti cari saldo 2026.
                                    $year = \Carbon\Carbon::parse($startDate)->year;

                                    // Cari Saldo di Database
                                    $balance = LeaveBalance::where('employee_id', $employeeId)
                                        ->where('leave_type_id', $leaveTypeId)
                                        ->where('year', $year)
                                        ->first();

                                    // Saldo Belum Dibuat sama sekali
                                    if (!$balance) {
                                        $fail("Saldo cuti karyawan ini untuk tahun {$year} belum dibuat. Hubungi HRD untuk generate saldo.");
                                        return;
                                    }

                                    // Saldo Ada, tapi Kurang
                                    // $value adalah isi field duration_days
                                    if ($balance->remaining < $value) {
                                        $fail("Sisa saldo tidak mencukupi. Sisa: {$balance->remaining} hari. Diminta: {$value} hari.");
                                    }
                                },
                            ]),
                    ])->columns(2),

                Forms\Components\Section::make('Alasan & Bukti')
                    ->schema([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->columnSpanFull()
                            ->label('Alasan'),

                        Forms\Components\FileUpload::make('attachment')
                            ->label('Lampiran (Surat Dokter/Undangan)')
                            ->directory(function (Get $get) {
                                $employeeId = $get('employee_id');

                                if (!$employeeId) {
                                    return 'leave_attachments/temp';
                                }

                                $employee = Employee::find($employeeId);

                                return 'leave_attachments/' .
                                    $employee->tenant_id . '/' .
                                    date('Y-m');
                            })
                            // Validasi: Wajib jika Tipe Cuti mengharuskan
                            ->required(
                                fn(Get $get) =>
                                LeaveType::find($get('leave_type_id'))?->requires_file ?? false
                            ),
                    ]),
            ]);
    }

    public static function calculateDuration(Get $get, Set $set)
    {
        $start = $get('start_date');
        $end = $get('end_date');

        if ($start && $end) {
            $startDate = Carbon::parse($start);
            $endDate = Carbon::parse($end);

            if ($endDate->lt($startDate)) {
                $set('duration_days', 0); // Validasi tgl kebalik
                return;
            }

            // Hitung selisih hari (+1 karena inclusive)
            // Catatan: Ini belum skip Sabtu/Minggu.
            // Kalau mau skip weekend, logic-nya harus diperbaiki disini.
            $diff = $startDate->diffInDays($endDate) + 1;
            $set('duration_days', $diff);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                LeaveRequest::query()
                    ->with('employee.leaveBalances') // EAGER LOAD
            )
            ->recordUrl(
                fn(LeaveRequest $record): ?string =>
                $record->status === 'pending'
                    ? Pages\EditLeaveRequest::getUrl([$record->id]) // Kalau pending, boleh ke Edit
                    : null // Kalau sudah approve/reject, matikan klik (gak bisa diklik)
            )
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->description(fn(LeaveRequest $record) => $record->employee->nik ?? ''),

                Tables\Columns\TextColumn::make('leaveType.name')
                    ->label('Tipe')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('start_date')
                    ->date('d M Y')
                    ->label('Mulai'),

                Tables\Columns\TextColumn::make('end_date')
                    ->date('d M Y')
                    ->label('Sampai'),

                Tables\Columns\TextColumn::make('duration_days')
                    ->label('Durasi')
                    ->suffix(' Hari')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'gray',
                        'approved_by_supervisor' => 'info',
                        'approved_by_manager' => 'primary',
                        'approved_by_hr' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved_by_supervisor' => 'Approved By Supervisor',
                        'approved_by_manager' => 'Approved By Manager',
                        'approved_by_hr' => 'Approved By HR',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('leave_type_id')
                    ->relationship('leaveType', 'name'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Download Laporan Cuti (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename(fn($resource) => 'Laporan_Cuti_' . date('Y-m-d'))
                            ->withColumns([
                                Column::make('employee.nik')->heading('NIK'),
                                Column::make('employee.full_name')->heading('Nama Karyawan'),
                                Column::make('leaveType.name')->heading('Jenis Cuti'),
                                Column::make('start_date')->heading('Mulai Tanggal'),
                                Column::make('end_date')->heading('Sampai Tanggal'),
                                Column::make('duration_days')->heading('Total Hari'),
                                Column::make('reason')->heading('Alasan'),
                                Column::make('status')->heading('Status Terakhir'),
                                Column::make('saldo_terakhir')
                                    ->heading('Sisa Saldo Saat Ini')
                                    ->getStateUsing(function ($record) {
                                        $year = Carbon::parse($record->start_date)->year;

                                        return $record->employee?->leaveBalances
                                            ->where('leave_type_id', $record->leave_type_id)
                                            ->where('year', $year)
                                            ->first()
                                            ?->remaining ?? 0;
                                    }),
                                Column::make('approved_at')->heading('Tanggal Disetujui'),
                                Column::make('created_at')->heading('Tanggal Pengajuan'),
                            ])
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make()
                    ->visible(fn(LeaveRequest $record) => $record->status === 'pending'),

                // =========================================================
                // 1. APPROVE SUPERVISOR (Layer 1)
                // =========================================================
                Tables\Actions\Action::make('approve_supervisor')
                    ->label('Approve (SPV)')
                    ->icon('heroicon-m-hand-thumb-up')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(function (LeaveRequest $record) {
                        $user = auth()->user();
                        // WAJIB Employee (Karyawan)
                        if (!$user || !$user->employee) return false;

                        // Cek: Apakah user ini ATASAN LANGSUNG si pemohon?
                        $isSupervisor = $record->employee->employee_id_supervisor === $user->employee->id;

                        return $record->status === 'pending' && $isSupervisor;
                    })
                    ->action(function (LeaveRequest $record) {
                        $record->update([
                            'status' => 'approved_by_supervisor',
                            'approved_by' => auth()->user()->id,
                            'approved_at' => now(),
                        ]);
                        Notification::make()->title('Permohonan Disetujui Supervisor')->success()->send();
                    }),

                // =========================================================
                // 2. APPROVE MANAGER (Layer 2)
                // =========================================================
                Tables\Actions\Action::make('approve_manager')
                    ->label('Approve (Manager)')
                    ->icon('heroicon-m-check')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(function (LeaveRequest $record) {
                        $user = auth()->user();
                        // WAJIB Employee (Manager juga Karyawan)
                        if (!$user || !$user->employee) return false;

                        $isManager = $record->employee->employee_id_manager === $user->employee->id;

                        // Muncul jika sudah di-acc SPV, ATAU bypass SPV jika gak punya SPV
                        $hasNoSpv = is_null($record->employee->employee_id_supervisor);

                        if ($record->status === 'approved_by_supervisor') return $isManager;
                        if ($record->status === 'pending' && $hasNoSpv) return $isManager;

                        return false;
                    })
                    ->action(function (LeaveRequest $record) {
                        $record->update([
                            'status' => 'approved_by_manager',
                            'approved_by' => auth()->user()->id,
                            'approved_at' => now(),
                        ]);
                        Notification::make()->title('Permohonan Disetujui Manager')->success()->send();
                    }),

                // =========================================================
                // 3. APPROVE FINAL (HR / OWNER) - JALUR BYPASS
                // =========================================================
                Tables\Actions\Action::make('approve_hr')
                    ->label('Approve (Final)')
                    ->icon('heroicon-m-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(function (LeaveRequest $record) {
                        $user = auth()->user();
                        if (!$user) return false;

                        // HR boleh bypass spv dan manager
                        if ($user->employee?->is_hr == 1) {
                            return in_array($record->status, [
                                'pending',
                                'approved_by_supervisor',
                                'approved_by_manager',
                            ]);
                        }
                        return false;
                    })
                    ->action(function (LeaveRequest $record) {
                        DB::transaction(function () use ($record) {
                            $leaveType = $record->leaveType;
                            // AMBIL STATUS LANGSUNG DARI KATEGORI                            // Gak perlu lagi str_contains, langsung ambil value kolom 'category'
                            $attendanceStatus = $record->leaveType->category;
                            // Validasi Fail-safe (Jaga-jaga kalau null, default ke 'leave')
                            if (!$attendanceStatus) {
                                $attendanceStatus = 'leave';
                            }

                            // Potong Saldo
                            if ($leaveType->deducts_quota) {
                                $year = Carbon::parse($record->start_date)->year;
                                $balance = LeaveBalance::where('employee_id', $record->employee_id)
                                    ->where('leave_type_id', $record->leave_type_id)
                                    ->where('year', $year)
                                    ->lockForUpdate()
                                    ->first();

                                if (!$balance || $balance->remaining < $record->duration_days) {
                                    Notification::make()->title('Gagal')->body('Saldo tidak cukup.')->danger()->send();
                                    throw new \Exception('Saldo Kurang');
                                }
                                $balance->increment('taken', $record->duration_days);
                            }

                            // Update Status Request
                            $record->update([
                                'status' => 'approved_by_hr',
                                'approved_by' => auth()->user()->id,
                                'approved_at' => now(),
                            ]);

                            // --- 4. Generate Absen ---
                            $startDate = Carbon::parse($record->start_date);
                            $endDate = Carbon::parse($record->end_date);

                            while ($startDate->lte($endDate)) {
                                $currentDate = $startDate->toDateString();

                                $shiftId = null;
                                $patternId = null;
                                $scheduleIn = null;
                                $scheduleOut = null;

                                // 1. Cek Override (Prioritas)
                                $override = ScheduleOverride::where('employee_id', $record->employee_id)
                                    ->where('date', $currentDate)
                                    ->first();

                                if ($override) {
                                    if ($override->shift) {
                                        $shiftId = $override->shift_id;
                                        $scheduleIn = $override->shift->start_time;
                                        $scheduleOut = $override->shift->end_time;
                                    }
                                } else {
                                    // 2. Cek Pattern Reguler
                                    $assignment = EmployeeScheduleAssignment::where('employee_id', $record->employee_id)
                                        ->whereDate('effective_date', '<=', $currentDate)
                                        ->orderBy('effective_date', 'desc')
                                        ->first();

                                    if ($assignment) {
                                        $patternId = $assignment->schedule_pattern_id;
                                        // Asumsi Model Assignment punya method getShiftOnDate
                                        $shift = $assignment->getShiftOnDate($currentDate);

                                        if ($shift) {
                                            $shiftId = $shift->id;
                                            if (!$shift->is_flexible) {
                                                $scheduleIn = $shift->start_time;
                                                $scheduleOut = $shift->end_time;
                                            }
                                        }
                                    }
                                }
                                AttendanceSummary::updateOrCreate(
                                    [
                                        'tenant_id' => $record->tenant_id,
                                        'employee_id' => $record->employee_id,
                                        'date' => $startDate->toDateString(),
                                    ],
                                    [
                                        'status' => $attendanceStatus,
                                        'schedule_id' => $patternId,
                                        'shift_id' => $shiftId,
                                        'schedule_in' => $scheduleIn,
                                        'schedule_out' => $scheduleOut,
                                        'late_minutes' => 0,
                                        'early_leave_minutes' => 0,
                                        'overtime_minutes' => 0,
                                    ]
                                );
                                $startDate->addDay();
                            }
                        });

                        Notification::make()->title('Permohonan Disetujui Final')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->form([Forms\Components\Textarea::make('rejection_reason')->required()])
                    ->visible(function (LeaveRequest $record) {
                        $user = auth()->user();
                        if (!$user) return false;

                        // Jangan tampilkan kalau sudah final
                        if (in_array($record->status, ['rejected', 'cancelled', 'approved_by_hr'])) {
                            return false;
                        }

                        $employee = $user->employee;
                        $requestEmployee = $record->employee;

                        // HR
                        if ($employee?->is_hr == 1) {
                            return true;
                        }

                        // Supervisor
                        if ($requestEmployee?->employee_id_supervisor === $employee?->id) {
                            return true;
                        }

                        // Manager
                        if ($requestEmployee?->employee_id_manager === $employee?->id) {
                            return true;
                        }

                        return false;
                    })
                    ->action(function (LeaveRequest $record, array $data) {
                        $record->update([
                            'status' => 'rejected',
                            'rejection_reason' => $data['rejection_reason'],
                            'approved_by' => auth()->user()->id,
                            'approved_at' => now(),
                        ]);
                        Notification::make()->title('Permohonan Ditolak')->danger()->send();
                    }),
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
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'edit' => Pages\EditLeaveRequest::route('/{record}/edit'),
        ];
    }
}
