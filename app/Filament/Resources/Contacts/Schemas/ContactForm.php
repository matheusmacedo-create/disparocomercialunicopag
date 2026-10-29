<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\Pii;
use App\Models\Contact;
use App\Support\Permissions;
use App\Support\Telefone;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        // E-mail e CPF/CNPJ: quem cria pode digitar; na edição só quem tem `view pii` vê o que está lá.
        $dadoSensivel = fn (string $operation): bool => $operation === 'create' || Permissions::allows(Permissions::VIEW_PII);

        return $schema
            ->components([
                Section::make('Contato')
                    ->columns(2)
                    ->schema([
                        TextInput::make('telefone')
                            ->label('Telefone (WhatsApp)')
                            ->tel()
                            ->required()
                            ->placeholder('+55 21 99876-5432')
                            ->helperText('DDD + número. Fica guardado em E.164 (+5511912345678).')
                            ->rules([
                                fn (?Contact $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    $normal = Telefone::normalizar((string) $value);

                                    if ($normal === null) {
                                        $fail('Informe um celular ou fixo brasileiro válido (DDD + número).');

                                        return;
                                    }

                                    // unique() do Filament compararia o que foi digitado, não a forma E.164.
                                    $repetido = Contact::query()
                                        ->where('telefone', $normal)
                                        ->when($record !== null, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                                        ->exists();

                                    if ($repetido) {
                                        $fail('Já existe um contato com este telefone.');
                                    }
                                },
                            ])
                            ->dehydrateStateUsing(fn (mixed $state): mixed => Telefone::normalizar((string) $state) ?? $state)
                            // Editar sem `view pii`: o número aparece mascarado, travado e não é regravado.
                            ->formatStateUsing(fn (?string $state, string $operation): ?string => $operation === 'edit' && ! Permissions::allows(Permissions::VIEW_PII) && filled($state) ? Pii::telefone($state) : $state)
                            ->dehydrated(fn (string $operation): bool => $operation === 'create' || Permissions::allows(Permissions::VIEW_PII))
                            ->disabled(fn (?Contact $record, string $operation): bool => ($record?->anonimizado() ?? false) || ($operation === 'edit' && ! Permissions::allows(Permissions::VIEW_PII))),
                        TextInput::make('nome')->label('Nome')->maxLength(255),
                        TextInput::make('empresa')->label('Empresa / loja')->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255)
                            ->visible($dadoSensivel),
                        TextInput::make('documento')
                            ->label('CPF/CNPJ')
                            ->maxLength(20)
                            ->helperText('Fica criptografado; no painel aparece mascarado.')
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    $digitos = preg_replace('/\D/', '', (string) $value) ?? '';

                                    if ($digitos !== '' && ! in_array(strlen($digitos), [11, 14], true)) {
                                        $fail('CPF tem 11 dígitos e CNPJ tem 14.');
                                    }
                                },
                            ])
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? preg_replace('/\D/', '', $state) : null)
                            ->visible($dadoSensivel),
                        Select::make('origem')->label('Origem')->options(Contact::origemLabels())->default('manual')->required()->native(false),
                        Select::make('tipo')->label('Tipo')->options(Contact::tipoLabels())->default('outro')->required()->native(false),
                        TextInput::make('account_id')->label('Conta no unico-api')->maxLength(64)->placeholder('accounts.id'),
                        Textarea::make('observacoes')->label('Observações')->rows(3)->columnSpanFull(),
                    ]),

                Section::make('Consentimento')
                    ->description('Contato de base externa (UnicoDrop/CSV) só recebe MARKETING com consentimento registrado em nome da ÚnicoPag (KB 2.13).')
                    ->columns(2)
                    ->schema([
                        Select::make('consentimento_origem')
                            ->label('Como consentiu')
                            ->options(Contact::consentimentoLabels())
                            ->native(false)
                            ->live(),
                        TextInput::make('consentimento_canal')
                            ->label('Canal')
                            ->maxLength(30)
                            ->placeholder('whatsapp, formulário, contrato...')
                            ->required(fn (Get $get, ?Contact $record): bool => filled($get('consentimento_origem')) && $get('consentimento_origem') !== $record?->consentimento_origem),
                        DateTimePicker::make('consentimento_em')
                            ->label('Quando')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d/m/Y H:i')
                            ->required(fn (Get $get, ?Contact $record): bool => filled($get('consentimento_origem')) && $get('consentimento_origem') !== $record?->consentimento_origem),
                        Textarea::make('consentimento_detalhes')->label('Prova / detalhes')->rows(2)->maxLength(500),
                    ]),
            ]);
    }
}
