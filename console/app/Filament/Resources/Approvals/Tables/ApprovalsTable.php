<?php

namespace App\Filament\Resources\Approvals\Tables;

use App\Models\Approval;
use App\Services\PublishService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApprovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.name')
                    ->searchable(),
                TextColumn::make('job_id')
                    ->searchable(),
                TextColumn::make('keyword')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'aprovado' => 'success',
                        'rejeitado' => 'danger',
                        default => 'warning',
                    })
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('aprovar')
                    ->label('Aprovar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Approval $record) => $record->status === 'pendente')
                    ->requiresConfirmation()
                    ->action(function (Approval $record) {
                        $results = app(PublishService::class)->publishApproval($record);
                        $ok = $results === [] || collect($results)->every(fn ($r) => $r['ok'] ?? false);
                        $record->update([
                            'status' => 'aprovado',
                            'meta' => array_merge($record->meta ?? [], ['publish' => $results]),
                        ]);
                        Notification::make()
                            ->title($ok ? 'Aprovado e publicado' : 'Aprovado (avisos no publish)')
                            ->{$ok ? 'success' : 'warning'}()
                            ->send();
                    }),
                Action::make('rejeitar')
                    ->label('Rejeitar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Approval $record) => $record->status === 'pendente')
                    ->requiresConfirmation()
                    ->action(fn (Approval $record) => $record->update(['status' => 'rejeitado'])),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
