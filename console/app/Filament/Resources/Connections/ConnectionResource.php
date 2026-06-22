<?php

namespace App\Filament\Resources\Connections;

use App\Filament\Resources\Connections\Pages\CreateConnection;
use App\Filament\Resources\Connections\Pages\EditConnection;
use App\Filament\Resources\Connections\Pages\ListConnections;
use App\Filament\Resources\Connections\Schemas\ConnectionForm;
use App\Filament\Resources\Connections\Tables\ConnectionsTable;
use App\Models\Connection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ConnectionResource extends Resource
{
    protected static ?string $model = Connection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConnectionsTable::configure($table);
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
            'index' => ListConnections::route('/'),
            'create' => CreateConnection::route('/create'),
            'edit' => EditConnection::route('/{record}/edit'),
        ];
    }
}
