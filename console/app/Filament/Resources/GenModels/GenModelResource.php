<?php

namespace App\Filament\Resources\GenModels;

use App\Filament\Resources\GenModels\Pages\EditGenModel;
use App\Filament\Resources\GenModels\Pages\ListGenModels;
use App\Filament\Resources\GenModels\Schemas\GenModelForm;
use App\Filament\Resources\GenModels\Tables\GenModelsTable;
use App\Models\GenModel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Catálogo de modelos de geração (operador). Organizado por `kind`
 * (video/image/audio/text). O operador define preço em créditos + nome white-label
 * e ativa/desativa SEM deploy. Não criar manualmente: o catálogo vem dos seeders
 * por provedor (ex: KieModelsSeeder).
 */
class GenModelResource extends Resource
{
    protected static ?string $model = GenModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Modelos de geração';

    protected static ?string $modelLabel = 'modelo';

    protected static ?string $pluralModelLabel = 'modelos de geração';

    public static function form(Schema $schema): Schema
    {
        return GenModelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GenModelsTable::configure($table);
    }

    public static function getPages(): array
    {
        // Sem create: o catálogo é populado por seeder por provedor.
        return [
            'index' => ListGenModels::route('/'),
            'edit' => EditGenModel::route('/{record}/edit'),
        ];
    }
}
