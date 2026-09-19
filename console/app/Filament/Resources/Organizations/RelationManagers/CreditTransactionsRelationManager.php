<?php

namespace App\Filament\Resources\Organizations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * LEDGER de créditos da organização (aba no admin) — extrato append-only que audita todo
 * grant/débito/estorno/ajuste (CreditWallet). SOMENTE LEITURA: transação não se edita nem
 * se apaga (correção = novo `adjust` com motivo, pela ação "Ajustar créditos" da listagem).
 */
class CreditTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'creditTransactions';

    protected static ?string $title = 'Créditos (extrato)';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'grant_subscription' => 'Concessão (assinatura)',
                        'grant_topup' => 'Concessão (top-up)',
                        'debit_generation' => 'Débito (geração)',
                        'refund_generation' => 'Estorno',
                        'adjustment' => 'Ajuste manual',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match (true) {
                        str_starts_with((string) $state, 'grant') => 'success',
                        str_starts_with((string) $state, 'refund') => 'info',
                        $state === 'adjustment' => 'warning',
                        default => 'gray', // debit_*
                    }),
                TextColumn::make('delta')
                    ->label('Variação')
                    ->numeric()
                    ->badge()
                    ->color(fn ($state): string => (int) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after')
                    ->label('Saldo após')
                    ->numeric(),
                // Orgs EXEMPT: delta 0 e o custo-que-seria em meta.would_be (monitoramento sem cobrança).
                TextColumn::make('meta.would_be')
                    ->label('Custo (isento)')
                    ->numeric()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($state): string => (int) $state < 0 ? 'warning' : 'gray'),
                TextColumn::make('tenant.name')
                    ->label('Marca')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('meta.note')
                    ->label('Motivo/nota')
                    ->limit(60)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('reference_type')
                    ->label('Referência')
                    ->formatStateUsing(fn ($state, $record) => $state ? $state.'#'.$record->reference_id : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'grant_subscription' => 'Concessão (assinatura)',
                        'grant_topup' => 'Concessão (top-up)',
                        'debit_generation' => 'Débito (geração)',
                        'refund_generation' => 'Estorno',
                        'adjustment' => 'Ajuste manual',
                    ]),
            ])
            // Ledger é APPEND-ONLY: sem criar/editar/apagar pela UI (auditoria).
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
