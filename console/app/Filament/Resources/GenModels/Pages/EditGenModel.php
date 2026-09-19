<?php

namespace App\Filament\Resources\GenModels\Pages;

use App\Filament\Resources\GenModels\GenModelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGenModel extends EditRecord
{
    protected static string $resource = GenModelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
