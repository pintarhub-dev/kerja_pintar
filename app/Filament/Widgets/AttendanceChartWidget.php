<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\AttendanceSummary;
use Carbon\Carbon;
use Flowframe\Trend\Trend;
use Flowframe\Trend\TrendValue;

class AttendanceChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Tren Kehadiran 7 Hari Terakhir';
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '15s';

    protected function getData(): array
    {
        // Ambil 7 hari terakhir
        $start = now()->subDays(6);
        $end = now();

        // Query 1: Data Hadir (Total Clock In)
        $dataPresent = Trend::query(AttendanceSummary::whereNotNull('clock_in'))
            ->dateColumn('date')
            ->between(start: $start, end: $end)
            ->perDay()
            ->count();

        // Query 2: Data Terlambat
        $dataLate = Trend::query(AttendanceSummary::where('late_minutes', '>', 0))
            ->dateColumn('date')
            ->between(start: $start, end: $end)
            ->perDay()
            ->count();

        return [
            'datasets' => [
                [
                    'label' => 'Hadir',
                    'data' => $dataPresent->map(fn(TrendValue $value) => $value->aggregate),
                    'backgroundColor' => '#36A2EB',
                    'borderColor' => '#36A2EB',
                ],
                [
                    'label' => 'Terlambat',
                    'data' => $dataLate->map(fn(TrendValue $value) => $value->aggregate),
                    'backgroundColor' => '#FF6384',
                    'borderColor' => '#FF6384',
                ],
            ],
            'labels' => $dataPresent->map(fn(TrendValue $value) => Carbon::parse($value->date)->format('d M')),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
