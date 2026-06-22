<?php

namespace App\Filament\Resources\Drafts\Pages;

use App\Filament\Resources\Drafts\DraftResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDrafts extends ListRecords
{
    protected static string $resource = DraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
