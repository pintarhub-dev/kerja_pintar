<?php

namespace App\Filament\Resources;

use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Columns\Column;
use App\Filament\Resources\OvertimeRequestResource\Pages;
use App\Filament\Resources\OvertimeRequestResource\RelationManagers;
use App\Models\OvertimeRequest;
use App\Models\Employee;
use App\Models\AttendanceSummary;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Closure;

class OvertimeRequestResource extends Resource
{
    protected static ?string $model = OvertimeRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationGroup = 'Manajemen Waktu';
    protected static ?string $navigationLabel = 'Permohonan Lembur';
    protected static ?int $navigationSort = 11;

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
                                    $query->whereNotIn('employment_status', ['resigned', 'terminated', 'retired']);
                                    $query->whereHas('scheduleAssignments');
                                }
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn(Employee $record) => $record->label
                            )
                            ->searchable(['full_name', 'nik'])
                            ->preload()
                            ->required(),

                        Forms\Components\DatePicker::make('date')
                            ->label('Tanggal')
                            ->required()
                            ->live()
                            ->rules([
                                // VALIDASI 1: CEK DUPLIKAT
                                fn(Get $get, ?OvertimeRequest $record) => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                    $employeeId = $get('employee_id');
                                    $date = $value;
                                    if (!$employeeId || !$date) return;

                                    $conflictingRequest = OvertimeRequest::query()
                                        ->where('employee_id', $employeeId)
                                        ->whereNotIn('status', ['rejected', 'cancelled'])
                                        ->where('date', $date);

                                    if ($record) {
                                        $conflictingRequest->where('id', '!=', $record->id);
                                    }

                                    if ($conflictingRequest->exists()) {
                                        $fail('Anda sudah memiliki pengajuan lembur pada tanggal ini.');
                                    }
                                },

                                // VALIDASI 2: CEK KEHADIRAN AKTUAL
                                fn(Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $employeeId = $get('employee_id');
                                    $date = $value;

                                    if (!$employeeId || !$date) return;

                                    // 1. Cari Data Absensi di Summary
                                    $attendance = AttendanceSummary::where('employee_id', $employeeId)
                                        ->where('date', $date)
                                        ->first();

                                    // Syarat A: Data Absen Harus Ada
                                    if (!$attendance) {
                                        $fail('Data absensi tidak ditemukan. Harap lakukan Clock In terlebih dahulu sebelum mengajukan lembur.');
                                        return;
                                    }

                                    // Syarat B: Status Tidak Boleh Cuti/Sakit/Izin/Alpha
                                    $invalidStatuses = ['leave', 'sick', 'permit', 'alpha'];
                                    if (in_array($attendance->status, $invalidStatuses)) {
                                        $fail('Tidak dapat mengajukan lembur saat status Anda sedang Cuti, Sakit, Izin dan Alpha.');
                                        return;
                                    }

                                    // Syarat C: Clock In ATAU Clock Out harus sudah terisi
                                    // (Artinya dia beneran datang ke kantor/lokasi kerja)
                                    if (is_null($attendance->clock_in) && is_null($attendance->clock_out)) {
                                        $fail('Jam kehadiran belum terekam (Clock In/Out kosong).');
                                        return;
                                    }
                                },
                            ]),

                        Forms\Components\TextInput::make('duration_minutes')
                            ->label('Durasi dalam Menit')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->rules([
                                fn(Get $get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $employeeId = $get('employee_id');
                                    $date = $get('date');

                                    // Kalau data dasar belum diisi, skip dulu (biar gak error query)
                                    if (!$employeeId || !$date) return;

                                    // 1. Ambil Data Absen
                                    $summary = AttendanceSummary::with(['employee.workLocation'])
                                        ->where('employee_id', $employeeId)
                                        ->where('date', $date)
                                        ->first();

                                    // Kalau data absen/clock out gak ada, biarkan validasi di field 'date' yang teriak
                                    if (!$summary || !$summary->schedule_out || !$summary->clock_out) {
                                        return;
                                    }

                                    // 2. Logic Waktu (Timezone Aware)
                                    $timezone = $summary->employee->workLocation->timezone ?? 'Asia/Jakarta';

                                    // Bersihkan format string dulu biar Carbon gak bingung (Double date issue)
                                    $dateString = $summary->date instanceof Carbon
                                        ? $summary->date->format('Y-m-d')
                                        : Carbon::parse($summary->date)->format('Y-m-d');

                                    $scheduleTimeString = Carbon::parse($summary->schedule_out)->format('H:i:s');
                                    $actualTimeString   = Carbon::parse($summary->clock_out)->format('H:i:s');

                                    // Parse Jadwal (Lokal)
                                    $scheduleOut = Carbon::parse("{$dateString} {$scheduleTimeString}", $timezone);

                                    // Parse Aktual (UTC -> Lokal)
                                    $actualOut = Carbon::parse("{$dateString} {$actualTimeString}", 'UTC')
                                        ->setTimezone($timezone);

                                    // --- JEBAKAN 1: CEK APAKAH BENAR PULANG TELAT? ---
                                    if ($actualOut->lte($scheduleOut)) {
                                        $fail('Berdasarkan data absensi, Anda pulang tepat waktu atau lebih awal. Tidak bisa mengajukan lembur.');
                                        return;
                                    }

                                    // --- CEK APAKAH DURASI NGELUNJAK? (Opsional) ---
                                    // Hitung selisih menit real
                                    $maxAllowedMinutes = $actualOut->diffInMinutes($scheduleOut);

                                    if ($value > $maxAllowedMinutes) {
                                        $fail("Durasi yang diajukan ({$value} menit) melebihi durasi keterlambatan aktual Anda ({$maxAllowedMinutes} menit).");
                                    }
                                },
                            ]),

                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->columnSpanFull()
                            ->label('Alasan'),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordUrl(
                fn(OvertimeRequest $record): ?string =>
                $record->status === 'pending'
                    ? Pages\EditOvertimeRequest::getUrl([$record->id]) // Kalau pending, boleh ke Edit
                    : null // Kalau sudah approve/reject, matikan klik (gak bisa diklik)
            )
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->description(fn(OvertimeRequest $record) => $record->employee->nik ?? ''),

                Tables\Columns\TextColumn::make('date')
                    ->date('d M Y')
                    ->label('Tanggal'),

                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Durasi')
                    ->suffix(' Menit')
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
                    })
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
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Download Laporan Lembur (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename(fn($resource) => 'Laporan_Lembur_' . date('Y-m-d'))
                            ->withColumns([
                                Column::make('employee.nik')->heading('NIK'),
                                Column::make('employee.full_name')->heading('Nama Karyawan'),
                                Column::make('date')->heading('Tanggal'),
                                Column::make('duration_minutes')->heading('Total Menit'),
                                Column::make('reason')->heading('Alasan'),
                                Column::make('status')->heading('Status Terakhir'),
                                Column::make('approved_at')->heading('Tanggal Disetujui'),
                                Column::make('created_at')->heading('Tanggal Pengajuan'),
                            ])
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                // =========================================================
                // 1. APPROVE SUPERVISOR (Layer 1)
                // =========================================================
                Tables\Actions\Action::make('approve_supervisor')
                    ->label('Approve (SPV)')
                    ->icon('heroicon-m-hand-thumb-up')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(function (OvertimeRequest $record) {
                        $user = auth()->user();
                        // WAJIB Employee (Karyawan)
                        if (!$user || !$user->employee) return false;

                        // Cek: Apakah user ini ATASAN LANGSUNG si pemohon?
                        $isSupervisor = $record->employee->employee_id_supervisor === $user->employee->id;

                        return $record->status === 'pending' && $isSupervisor;
                    })
                    ->action(function (OvertimeRequest $record) {
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
                    ->visible(function (OvertimeRequest $record) {
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
                    ->action(function (OvertimeRequest $record) {
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
                    ->visible(function (OvertimeRequest $record) {
                        $user = auth()->user();
                        // Hanya HR yang boleh, dan status sebelumnya harus valid
                        return $user && $user->employee?->is_hr == 1 &&
                            in_array($record->status, ['pending', 'approved_by_supervisor', 'approved_by_manager']);
                    })
                    ->action(function (OvertimeRequest $record) {
                        DB::transaction(function () use ($record) {
                            // 1. Update Status Surat Lembur jadi Approved
                            $record->update([
                                'status' => 'approved_by_hr',
                                'approved_by' => auth()->user()->id,
                                'approved_at' => now(),
                            ]);

                            // 2. HITUNG ULANG & UPDATE KE SUMMARY ABSENSI
                            // Ambil data absensi pada tanggal lembur tersebut
                            $summary = AttendanceSummary::where('employee_id', $record->employee_id)
                                ->where('date', $record->date)
                                ->first();

                            // Pastikan data summary ada dan Jadwal Pulang + Jam Pulang Aktual tersedia
                            if ($summary && $summary->schedule_out && $summary->clock_out) {

                                // --- A. AMBIL TIMEZONE KARYAWAN ---
                                // Default Asia/Jakarta jika tidak disetting
                                $timezone = $record->employee->workLocation->timezone ?? 'Asia/Jakarta';

                                $dateString = $summary->date instanceof Carbon
                                    ? $summary->date->format('Y-m-d')
                                    : Carbon::parse($summary->date)->format('Y-m-d');

                                $scheduleTimeString = Carbon::parse($summary->schedule_out)->format('H:i:s');
                                $actualTimeString   = Carbon::parse($summary->clock_out)->format('H:i:s');

                                // --- B. PARSE SCHEDULE (Asumsi: Jam Lokal) ---
                                // "17:00" dianggap sebagai jam 17:00 WIB
                                $scheduleOut = Carbon::parse(
                                    "{$dateString} {$scheduleTimeString}",
                                    $timezone
                                );

                                // --- C. PARSE ACTUAL CLOCK OUT (Asumsi: Jam UTC) ---
                                // "10:30" (UTC) -> Kita parse sebagai UTC dulu -> Lalu ubah ke WIB
                                // Hasilnya jadi "17:30" WIB
                                $actualOut = \Carbon\Carbon::parse(
                                    "{$dateString} {$actualTimeString}",
                                    'UTC'
                                )->setTimezone($timezone);

                                // --- LOGIC CROSS-DAY (SHIFT MALAM) ---
                                if ($actualOut->lessThan($scheduleOut->copy()->subHours(12))) {
                                    $actualOut->addDay();
                                }

                                // --- D. HITUNG LEMBUR ---
                                if ($actualOut->greaterThan($scheduleOut)) {

                                    // Hitung selisih dalam menit
                                    $rawOvertimeMinutes = $actualOut->diffInMinutes($scheduleOut);

                                    // Bandingkan dengan Request (Ambil yang terkecil)
                                    $finalOvertime = min($rawOvertimeMinutes, $record->duration_minutes);

                                    // Update Summary
                                    $summary->update([
                                        'overtime_minutes' => $finalOvertime
                                    ]);

                                    Notification::make()
                                        ->title('Permohonan Disetujui')
                                        ->body("Data lembur diperbarui: {$finalOvertime} Menit.")
                                        ->success()
                                        ->send();
                                }
                            } else {
                                Notification::make()
                                    ->title('Permohonan Disetujui')
                                    ->body('Data absensi belum lengkap.')
                                    ->warning()
                                    ->send();
                            }
                        });
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
            'index' => Pages\ListOvertimeRequests::route('/'),
            'create' => Pages\CreateOvertimeRequest::route('/create'),
            'edit' => Pages\EditOvertimeRequest::route('/{record}/edit'),
        ];
    }
}
