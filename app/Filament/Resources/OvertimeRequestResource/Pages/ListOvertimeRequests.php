<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Resources\OvertimeRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOvertimeRequests extends ListRecords
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
