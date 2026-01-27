<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Resources\OvertimeRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateOvertimeRequest extends CreateRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
