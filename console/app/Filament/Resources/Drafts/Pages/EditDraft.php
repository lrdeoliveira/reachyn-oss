<?php

namespace App\Filament\Resources\Drafts\Pages;

use App\Filament\Resources\Drafts\DraftResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDraft extends EditRecord
{
    protected static string $resource = DraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
