<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Usuários CADASTRADOS da organização (aba no admin /admin → Organizações → editar).
 * Visão de controle: quem se cadastrou, papel e verificação — o plano/créditos são da ORG
 * (form + ação "Ajustar créditos"). Sem criar/excluir usuário por aqui (o cadastro é
 * self-service; exclusão de conta segue o fluxo LGPD) — só EDITAR nome/papel.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuários';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                Select::make('role')
                    ->label('Papel')
                    ->options([
                        'member' => 'Membro',
                        'operator' => 'Operador (admin RedFoxCode)',
                        'admin' => 'Admin (RedFoxCode)',
                    ])
                    ->default('member')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Papel')
                    ->badge()
                    ->color(fn (?string $state): string => in_array($state, ['operator', 'admin'], true) ? 'warning' : 'gray'),
                TextColumn::make('email_verified_at')
                    ->label('Verificado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('não verificado')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
