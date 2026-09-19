<?php

namespace App\Filament\Resources\Organizations\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                Select::make('plan')
                    ->options([
                        'starter' => 'Starter',
                        'pro' => 'Pro',
                        'studio' => 'Studio',
                        'enterprise' => 'Enterprise', // tier com Veo premium (PLAN_LIMITS) — faltava no form
                        'unlimited' => 'Ilimitado (interno)',
                    ])
                    ->required()
                    ->default('starter'),
                Select::make('billing_status')
                    ->options([
                        'none' => 'Sem assinatura',
                        'active' => 'Ativa',
                        'past_due' => 'Pagamento pendente',
                        'canceled' => 'Cancelada',
                        'exempt' => 'Isenta (interna)',
                    ])
                    ->required()
                    ->default('none'),
                DateTimePicker::make('trial_ends_at'),
            ]);
    }
}
