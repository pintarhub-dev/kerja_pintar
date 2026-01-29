<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use App\Models\AttendanceSummary;
use Carbon\Carbon;

class LatestAttendanceWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Aktivitas Absensi Terbaru (Hari Ini)';
    protected static ?string $pollingInterval = '15s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AttendanceSummary::with('employee')
                    ->where('date', Carbon::today()->toDateString())
                    ->whereNotNull('clock_in') // Hanya yang sudah absen
                    ->orderBy('updated_at', 'desc') // Yang baru absen paling atas
                    ->limit(5)
            )
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Nama Karyawan')
                    ->description(fn($record) => $record->employee->nik)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('clock_in')
                    ->label('Jam Masuk')
                    ->time('H:i')
                    ->timezone(function (AttendanceSummary $record) {
                        return $record->employee->workLocation->timezone ?? 'Asia/Jakarta';
                    })
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('late_minutes')
                    ->label('Status')
                    ->formatStateUsing(fn($state) => $state > 0 ? "Telat {$state} Menit" : 'Tepat Waktu')
                    ->badge()
                    ->color(fn($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('clock_out')
                    ->label('Jam Pulang')
                    ->time('H:i')
                    ->timezone(function (AttendanceSummary $record) {
                        return $record->employee->workLocation->timezone ?? 'Asia/Jakarta';
                    })
                    ->placeholder('Belum Pulang'),
            ])
            ->paginated(false); // Hilangkan pagination biar compact
    }
}
