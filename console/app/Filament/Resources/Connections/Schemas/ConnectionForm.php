<?php

namespace App\Filament\Resources\Connections\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->required(),
                TextInput::make('platform')
                    ->required(),
                TextInput::make('label')
                    ->required()
                    ->default(''),
                Textarea::make('secret')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('unchecked'),
                TextInput::make('detail')
                    ->required()
                    ->default(''),
            ]);
    }
}
