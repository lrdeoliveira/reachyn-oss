<?php

namespace App\Filament\Resources\GenModels\Schemas;

use App\Models\GenModel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GenModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // --- Público (white-label) ---
                TextInput::make('display_name')
                    ->label('Nome público (white-label)')
                    ->helperText('O que o cliente vê. NÃO citar o provedor real (guideline #6).')
                    ->required(),
                Select::make('kind')
                    ->label('Característica')
                    ->options(array_combine(GenModel::KINDS, ['Vídeo', 'Imagem', 'Áudio', 'Texto']))
                    ->required(),
                TextInput::make('cost_credits')
                    ->label('Custo (créditos)')
                    ->helperText('Créditos cobrados do cliente. Vazio = não precificado (não pode ativar).')
                    ->numeric()
                    ->minValue(0),
                Select::make('min_plan')
                    ->label('Plano mínimo')
                    ->options(['starter' => 'Starter', 'pro' => 'Pro', 'studio' => 'Studio'])
                    ->placeholder('Todos os planos')
                    ->nullable(),

                // --- Disponibilidade ---
                Toggle::make('is_active')->label('Ativo'),
                Toggle::make('is_unstable')->label('Instável (banner)'),
                TextInput::make('unstable_reason')->label('Motivo da instabilidade')->nullable(),
                TextInput::make('sort_order')->label('Ordem')->numeric()->default(0),

                // --- Roteamento interno (referência; vem do seeder) ---
                TextInput::make('provider')
                    ->label('Provedor (interno)')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('provider_model_id')
                    ->label('ID no provedor (interno)')
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('capabilities')
                    ->label('Capacidades (task_types, variantes, upstream — interno)')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($state) => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
