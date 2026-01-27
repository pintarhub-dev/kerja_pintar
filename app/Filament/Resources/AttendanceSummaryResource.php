<?php

namespace App\Filament\Resources;

use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
use pxlrbt\FilamentExcel\Columns\Column;
use App\Filament\Resources\AttendanceSummaryResource\Pages;
use App\Models\AttendanceSummary;
use App\Models\Employee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Actions\Action;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class AttendanceSummaryResource extends Resource
{
    protected static ?string $model = AttendanceSummary::class;

    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Laporan Harian';
    protected static ?string $navigationGroup = 'Monitoring Absensi';
    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informasi Dasar')
                    ->schema([
                        Forms\Components\TextInput::make('employee_name')
                            ->label('Karyawan')
                            ->formatStateUsing(fn($record) => $record->employee->full_name)
                            ->disabled(),

                        Forms\Components\DatePicker::make('date')
                            ->label('Tanggal')
                            ->disabled(),

                        Forms\Components\Select::make('status')
                            ->options([
                                'present' => 'Hadir',
                                'alpha' => 'Alpha',
                                'late' => 'Telat',
                                'sick' => 'Sakit',
                                'permit' => 'Izin',
                                'leave' => 'Cuti',
                                'off' => 'Libur',
                                'holiday' => 'Holiday',
                            ])
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Data Waktu (Jadwal vs Aktual)')
                    ->schema([
                        // Kolom Kiri: JADWAL
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TimePicker::make('schedule_in')
                                    ->label('Jadwal Masuk')
                                    ->seconds(false)
                                    ->disabled(),
                                Forms\Components\TimePicker::make('schedule_out')
                                    ->label('Jadwal Pulang')
                                    ->seconds(false)
                                    ->disabled(),
                            ])->columnSpan(1),

                        // Kolom Kanan: AKTUAL (Bisa diedit HRD untuk koreksi)
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TimePicker::make('clock_in')
                                    ->label('Aktual Masuk')
                                    ->seconds(false),
                                Forms\Components\TimePicker::make('clock_out')
                                    ->label('Aktual Pulang')
                                    ->seconds(false),
                            ])->columnSpan(1),
                    ])->columns(2),

                Forms\Components\Section::make('Kalkulasi')
                    ->schema([
                        Forms\Components\TextInput::make('late_minutes')
                            ->label('Telat (Menit)')
                            ->numeric(),
                        Forms\Components\TextInput::make('overtime_minutes')
                            ->label('Lembur (Menit)')
                            ->numeric(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            // ->modifyQueryUsing(fn($query) => $query->with(['employee.workLocation']))
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->description(fn($record) => $record->employee->nik),

                // Tanggal & Shift
                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable()
                    ->description(fn($record) => $record->shift->name ?? '-'),

                // Jam Jadwal
                Tables\Columns\TextColumn::make('schedule_in')
                    ->label('Jadwal')
                    ->getStateUsing(
                        fn($record) =>
                        $record->shift && $record->shift->is_day_off
                            ? 'LIBUR'
                            : ($record->schedule_in ? Carbon::parse($record->schedule_in)->format('H:i') . ' - ' . Carbon::parse($record->schedule_out)->format('H:i') : '-')
                    )
                    ->color('gray'),

                // Jam Aktual (Clock In / Out)
                Tables\Columns\TextColumn::make('clock_in')
                    ->label('Absen Masuk')
                    ->time('H:i')
                    ->timezone(function (AttendanceSummary $record) {
                        return $record->employee->workLocation->timezone ?? 'Asia/Jakarta';
                    })
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('clock_out')
                    ->label('Absen Pulang')
                    ->time('H:i')
                    ->timezone(function (AttendanceSummary $record) {
                        return $record->employee->workLocation->timezone ?? 'Asia/Jakarta';
                    })
                    ->placeholder('-'),

                // Status (Badge Keren)
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // LOGIKA LABEL TEXT
                    ->formatStateUsing(function ($record, $state) {
                        // Cek apakah tanggal record > hari ini (Masa Depan)
                        $isFuture = $record->date > now()->format('Y-m-d');

                        // Jika Masa Depan & statusnya Alpha, ganti tulisan jadi 'Terjadwal'
                        if ($isFuture && $state === 'alpha') {
                            return 'Terjadwal';
                        }

                        // Jika Hari Ini & status Alpha (tapi jam pulang belum lewat), ganti jadi 'Belum Hadir'
                        $isToday = $record->date->format('Y-m-d') == now()->format('Y-m-d');
                        if ($isToday && $state === 'alpha') {
                            return 'Belum Hadir';
                        }

                        // Selain itu tampilkan status asli (Hadir, Sakit, Libur, Alpha Kemarin)
                        return match ($state) {
                            'present' => 'Hadir',
                            'alpha'   => 'Alpha / Mangkir',
                            'late'    => 'Telat',
                            'sick'    => 'Sakit',
                            'permit'  => 'Izin',
                            'leave'   => 'Cuti',
                            'off'     => 'Libur',
                            'holiday' => 'Holiday',
                            default   => ucfirst($state),
                        };
                    })
                    // LOGIKA WARNA BADGE
                    ->color(fn($record, $state) => match (true) {
                        // Prioritas 1: Masa Depan (Alpha) -> Abu-abu (Netral)
                        ($record->date > now()->format('Y-m-d') && $state === 'alpha') => 'gray',

                        // Prioritas 2: Hari Ini (Alpha) -> Oranye (Warning/Waiting)
                        ($record->date->format('Y-m-d') == now()->format('Y-m-d') && $state === 'alpha') => 'warning',

                        // Prioritas 3: Status Asli
                        $state === 'present' => 'success',
                        $state === 'alpha'   => 'danger', // Merah cuma kalau sudah lewat hari (Mangkir)
                        $state === 'late'    => 'warning',
                        $state === 'sick'    => 'info',
                        $state === 'permit'  => 'info',
                        $state === 'leave'   => 'info',
                        default => 'gray',
                    }),

                // Telat (Merah jika > 0)
                Tables\Columns\TextColumn::make('late_minutes')
                    ->label('Telat')
                    ->state(fn($record) => $record->late_minutes > 0 ? $record->late_minutes . 'm' : '-')
                    ->color(fn($state) => $state !== '-' ? 'danger' : 'success')
                    ->weight('bold'),
            ])
            ->filters([
                // Filter Tanggal (Penting!)
                Filter::make('date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn(Builder $query, $date) => $query->whereDate('date', '>=', $date))
                            ->when($data['until'], fn(Builder $query, $date) => $query->whereDate('date', '<=', $date));
                    }),

                // Filter Status
                SelectFilter::make('status')
                    ->options([
                        'present' => 'Hadir',
                        'alpha'   => 'Alpha / Mangkir',
                        'late'    => 'Telat',
                        'sick'    => 'Sakit',
                        'permit'  => 'Izin',
                        'leave'   => 'Cuti',
                        'off'     => 'Libur',
                        'holiday' => 'Holiday',
                    ]),

                // Filter Karyawan
                SelectFilter::make('employee_id')
                    ->relationship(
                        name: 'employee',
                        titleAttribute: 'full_name',
                        modifyQueryUsing: function (Builder $query) {
                            $query->whereNotIn('employment_status', ['resigned', 'terminated', 'retired']);
                            $query->whereHas('scheduleAssignments');
                        }
                    )
                    ->getOptionLabelFromRecordUsing(fn(Employee $record) => $record->label)
                    ->label('Karyawan')
                    ->searchable(['nik', 'full_name']),

                // Filter Shift
                SelectFilter::make('shift_id')
                    ->relationship('shift', 'name')
                    ->label('Shift'),
            ])
            ->headerActions([
                ExportAction::make()
                    ->label('Download Laporan Absen (Excel)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exports([
                        ExcelExport::make()
                            ->fromTable()
                            ->withFilename(fn($resource) => 'Laporan_Absen_' . date('Y-m-d'))
                            ->withColumns([
                                Column::make('employee.nik')->heading('NIK'),
                                Column::make('employee.full_name')->heading('Nama Karyawan'),
                                Column::make('durasi_kerja')
                                    ->heading('Durasi Kerja')
                                    ->getStateUsing(function ($record) {
                                        if ($record->clock_in && $record->clock_out) {
                                            $in = Carbon::parse($record->clock_in);
                                            $out = Carbon::parse($record->clock_out);
                                            return $in->diff($out)->format('%H Jam %I Menit');
                                        }
                                        return '-';
                                    }),
                                Column::make('lokasi_masuk')
                                    ->heading('Lokasi Clock In')
                                    ->getStateUsing(fn($record) => $record->details->first()?->workLocation->name ?? '-'),
                            ])
                    ]),
            ])
            ->actions([
                Action::make('history')
                    ->label('Detail Sesi')
                    ->icon('heroicon-m-clock')
                    ->color('info')
                    ->modalHeading('Riwayat Aktivitas Harian')
                    ->modalSubmitAction(false)
                    ->modalCancelAction(fn($action) => $action->label('Tutup'))
                    ->infolist([
                        Section::make()
                            ->schema([
                                RepeatableEntry::make('details')
                                    ->hiddenLabel()
                                    ->schema([
                                        Grid::make(3)
                                            ->schema([
                                                /* ========= MASUK ========= */
                                                Section::make('Masuk')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                TextEntry::make('clock_in_time')
                                                                    ->label('Jam Masuk')
                                                                    ->time('H:i')
                                                                    ->timezone(fn($record) => $record->workLocation->timezone ?? 'Asia/Jakarta')
                                                                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                                                                    ->extraAttributes([
                                                                        'class' => 'pt-2',
                                                                    ]),

                                                                ImageEntry::make('clock_in_image')
                                                                    ->hiddenLabel()
                                                                    ->disk('public')
                                                                    ->visibility('public')
                                                                    ->circular()
                                                                    ->height(90)
                                                                    ->width(90)
                                                                    ->defaultImageUrl(url('/images/placeholder-user.png')),
                                                            ])
                                                            ->extraAttributes([
                                                                'class' => 'items-center gap-x-4',
                                                            ]),
                                                    ])
                                                    ->columnSpan(1),

                                                /* ========= PULANG ========= */
                                                Section::make('Pulang')
                                                    ->schema([
                                                        Grid::make(2)
                                                            ->schema([
                                                                TextEntry::make('clock_out_time')
                                                                    ->label('Jam Pulang')
                                                                    ->time('H:i')
                                                                    ->timezone(fn($record) => $record->workLocation->timezone ?? 'Asia/Jakarta')
                                                                    ->placeholder('—')
                                                                    ->color(fn($state) => $state ? 'success' : 'gray')
                                                                    ->weight(\Filament\Support\Enums\FontWeight::Medium)
                                                                    ->extraAttributes([
                                                                        'class' => 'pt-2',
                                                                    ]),

                                                                ImageEntry::make('clock_out_image')
                                                                    ->hiddenLabel()
                                                                    ->disk('public')
                                                                    ->visibility('public')
                                                                    ->circular()
                                                                    ->height(90)
                                                                    ->width(90)
                                                                    ->defaultImageUrl(url('/images/placeholder-user.png')),
                                                            ])
                                                            ->extraAttributes([
                                                                'class' => 'items-center gap-x-4',
                                                            ]),
                                                    ])
                                                    ->columnSpan(1),

                                                /* ========= META ========= */
                                                Section::make('Info')
                                                    ->schema([
                                                        TextEntry::make('workLocation.name')
                                                            ->label('Lokasi')
                                                            ->icon('heroicon-m-map-pin')
                                                            ->weight(\Filament\Support\Enums\FontWeight::Medium),

                                                        TextEntry::make('clock_in_latitude')
                                                            ->label('Masuk')
                                                            ->formatStateUsing(
                                                                fn($record) =>
                                                                "{$record->clock_in_latitude}, {$record->clock_in_longitude}"
                                                            )
                                                            ->url(
                                                                fn($record) =>
                                                                "https://www.google.com/maps/search/?api=1&query={$record->clock_in_latitude},{$record->clock_in_longitude}"
                                                            )
                                                            ->openUrlInNewTab(),

                                                        TextEntry::make('clock_out_latitude')
                                                            ->label('Pulang')
                                                            ->formatStateUsing(
                                                                fn($record) =>
                                                                "{$record->clock_out_latitude}, {$record->clock_out_longitude}"
                                                            )
                                                            ->url(
                                                                fn($record) =>
                                                                "https://www.google.com/maps/search/?api=1&query={$record->clock_out_latitude},{$record->clock_out_longitude}"
                                                            )
                                                            ->openUrlInNewTab(),
                                                    ])
                                                    ->columnSpan(1)
                                                    ->extraAttributes([
                                                        'class' => 'text-sm',
                                                    ]),
                                            ])
                                            ->extraAttributes([
                                                'class' => 'gap-x-6',
                                            ]),
                                    ])
                                    ->grid(1)
                            ])
                    ]),
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListAttendanceSummaries::route('/'),
            'edit' => Pages\EditAttendanceSummary::route('/{record}/edit'),
        ];
    }
}
