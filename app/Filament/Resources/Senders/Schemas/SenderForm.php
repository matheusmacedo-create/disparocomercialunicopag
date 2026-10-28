<?php

namespace App\Filament\Resources\Senders\Schemas;

use App\Models\Sender;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SenderForm
{
    public const FUSOS = [
        'America/Sao_Paulo' => 'Brasília (America/Sao_Paulo)',
        'America/Manaus' => 'Manaus (America/Manaus)',
        'America/Cuiaba' => 'Cuiabá (America/Cuiaba)',
        'America/Campo_Grande' => 'Campo Grande (America/Campo_Grande)',
        'America/Porto_Velho' => 'Porto Velho (America/Porto_Velho)',
        'America/Boa_Vista' => 'Boa Vista (America/Boa_Vista)',
        'America/Rio_Branco' => 'Rio Branco (America/Rio_Branco)',
        'America/Fortaleza' => 'Fortaleza (America/Fortaleza)',
        'America/Noronha' => 'Fernando de Noronha (America/Noronha)',
    ];

    public static function configure(Schema $schema): Schema
    {
        $tetoMaximo = (int) config('disparo.teto_maximo_absoluto', 1000);

        return $schema
            ->components([
                Section::make('Número')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')->label('Nome')->required()->maxLength(120)->helperText('Como aparece nas campanhas: "Comercial", "Avisos"...'),
                        Select::make('provider')->label('Provedor')->options(Sender::provedores())->required()->default(Sender::PROVIDER_KAPSO),
                        Select::make('finalidade')->label('Finalidade')->options(Sender::finalidades())->required()->default(Sender::FINALIDADE_COMERCIAL),
                        Toggle::make('ativo')->label('Ativo')->default(true)->helperText('Desligado: não envia e não aparece nas campanhas.'),
                        TextInput::make('phone_number_id')->label('Phone number ID (Meta)')->required()->maxLength(40)->unique(ignoreRecord: true),
                        TextInput::make('waba_id')->label('WABA ID')->maxLength(40),
                        TextInput::make('api_key')
                            ->label('Chave da API')
                            ->password()
                            ->revealable()
                            ->maxLength(4000)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->helperText(fn (?Sender $record): string => $record
                                ? 'Em branco mantém a chave atual. Fica criptografada e nunca aparece aqui.'
                                : 'Chave da Kapso (projeto) ou token permanente da Meta. Em branco, usa a chave do .env do provedor. Fica criptografada.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Política de envio')
                    ->description('A regra do número: o que ele pode mandar, quanto por dia, em que ritmo e em que horário (KB 2.5, 2.10, 2.11).')
                    ->columns(3)
                    ->schema([
                        CheckboxList::make('categorias_permitidas')
                            ->label('Categorias permitidas')
                            ->options([Sender::CATEGORIA_UTILITY => 'UTILITY (aviso de conta)', Sender::CATEGORIA_MARKETING => 'MARKETING (oferta)'])
                            ->default([Sender::CATEGORIA_UTILITY])
                            ->helperText('Nada marcado = só UTILITY. O número do KYC nunca manda MARKETING.')
                            ->columnSpanFull(),
                        TextInput::make('teto_diario')
                            ->label('Teto diário')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue($tetoMaximo)
                            ->default((int) config('disparo.remetente.teto_diario', 200))
                            ->required()
                            ->helperText('Templates por 24 h, contando o que o webhook vê sair por fora. Máximo absoluto: '.$tetoMaximo.'.'),
                        TextInput::make('msgs_por_minuto')
                            ->label('Mensagens por minuto')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(600)
                            ->default((int) config('disparo.remetente.msgs_por_minuto', 20))
                            ->required(),
                        Select::make('fuso')->label('Fuso da janela')->options(self::FUSOS)->default('America/Sao_Paulo')->required(),
                        TextInput::make('janela_inicio')
                            ->label('Janela: início')
                            ->default((string) config('disparo.remetente.janela_inicio', '08:00'))
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                            ->placeholder('08:00')
                            ->required(),
                        TextInput::make('janela_fim')
                            ->label('Janela: fim')
                            ->default((string) config('disparo.remetente.janela_fim', '20:00'))
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                            ->placeholder('20:00')
                            ->required(),
                        TextInput::make('sonda_intervalo_minutos')
                            ->label('Sonda a cada (min)')
                            ->numeric()
                            ->integer()
                            ->minValue(5)
                            ->default(120)
                            ->required()
                            ->helperText('Conta impedida (pagamento, token, saldo): 1 envio de sonda a cada N minutos.'),
                    ]),

                Section::make('Aquecimento')
                    ->description('Número novo ou que voltou de RED/YELLOW: teto inicial multiplicado pelo fator a cada dia GREEN, até o teto configurado.')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('aquecimento_inicio')->label('Início do aquecimento')->native(false)->displayFormat('d/m/Y')->helperText('Em branco: sem aquecimento.'),
                        TextInput::make('aquecimento_teto_inicial')->label('Teto inicial')->numeric()->integer()->minValue(1)->placeholder((string) config('disparo.remetente.aquecimento_teto_inicial', 50)),
                        TextInput::make('aquecimento_fator')->label('Fator diário')->numeric()->minValue(1)->maxValue(5)->step(0.1)->placeholder((string) config('disparo.remetente.aquecimento_fator', 1.5)),
                    ]),
            ]);
    }
}
