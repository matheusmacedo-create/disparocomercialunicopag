<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Telefone;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $euMesmo = fn (?User $record): bool => $record !== null && $record->is(auth()->user());

        return $schema
            ->components([
                Section::make('Usuário')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(255),
                        TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                        TextInput::make('password')
                            ->label('Senha')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Em branco mantém a senha atual.' : null),
                        TextInput::make('telefone_teste')
                            ->label('Telefone de teste (WhatsApp)')
                            ->tel()
                            ->placeholder('+55 21 99876-5432')
                            ->helperText('Único destino do "Enviar teste" das campanhas. Fica em E.164.')
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (filled($value) && Telefone::normalizar((string) $value) === null) {
                                        $fail('Informe um celular brasileiro válido (DDD + número).');
                                    }
                                },
                            ])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Telefone::normalizar($state) : null),
                        Toggle::make('ativo')
                            ->label('Ativo')
                            ->default(true)
                            ->helperText('Desativado não entra no painel. Usuário nunca é apagado: a trilha guarda quem aprovou o quê.')
                            // Ninguém se desativa (ficaria trancado para fora); o servidor ignora o campo nesse caso.
                            ->disabled($euMesmo),
                    ]),

                Section::make('Papel')
                    ->description('Sem papel o usuário não entra no painel. Ninguém muda o próprio papel.')
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'name')
                            ->descriptions(Permissions::roleDescriptions())
                            ->columns(2)
                            // Desabilitado, o Filament não grava a relação: nem um `set()` forjado troca o próprio papel.
                            ->disabled($euMesmo)
                            ->helperText(fn (?User $record): ?string => $euMesmo($record) ? 'Peça a outro administrador para mudar o seu papel.' : null),
                    ]),
            ]);
    }
}
