<?php

namespace App\Filament\Resources\Suppressions\Schemas;

use App\Models\Sender;
use App\Models\Suppression;
use App\Support\Telefone;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SuppressionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bloqueio manual')
                    ->description('Escopo "tudo" vale para todos os remetentes e para sempre; "só marketing" deixa passar aviso de conta (UTILITY).')
                    ->columns(2)
                    ->schema([
                        TextInput::make('telefone')
                            ->label('Telefone')
                            ->tel()
                            ->required()
                            ->placeholder('+55 21 99876-5432')
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (Telefone::normalizar((string) $value) === null) {
                                        $fail('Informe um celular ou fixo brasileiro válido (DDD + número).');
                                    }
                                },
                            ]),
                        Select::make('escopo')
                            ->label('Escopo')
                            ->options(Suppression::escopoLabels())
                            ->default(Suppression::ESCOPO_TUDO)
                            ->required()
                            ->native(false)
                            ->live(),
                        Select::make('sender_id')
                            ->label('Remetente')
                            ->options(fn (): array => Sender::query()->orderBy('nome')->pluck('nome', 'id')->all())
                            ->native(false)
                            ->placeholder('Todos os remetentes')
                            ->helperText('A preferência de marketing é por número; em branco vale para todos.')
                            ->visible(fn (Get $get): bool => $get('escopo') === Suppression::ESCOPO_MARKETING),
                        Select::make('motivo')
                            ->label('Motivo')
                            ->options([
                                Suppression::MOTIVO_MANUAL => Suppression::motivoLabels()[Suppression::MOTIVO_MANUAL],
                                Suppression::MOTIVO_IMPORTACAO => Suppression::motivoLabels()[Suppression::MOTIVO_IMPORTACAO],
                            ])
                            ->default(Suppression::MOTIVO_MANUAL)
                            ->required()
                            ->native(false),
                        Textarea::make('detalhes')->label('Detalhes')->rows(2)->maxLength(500)->columnSpanFull(),
                    ]),
            ]);
    }
}
