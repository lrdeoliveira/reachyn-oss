<?php

namespace App\Filament\Resources\GenModels\Pages;

use App\Filament\Resources\GenModels\GenModelResource;
use Filament\Resources\Pages\ListRecords;

class ListGenModels extends ListRecords
{
    protected static string $resource = GenModelResource::class;

    // Sem CreateAction: o catálogo é populado por seeder por provedor.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
