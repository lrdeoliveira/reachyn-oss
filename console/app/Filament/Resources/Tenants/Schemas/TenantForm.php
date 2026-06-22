<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('plan')
                    ->required()
                    ->default('starter'),
                TextInput::make('zernio_profile_id'),
                TextInput::make('billing_status')
                    ->required()
                    ->default('none'),
                TextInput::make('voice_id'),
            ]);
    }
}
