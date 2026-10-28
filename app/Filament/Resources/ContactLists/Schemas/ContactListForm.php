<?php

namespace App\Filament\Resources\ContactLists\Schemas;

use App\Models\ContactList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ContactListForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Base')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')->label('Nome')->required()->maxLength(255)->columnSpanFull(),
                        Select::make('dono')
                            ->label('Dono da base')
                            ->options(ContactList::donoLabels())
                            ->default(ContactList::DONO_UNICOPAG)
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state !== ContactList::DONO_UNICOPAG) {
                                    $set('exige_consentimento', true);
                                }
                            }),
                        Toggle::make('exige_consentimento')
                            ->label('Exige consentimento registrado')
                            ->helperText('Base da UnicoDrop ou compartilhada: travado em "sim" - o lead consentiu com a outra marca, não com a ÚnicoPag (KB 2.13). MARKETING só sai para quem tem consentimento em nome da ÚnicoPag.')
                            ->default(false)
                            // Trava visual; a regra de verdade está no model (ContactList::saving).
                            ->disabled(fn (Get $get): bool => $get('dono') !== ContactList::DONO_UNICOPAG)
                            ->dehydrated(),
                        Textarea::make('descricao')->label('Descrição')->rows(3)->columnSpanFull(),
                        Toggle::make('ativa')->label('Ativa')->default(true)->helperText('Base inativa não aparece para campanha nova; os contatos continuam nela.'),
                    ]),
            ]);
    }
}
