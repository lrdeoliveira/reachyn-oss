<?php

namespace App\Filament\Resources\Drafts\Pages;

use App\Filament\Resources\Drafts\DraftResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDraft extends CreateRecord
{
    protected static string $resource = DraftResource::class;
}
