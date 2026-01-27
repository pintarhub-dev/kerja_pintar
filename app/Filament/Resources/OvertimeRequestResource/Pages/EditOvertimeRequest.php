<?php

namespace App\Filament\Resources\OvertimeRequestResource\Pages;

use App\Filament\Resources\OvertimeRequestResource;
use App\Models\OvertimeRequest;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOvertimeRequest extends EditRecord
{
    protected static string $resource = OvertimeRequestResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn(OvertimeRequest $record) => $record->status === 'pending'),
        ];
    }
}
