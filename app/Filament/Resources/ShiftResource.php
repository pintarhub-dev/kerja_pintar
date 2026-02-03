<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use App\Models\Shift;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Data Master';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Placeholder::make('locked_warning')
                    ->label('PERHATIAN')
                    ->content('Shift ini sedang digunakan oleh karyawan atau memiliki riwayat absensi. Data tidak dapat diubah untuk menjaga konsistensi laporan.')
                    ->visible(fn($record) => $record && $record->isLocked()) // Hanya muncul jika terkunci
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'bg-red-50 text-red-600 p-4 rounded-lg font-bold']),

                Forms\Components\Section::make('Identitas Shift')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Shift')
                            ->placeholder('Contoh: Shift Pagi, OFF Sabtu, Shift Malam')
                            ->required()
                            ->disabled(fn($record) => $record?->isLocked())
                            ->dehydrated(),

                        // 1. TOGGLE LIBUR (Master Switch)
                        Forms\Components\Toggle::make('is_day_off')
                            ->label('Set sebagai Shift Libur (OFF)?')
                            ->helperText('Aktifkan ini untuk membuat jadwal Libur.')
                            ->default(false)
                            ->live() // <--- Live agar field lain bereaksi
                            ->afterStateUpdated(function (Set $set, $state) {
                                // Jika diset Libur, otomatis matikan Flexible & Kosongkan jam
                                if ($state) {
                                    $set('is_flexible', false);
                                    $set('start_time', null);
                                    $set('end_time', null);
                                    $set('daily_target_minutes', null);
                                }
                            })
                            ->disabled(fn($record) => $record?->isLocked())
                            ->dehydrated(),
                    ]),

                Forms\Components\Section::make('Konfigurasi Waktu')
                    // Sembunyikan Section ini kalau Shift Libur
                    ->hidden(fn(Get $get) => $get('is_day_off'))
                    ->schema([
                        // 2. TOGGLE FLEXIBLE
                        Forms\Components\Toggle::make('is_flexible')
                            ->label('Shift Flexible?')
                            ->helperText('Jika aktif, jam masuk/pulang diabaikan, yang penting durasi kerja.')
                            ->default(false)
                            ->live()
                            ->disabled(fn($record) => $record?->isLocked())
                            ->dehydrated(),

                        // 3. INPUT JAM (FIXED)
                        // Muncul jika: BUKAN Libur DAN BUKAN Flexible
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TimePicker::make('start_time')
                                    ->label('Jam Masuk')
                                    ->seconds(false)
                                    ->required() // Wajib jika muncul
                                    ->disabled(fn($record) => $record?->isLocked())
                                    ->dehydrated(),

                                Forms\Components\TimePicker::make('end_time')
                                    ->label('Jam Pulang')
                                    ->seconds(false)
                                    ->required()
                                    ->disabled(fn($record) => $record?->isLocked())
                                    ->dehydrated(),
                            ])
                            ->hidden(fn(Get $get) => $get('is_flexible')), // Sembunyi jika flexible

                        // 4. INPUT DURASI (FLEXIBLE)
                        // Muncul jika: BUKAN Libur TAPI Flexible
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('daily_target_minutes')
                                    ->label('Target Durasi (Menit)')
                                    ->numeric()
                                    ->default(480)
                                    ->helperText('480 menit = 8 Jam')
                                    ->required()
                                    ->disabled(fn($record) => $record?->isLocked())
                                    ->dehydrated(),
                            ])
                            ->visible(fn(Get $get) => $get('is_flexible')), // Muncul hanya jika flexible

                        Forms\Components\TextInput::make('break_duration_minutes')
                            ->label('Waktu istirahat (Menit)')
                            ->numeric()
                            ->default(60)
                            ->disabled(fn($record) => $record?->isLocked())
                            ->dehydrated(),

                        Forms\Components\TextInput::make('late_tolerance_minutes')
                            ->label('Toleransi Terlambat (Menit)')
                            ->numeric()
                            ->default(0)
                            ->disabled(fn($record) => $record?->isLocked())
                            ->dehydrated(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // Kolom Status (Badge)
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe Shift')
                    ->sortable()
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if ($record->is_day_off) return 'Libur / OFF';
                        if ($record->is_flexible) return 'Flexible';
                        return 'Fixed Time';
                    })
                    ->color(fn(string $state): string => match ($state) {
                        'Libur / OFF' => 'danger',   // Merah
                        'Flexible' => 'warning',     // Kuning
                        'Fixed Time' => 'success',   // Hijau
                        default => 'gray',
                    }),

                // Kolom Jam Kerja (Smart Display)
                Tables\Columns\TextColumn::make('working_hours')
                    ->label('Jam Kerja')
                    ->getStateUsing(function ($record) {
                        if ($record->is_day_off) return '-';
                        if ($record->is_flexible) return $record->daily_target_minutes . ' Menit';
                        return "{$record->start_time} - {$record->end_time}";
                    }),

                Tables\Columns\TextColumn::make('late_tolerance_minutes')
                    ->label('Toleransi Terlambat')
                    ->getStateUsing(function ($record) {
                        return $record->late_tolerance_minutes . ' Menit';
                    }),

                Tables\Columns\TextColumn::make('break_duration_minutes')
                    ->label('Waktu Istirahat')
                    ->sortable()
                    ->getStateUsing(function ($record) {
                        return $record->break_duration_minutes . ' Menit';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_by')
                    ->sortable()
                    ->getStateUsing(
                        fn($record) =>
                        $record->created_by
                            ? \App\Models\User::find($record->created_by)?->full_name
                            : 'System Generate'
                    )
                    ->color(fn(string $state): string => match ($state) {
                        'System Generate' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Filter biar gampang cari yang libur
                Tables\Filters\Filter::make('is_day_off')
                    ->label('Hanya Shift Libur')
                    ->query(fn($query) => $query->where('is_day_off', true)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->icon(fn($record) => $record->isLocked() ? 'heroicon-o-eye' : 'heroicon-m-pencil-square')
                    ->label(fn($record) => $record->isLocked() ? 'Detail' : 'Edit'),
                Tables\Actions\DeleteAction::make()
                    ->disabled(fn($record) => $record->isLocked())
                    ->tooltip(fn($record) => $record->isLocked() ? 'Shift sedang digunakan, tidak bisa dihapus.' : null),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $records->each(function ($record) {
                                if (!$record->isLocked()) {
                                    $record->delete();
                                }
                            });

                            Notification::make()
                                ->title('Proses Selesai')
                                ->body('Hanya shift yang tidak terpakai yang dihapus.')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
            'create' => Pages\CreateShift::route('/create'),
            'edit' => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
