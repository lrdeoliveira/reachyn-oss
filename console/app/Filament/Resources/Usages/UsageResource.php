<?php

namespace App\Filament\Resources\Usages;

use App\Filament\Resources\Usages\Pages\CreateUsage;
use App\Filament\Resources\Usages\Pages\EditUsage;
use App\Filament\Resources\Usages\Pages\ListUsages;
use App\Filament\Resources\Usages\Schemas\UsageForm;
use App\Filament\Resources\Usages\Tables\UsagesTable;
use App\Models\Usage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UsageResource extends Resource
{
    protected static ?string $model = Usage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return UsageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsagesTable::configure($table);
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
            'index' => ListUsages::route('/'),
            'create' => CreateUsage::route('/create'),
            'edit' => EditUsage::route('/{record}/edit'),
        ];
    }
}
