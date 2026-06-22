<?php

namespace App\Filament\Resources\Drafts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class DraftForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->required(),
                TextInput::make('keyword')
                    ->required()
                    ->default(''),
                Textarea::make('research')
                    ->columnSpanFull(),
                Textarea::make('texts')
                    ->columnSpanFull(),
                Textarea::make('media')
                    ->columnSpanFull(),
                Textarea::make('image_url')
                    ->columnSpanFull(),
                Textarea::make('video_url')
                    ->columnSpanFull(),
                Textarea::make('image_prompt')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('rascunho'),
            ]);
    }
}
