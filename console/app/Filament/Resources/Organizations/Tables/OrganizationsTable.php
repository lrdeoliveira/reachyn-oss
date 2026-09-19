<?php

namespace App\Filament\Resources\Organizations\Tables;

use App\Exceptions\InsufficientCreditsException;
use App\Models\Organization;
use App\Services\CreditWallet;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan')
                    ->badge()
                    ->searchable(),
                TextColumn::make('credit_balance')
                    ->label('Créditos')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success'),
                // Consumo dos últimos 30 dias: débitos REAIS (delta<0) + o custo-que-seria das orgs
                // EXEMPT (lançamentos delta 0 com meta.would_be — monitoramento sem cobrança),
                // líquido de estornos. É o "gasto de créditos" pedido pra monitorar as contas.
                TextColumn::make('consumo_30d')
                    ->label('Consumo 30d')
                    ->badge()
                    ->color('warning')
                    ->state(function (Organization $record): string {
                        $r = DB::selectOne(
                            "SELECT
                               COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END), 0)
                             - COALESCE(SUM(CASE WHEN delta > 0 AND type = 'refund_generation' THEN delta ELSE 0 END), 0) AS real_gasto,
                               COALESCE(SUM(CASE WHEN delta = 0 AND (meta->>'would_be') IS NOT NULL THEN -((meta->>'would_be')::int) ELSE 0 END), 0) AS isento_gasto
                             FROM credit_transactions
                             WHERE organization_id = ? AND created_at >= now() - interval '30 days'",
                            [$record->getKey()],
                        );
                        $real = max(0, (int) ($r->real_gasto ?? 0));
                        $isento = max(0, (int) ($r->isento_gasto ?? 0));
                        if ($isento > 0 && $real === 0) {
                            return $isento.' (isento)';
                        }

                        return $isento > 0 ? $real.' + '.$isento.' isento' : (string) $real;
                    }),
                TextColumn::make('tenants_count')
                    ->label('Marcas')
                    ->counts('tenants')
                    ->badge(),
                TextColumn::make('billing_status')
                    ->badge()
                    ->searchable(),
                TextColumn::make('trial_ends_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                // Ajuste manual de créditos (cortesia/correção). Sempre logado no ledger (auditoria).
                Action::make('ajustarCreditos')
                    ->label('Ajustar créditos')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->schema([
                        TextInput::make('delta')
                            ->label('Variação')
                            ->helperText('Positivo adiciona, negativo remove. Ex: 5000 ou -1000.')
                            ->numeric()
                            ->required(),
                        Textarea::make('note')
                            ->label('Motivo (auditoria)')
                            ->required(),
                    ])
                    ->action(function (array $data, Organization $record): void {
                        try {
                            $tx = app(CreditWallet::class)->adjust($record, (int) $data['delta'], (string) $data['note']);
                            Notification::make()->title('Saldo ajustado')->body('Novo saldo: '.$tx->balance_after.' créditos.')->success()->send();
                        } catch (InsufficientCreditsException $e) {
                            Notification::make()->title('Ajuste recusado')->body('Saldo insuficiente para remover esse valor (atual: '.$e->balance.').')->danger()->send();
                        }
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
