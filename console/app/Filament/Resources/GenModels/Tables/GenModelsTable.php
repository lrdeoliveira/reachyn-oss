<?php

namespace App\Filament\Resources\GenModels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class GenModelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('kind')   // agrupado por característica: vídeo / imagem / áudio / texto
            ->defaultSort('sort_order')
            ->groups([
                Group::make('kind')->label('Característica'),
                Group::make('provider')->label('Provedor'),
            ])
            ->columns([
                TextColumn::make('display_name')
                    ->label('Nome público')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'video' => 'info',
                        'image' => 'success',
                        'audio' => 'warning',
                        'text' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('provider')
                    ->label('Provedor (interno)')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('provider_model_id')
                    ->label('ID upstream')
                    ->color('gray')
                    ->searchable()
                    ->toggleable(),
                TextInputColumn::make('cost_credits')
                    ->label('Créditos')
                    ->type('number')
                    ->rules(['nullable', 'integer', 'min:0']),
                ToggleColumn::make('is_active')->label('Ativo'),
                ToggleColumn::make('is_unstable')->label('Instável'),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Característica')
                    ->options([
                        'video' => 'Vídeo',
                        'image' => 'Imagem',
                        'audio' => 'Áudio',
                        'text' => 'Texto',
                    ]),
                SelectFilter::make('provider')->label('Provedor'),
                TernaryFilter::make('is_active')->label('Ativo'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
