<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LeaveTypeResource\Pages;
use App\Models\LeaveType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;

class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationGroup = 'Data Master';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Jenis Cuti';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informasi Dasar')
                    ->description('Tentukan nama dan kode untuk jenis cuti ini.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nama Cuti')
                            ->placeholder('Contoh: Cuti Tahunan')
                            ->required()
                            ->maxLength(255),


                        Forms\Components\TextInput::make('code')
                            ->label('Kode Singkatan')
                            ->placeholder('CT / AL')
                            ->maxLength(10)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase']),

                        Forms\Components\Select::make('category')
                            ->label('Kategori Sistem')
                            ->options([
                                'leave' => 'Cuti Tahunan',
                                'sick' => 'Sakit (Sick)',
                                'permit' => 'Izin (Permit)',
                            ])
                            ->helperText('Tentukan bagaimana sistem mencatat status absensi untuk jenis cuti ini.')
                            ->required()
                            ->default('leave'),

                        Forms\Components\TextInput::make('min_months_of_service')
                            ->label('Syarat Masa Kerja (Bulan)')
                            ->helperText('0 = Bebas. 3 = Harus kerja 3 bulan dulu. 12 = Harus setahun.')
                            ->numeric()
                            ->default(0)
                            ->suffix('Bulan')
                            ->required(),

                        Forms\Components\TextInput::make('default_quota')
                            ->label('Kuota Default (Per Tahun)')
                            ->helperText('Jumlah hari yang otomatis diberikan saat generate saldo awal tahun.')
                            ->numeric()
                            ->default(12)
                            ->required(),
                    ])->columns(3),

                Section::make('Konfigurasi & Aturan')
                    ->description('Pengaturan perilaku untuk jenis cuti ini.')
                    ->schema([
                        Grid::make(2) // Grid 2 kolom biar rapi
                            ->schema([
                                // Kolom Kiri
                                Forms\Components\Toggle::make('is_paid')
                                    ->label('Dibayar Penuh (Paid Leave)')
                                    ->helperText('Jika aktif, gaji tidak dipotong.')
                                    ->default(true)
                                    ->onColor('success'),

                                Forms\Components\Toggle::make('deducts_quota')
                                    ->label('Memotong Saldo (Kuota)')
                                    ->helperText('Jika aktif, saldo karyawan akan berkurang saat request disetujui.')
                                    ->default(true)
                                    ->onColor('danger'), // Merah biar aware ini ngurangin jatah

                                // Kolom Kanan
                                Forms\Components\Toggle::make('requires_file')
                                    ->label('Wajib Upload Bukti')
                                    ->helperText('Contoh: Surat Dokter untuk Sakit.')
                                    ->default(false),

                                // Forms\Components\Toggle::make('is_carry_forward')
                                //     ->label('Bisa Carry Forward (Sisa saldo dibawa ke tahun depan)')
                                //     ->default(false),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Jenis Cuti')
                    ->description(fn($record) => $record->code)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('min_months_of_service')
                    ->label('Berlaku Minimal')
                    ->suffix(' Bulan')
                    // ->alignCenter(),
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('default_quota')
                    ->label('Jatah')
                    ->suffix(' Hari')
                    // ->alignCenter(),
                    ->toggleable(isToggledHiddenByDefault: true),

                // Boolean Columns (Pakai Icon biar cakep)
                Tables\Columns\IconColumn::make('is_paid')
                    ->label('Dibayar?')
                    ->boolean()
                    ->trueIcon('heroicon-o-currency-dollar')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\IconColumn::make('deducts_quota')
                    ->label('Potong Saldo?')
                    ->boolean()
                    ->trueIcon('heroicon-o-scissors') // Ikon gunting
                    ->falseIcon('heroicon-o-minus-circle')
                    ->trueColor('danger') // Merah artinya motong
                    ->falseColor('success'), // Hijau artinya gak motong (Free)

                Tables\Columns\IconColumn::make('requires_file')
                    ->label('Wajib Bukti?')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-x-mark'),

                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori Sistem')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_paid')->label('Paid / Unpaid'),
                Tables\Filters\TernaryFilter::make('deducts_quota')->label('Memotong Saldo'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListLeaveTypes::route('/'),
            'create' => Pages\CreateLeaveType::route('/create'),
            'edit' => Pages\EditLeaveType::route('/{record}/edit'),
        ];
    }
}
