<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Marca (Tenant) = CONTEÚDO. Billing (plano/assinatura/créditos) vive na Organização — editar lá.
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('organization_id')
                    ->label('Organização')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('zernio_profile_id'),
                TextInput::make('voice_id'),
                Select::make('content_lang')
                    ->label('Idioma do conteúdo')
                    ->options(['pt-BR' => 'Português', 'en' => 'English'])
                    ->default('pt-BR'),
            ]);
    }
}
