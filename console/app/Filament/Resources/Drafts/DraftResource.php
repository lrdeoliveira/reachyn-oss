<?php

namespace App\Filament\Resources\Drafts;

use App\Filament\Resources\Drafts\Pages\CreateDraft;
use App\Filament\Resources\Drafts\Pages\EditDraft;
use App\Filament\Resources\Drafts\Pages\ListDrafts;
use App\Filament\Resources\Drafts\Schemas\DraftForm;
use App\Filament\Resources\Drafts\Tables\DraftsTable;
use App\Models\Draft;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DraftResource extends Resource
{
    protected static ?string $model = Draft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return DraftForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DraftsTable::configure($table);
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
            'index' => ListDrafts::route('/'),
            'create' => CreateDraft::route('/create'),
            'edit' => EditDraft::route('/{record}/edit'),
        ];
    }
}
