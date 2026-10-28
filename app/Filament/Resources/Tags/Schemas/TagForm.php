<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tag')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')->label('Nome')->required()->maxLength(60)->unique(ignoreRecord: true),
                        ColorPicker::make('cor')->label('Cor'),
                    ]),
            ]);
    }
}
