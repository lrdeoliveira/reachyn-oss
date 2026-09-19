<?php

namespace App\Filament\Resources\Approvals\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ApprovalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->required(),
                TextInput::make('job_id'),
                TextInput::make('keyword')
                    ->required()
                    ->default(''),
                Textarea::make('preview_text')
                    ->columnSpanFull(),
                Textarea::make('image_url')
                    ->columnSpanFull(),
                Textarea::make('video_url')
                    ->columnSpanFull(),
                Textarea::make('resume_url')
                    ->columnSpanFull(),
                Textarea::make('cancel_url')
                    ->columnSpanFull(),
                Textarea::make('meta')
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('pendente'),
            ]);
    }
}
