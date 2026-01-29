<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveBalanceResource\Pages;
use App\Models\LeaveBalance;
use App\Models\Employee;
use App\Models\LeaveType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class LeaveBalanceResource extends Resource
{
    protected static ?string $model = LeaveBalance::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';
    protected static ?string $navigationGroup = 'Manajemen Waktu';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Saldo Cuti';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Identitas Saldo')
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

                        Forms\Components\Select::make('leave_type_id')
                            ->label('Jenis Cuti')
                            ->relationship('leaveType', 'name')
                            ->required()
                            // Saat jenis cuti dipilih, otomatis isi entitlement default
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $type = LeaveType::find($state);
                                if ($type) {
                                    $set('entitlement', $type->default_quota);
                                }
                            }),

                        Forms\Components\TextInput::make('year')
                            ->label('Tahun Periode')
                            ->numeric()
                            ->default(date('Y'))
                            ->required(),
                    ])->columns(3),

                Section::make('Rincian Kuota')
                    ->description('Angka "Sisa" (Remaining) akan dihitung otomatis oleh database.')
                    ->schema([
                        Forms\Components\TextInput::make('entitlement')
                            ->label('Jatah Tahunan (Entitlement)')
                            ->helperText('Kuota standar yang diberikan.')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        Forms\Components\TextInput::make('carried_over')
                            ->label('Sisa Lalu (Carry Over)')
                            ->helperText('Sisa cuti tahun lalu yang dibawa (jika ada).')
                            ->numeric()
                            ->default(0),

                        Forms\Components\TextInput::make('taken')
                            ->label('Sudah Dipakai (Taken)')
                            ->helperText('Jumlah hari yang sudah disetujui.')
                            ->numeric()
                            ->default(0),

                        // Placeholder visual aja (karena ini Virtual Column, gak bisa diedit)
                        Forms\Components\Placeholder::make('remaining_display')
                            ->label('Sisa Saat Ini')
                            ->content(fn($record) => $record ? $record->remaining : '-')
                            ->extraAttributes(['class' => 'text-xl font-bold text-primary-600']),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.full_name')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->description(fn(LeaveBalance $record) => $record->employee->employee_code ?? ''),

                Tables\Columns\TextColumn::make('leaveType.name')
                    ->label('Jenis Cuti')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('year')
                    ->label('Tahun')
                    ->sortable(),

                // Kolom Perhitungan
                Tables\Columns\TextColumn::make('entitlement')
                    ->label('Jatah')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('carried_over')
                    ->label('Sisa Lalu')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('taken')
                    ->label('Terpakai')
                    ->alignCenter()
                    ->color('danger'),

                // Ini Virtual Column (Paling Penting)
                Tables\Columns\TextColumn::make('remaining')
                    ->label('SISA')
                    ->weight('bold')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Large)
                    ->color(fn($state) => $state < 0 ? 'danger' : 'success') // Merah kalau minus
                    ->alignCenter(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('year')
                    ->options([
                        date('Y') => date('Y'),
                        date('Y') - 1 => date('Y') - 1,
                        date('Y') + 1 => date('Y') + 1,
                    ])->default(date('Y')),
                Tables\Filters\SelectFilter::make('leave_type_id')
                    ->relationship('leaveType', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            // === FITUR MAGIC: GENERATE MASSAL ===
            ->headerActions([
                Tables\Actions\Action::make('generate_balance')
                    ->label('Generate Saldo Massal')
                    ->icon('heroicon-o-sparkles')
                    ->form([
                        Forms\Components\Select::make('year')
                            ->label('Untuk Tahun')
                            ->options([
                                date('Y') => date('Y'),
                                date('Y') + 1 => date('Y') + 1,
                            ])
                            ->default(date('Y'))
                            ->required(),

                        Forms\Components\Select::make('leave_type_id')
                            ->label('Jenis Cuti')
                            ->relationship('leaveType', 'name')
                            ->required()
                            ->helperText('Saldo akan dibuat untuk SEMUA karyawan aktif berdasarkan kuota default jenis cuti ini.'),
                    ])
                    ->action(function (array $data) {
                        $year = $data['year'];
                        $leaveType = LeaveType::find($data['leave_type_id']);

                        if (!$leaveType) return;

                        // Ambil semua karyawan aktif yang sudah di assign schedule
                        $employees = Employee::whereNotIn('employment_status', ['resigned', 'terminated', 'retired'])
                            ->whereHas('scheduleAssignments')
                            ->get();

                        $count = 0;
                        foreach ($employees as $emp) {
                            // Cek apakah sudah ada saldo tahun ini? Biar gak dobel.
                            $exists = LeaveBalance::where('employee_id', $emp->id)
                                ->where('leave_type_id', $leaveType->id)
                                ->where('year', $year)
                                ->exists();

                            if (!$exists) {
                                LeaveBalance::create([
                                    'tenant_id' => $emp->tenant_id,
                                    'employee_id' => $emp->id,
                                    'leave_type_id' => $leaveType->id,
                                    'year' => $year,
                                    'entitlement' => $leaveType->default_quota,
                                    'carried_over' => 0,
                                    'taken' => 0,
                                ]);
                                $count++;
                            }
                        }

                        Notification::make()
                            ->title("Berhasil Generate Saldo")
                            ->body("Saldo cuti {$leaveType->name} berhasil dibuat untuk {$count} karyawan.")
                            ->success()
                            ->send();
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
            'index' => Pages\ListLeaveBalances::route('/'),
            'create' => Pages\CreateLeaveBalance::route('/create'),
            'edit' => Pages\EditLeaveBalance::route('/{record}/edit'),
        ];
    }
}
