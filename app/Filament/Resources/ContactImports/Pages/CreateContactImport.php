<?php

namespace App\Filament\Resources\ContactImports\Pages;

use App\Filament\Resources\ContactImports\ContactImportResource;
use App\Filament\Support\Pii;
use App\Jobs\ProcessarImportacaoJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Tag;
use App\Services\Importacao\ImportadorCsv;
use App\Support\Mask;
use App\Support\Permissions;
use App\Support\Variaveis;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Importar CSV em três passos (achado da revisão de 28/10: sem prévia do mapeamento o operador importa
 * no escuro): arquivo e destino → mapeamento das colunas com prévia das primeiras linhas → confirmação.
 * O processamento é do job {@see ProcessarImportacaoJob} (outra frente); o cabeçalho é lido aqui e a
 * sugestão de mapeamento e a prévia vêm de {@see ImportadorCsv} (outra frente):
 *   ImportadorCsv::sugerirMapeamento(array $cabecalho): array<string, string>   coluna => campo
 *   ImportadorCsv::previa(string $caminho, array $mapeamento, array $opcoes): array{linhas: list<array>, previsao: array}
 *
 * O que fica no registro: `arquivo` (caminho no disco `local`, pasta `importacoes/`), `nome_original`,
 * `mapeamento` {coluna => campo}, `contact_list_id`, `origem`, `tipo_contato`, `tags` (ids),
 * `consentimento_origem` e `parametros` {consentimento_canal, consentimento_detalhes}.
 */
class CreateContactImport extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ContactImportResource::class;

    protected static bool $canCreateAnother = false;

    public const CAMPO_IGNORAR = 'ignorar';

    public const CAMPO_ATRIBUTO = 'atributos';

    protected function getSteps(): array
    {
        return [
            Step::make('Arquivo')
                ->description('O CSV e a base de destino')
                ->schema([
                    Select::make('tipo')
                        ->label('O que o arquivo traz')
                        ->options([
                            ContactImport::TIPO_CSV => 'Contatos (telefone, nome, e-mail...)',
                            ContactImport::TIPO_OPTOUT => 'Lista de opt-out (telefones que pediram para parar)',
                        ])
                        ->default(ContactImport::TIPO_CSV)
                        ->required()
                        ->native(false)
                        ->live(),
                    FileUpload::make('arquivo')
                        ->label('Arquivo CSV')
                        ->required()
                        ->disk('local')
                        ->directory('importacoes')
                        ->visibility('private')
                        ->storeFileNamesIn('nome_original')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                        ->maxSize(20480)
                        ->helperText('Separado por vírgula ou ponto e vírgula, com cabeçalho na primeira linha. Até 20 MB.'),
                    Hidden::make('nome_original'),
                    Section::make('Destino')
                        ->columns(2)
                        ->visible(fn (Get $get): bool => $get('tipo') === ContactImport::TIPO_CSV)
                        ->schema([
                            Select::make('contact_list_id')
                                ->label('Base de destino')
                                ->options(fn (): array => ContactList::query()->where('ativa', true)->orderBy('nome')->pluck('nome', 'id')->all())
                                ->searchable()
                                ->required(fn (Get $get): bool => $get('tipo') === ContactImport::TIPO_CSV)
                                ->native(false)
                                ->live(),
                            Select::make('origem')
                                ->label('Origem dos contatos')
                                ->options(Contact::origemLabels())
                                ->default('csv')
                                ->required(fn (Get $get): bool => $get('tipo') === ContactImport::TIPO_CSV)
                                ->native(false)
                                ->live(),
                            Select::make('tipo_contato')
                                ->label('Tipo dos contatos')
                                ->options(Contact::tipoLabels())
                                ->default('lead')
                                ->required(fn (Get $get): bool => $get('tipo') === ContactImport::TIPO_CSV)
                                ->native(false),
                            Select::make('tags')
                                ->label('Tags para todos')
                                ->multiple()
                                ->options(fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all()),
                        ]),
                    Section::make('Consentimento')
                        ->description('Obrigatório quando a base exige consentimento ou a origem é externa (UnicoDrop/CSV): a Meta exige opt-in que nomeie a ÚnicoPag (KB 2.13).')
                        ->columns(2)
                        ->visible(fn (Get $get): bool => $get('tipo') === ContactImport::TIPO_CSV)
                        ->schema([
                            Select::make('consentimento_origem')
                                ->label('Como consentiram')
                                ->options(Contact::consentimentoLabels())
                                ->native(false)
                                ->required(fn (Get $get): bool => self::exigeConsentimento($get))
                                ->live(),
                            TextInput::make('consentimento_canal')
                                ->label('Canal')
                                ->maxLength(30)
                                ->placeholder('formulário, contrato, whatsapp...')
                                ->required(fn (Get $get): bool => self::exigeConsentimento($get) || filled($get('consentimento_origem'))),
                            Textarea::make('consentimento_detalhes')->label('Prova / detalhes (termo, versão, data da coleta)')->rows(2)->maxLength(500)->columnSpanFull(),
                        ]),
                ])
                ->afterValidation(function (Get $get, Set $set): void {
                    $caminho = self::caminhoDoArquivo($get('arquivo'));

                    if ($caminho === null) {
                        return;
                    }

                    $cabecalho = self::lerCabecalho($caminho);

                    try {
                        $sugerido = (array) app(ImportadorCsv::class)->sugerirMapeamento($cabecalho);
                    } catch (Throwable) {
                        $sugerido = [];
                    }

                    $set('mapeamento', collect($cabecalho)->mapWithKeys(fn (string $coluna): array => [
                        (string) Str::uuid() => ['coluna' => $coluna, 'campo' => self::campoSugerido($coluna, $sugerido)],
                    ])->all());
                }),

            Step::make('Mapeamento')
                ->description('Qual coluna vira qual campo')
                ->schema([
                    Repeater::make('mapeamento')
                        ->label('Colunas do arquivo')
                        ->schema([
                            TextInput::make('coluna')->label('Coluna do CSV')->disabled()->dehydrated(),
                            Select::make('campo')
                                ->label('Vira')
                                ->options(self::camposDeDestino())
                                ->default(self::CAMPO_IGNORAR)
                                ->required()
                                ->native(false)
                                ->live(),
                        ])
                        ->columns(2)
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                $campos = collect((array) $value)->pluck('campo');

                                if (! $campos->contains('telefone')) {
                                    $fail('Marque qual coluna é o telefone.');
                                }

                                if ($campos->filter(fn ($c): bool => $c !== self::CAMPO_IGNORAR && $c !== self::CAMPO_ATRIBUTO)->duplicates()->isNotEmpty()) {
                                    $fail('Duas colunas não podem virar o mesmo campo.');
                                }
                            },
                        ])
                        ->live(),
                    Placeholder::make('previa')
                        ->label('Prévia das primeiras linhas, já normalizadas')
                        ->content(fn (Get $get): HtmlString|string => self::previa($get)),
                ]),

            Step::make('Confirmação')
                ->description('Confere e enfileira')
                ->schema([
                    Placeholder::make('resumo')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::resumo($get)),
                ]),
        ];
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['mapeamento'] = self::mapeamentoAssociativo((array) ($data['mapeamento'] ?? []));
        $data['tags'] = array_values(array_map('intval', (array) ($data['tags'] ?? [])));
        $data['parametros'] = array_filter([
            'consentimento_canal' => $data['consentimento_canal'] ?? null,
            'consentimento_detalhes' => $data['consentimento_detalhes'] ?? null,
        ], fn ($v): bool => filled($v));
        $data['nome_original'] = mb_substr(basename((string) ($data['nome_original'] ?: $data['arquivo'] ?: 'arquivo.csv')), 0, 255);
        $data['user_id'] = auth()->id();
        $data['status'] = ContactImport::STATUS_PENDENTE;

        if (($data['tipo'] ?? null) === ContactImport::TIPO_OPTOUT) {
            $data['contact_list_id'] = null;
            $data['origem'] = null;
            $data['tipo_contato'] = null;
            $data['tags'] = [];
            $data['consentimento_origem'] = null;
        }

        unset($data['consentimento_canal'], $data['consentimento_detalhes']);

        return $data;
    }

    protected function afterCreate(): void
    {
        ProcessarImportacaoJob::dispatch($this->getRecord());

        Notification::make()
            ->info()
            ->title('Importação na fila')
            ->body('Você recebe o aviso no sininho quando terminar; as contagens aparecem nesta tela.')
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /** @return array<string, string> */
    public static function camposDeDestino(): array
    {
        return [
            'telefone' => 'Telefone (WhatsApp)',
            'nome' => 'Nome',
            'email' => 'E-mail',
            'documento' => 'CPF/CNPJ',
            'empresa' => 'Empresa / loja',
            'account_id' => 'Conta no unico-api',
            self::CAMPO_ATRIBUTO => 'Atributo (vira {{atributos.coluna}})',
            self::CAMPO_IGNORAR => 'Ignorar',
        ];
    }

    /**
     * Do repeater ([{coluna, campo}]) para o JSON do registro ({coluna => campo}); "ignorar" fica de fora
     * e "atributos" vira `atributos.<coluna>` em minúsculas, como {@see Variaveis} espera.
     *
     * @param  array<int|string, array<string, mixed>>  $itens
     * @return array<string, string>
     */
    public static function mapeamentoAssociativo(array $itens): array
    {
        $mapa = [];

        foreach ($itens as $item) {
            $coluna = trim((string) ($item['coluna'] ?? ''));
            $campo = (string) ($item['campo'] ?? self::CAMPO_IGNORAR);

            if ($coluna === '' || $campo === '' || $campo === self::CAMPO_IGNORAR) {
                continue;
            }

            $mapa[$coluna] = $campo === self::CAMPO_ATRIBUTO ? 'atributos.'.Str::lower(Str::slug($coluna, '_')) : $campo;
        }

        return $mapa;
    }

    private static function exigeConsentimento(Get $get): bool
    {
        if ($get('tipo') !== ContactImport::TIPO_CSV) {
            return false;
        }

        if (in_array((string) $get('origem'), Contact::ORIGENS_EXTERNAS, true)) {
            return true;
        }

        $listaId = (int) $get('contact_list_id');

        return $listaId > 0 && ContactList::query()->whereKey($listaId)->where('exige_consentimento', true)->exists();
    }

    /** O que o importador sugeriu para a coluna, traduzido para as opções do select. */
    private static function campoSugerido(string $coluna, array $sugerido): string
    {
        $campo = (string) ($sugerido[$coluna] ?? self::CAMPO_IGNORAR);

        if (str_starts_with($campo, 'atributos.')) {
            return self::CAMPO_ATRIBUTO;
        }

        return array_key_exists($campo, self::camposDeDestino()) ? $campo : self::CAMPO_IGNORAR;
    }

    /**
     * O arquivo que o operador subiu, ainda temporário (Livewire) ou já guardado no disco. Nunca um caminho
     * vindo de campo editável: o cliente não escolhe o que o servidor lê.
     */
    private static function caminhoDoArquivo(mixed $estado): ?string
    {
        $arquivo = is_array($estado) ? reset($estado) : $estado;

        if ($arquivo instanceof TemporaryUploadedFile || $arquivo instanceof UploadedFile) {
            $caminho = $arquivo->getRealPath();

            return is_string($caminho) && $caminho !== '' && is_file($caminho) ? $caminho : null;
        }

        if (is_string($arquivo) && $arquivo !== '' && Storage::disk('local')->exists($arquivo)) {
            return Storage::disk('local')->path($arquivo);
        }

        return null;
    }

    /** @return list<string> */
    public static function lerCabecalho(string $caminho): array
    {
        $handle = @fopen($caminho, 'rb');

        if ($handle === false) {
            return [];
        }

        $linha = (string) fgets($handle);
        fclose($handle);

        $linha = (string) preg_replace('/^\xEF\xBB\xBF/', '', $linha);
        $delimitador = substr_count($linha, ';') > substr_count($linha, ',') ? ';' : ',';

        return array_values(array_filter(
            array_map(fn (?string $coluna): string => trim((string) $coluna, " \t\n\r\0\x0B\""), str_getcsv($linha, $delimitador, '"', '\\')),
            fn (string $coluna): bool => $coluna !== '',
        ));
    }

    private static function previa(Get $get): HtmlString|string
    {
        $caminho = self::caminhoDoArquivo($get('arquivo'));

        if ($caminho === null) {
            return 'Envie o arquivo no passo anterior para ver a prévia.';
        }

        $mapeamento = self::mapeamentoAssociativo((array) ($get('mapeamento') ?? []));

        if (! in_array('telefone', $mapeamento, true)) {
            return 'Marque a coluna do telefone para ver a prévia.';
        }

        try {
            $previa = (array) app(ImportadorCsv::class)->previa($caminho, $mapeamento, [
                'tipo' => (string) $get('tipo'),
                'origem' => (string) $get('origem'),
                'tipo_contato' => (string) $get('tipo_contato'),
                'contact_list_id' => (int) $get('contact_list_id'),
                'consentimento_origem' => (string) $get('consentimento_origem'),
                'linhas' => 10,
            ]);
        } catch (Throwable $e) {
            return 'Prévia indisponível: '.Mask::scrub($e->getMessage());
        }

        $linhas = array_values((array) ($previa['linhas'] ?? []));
        $previsao = (array) ($previa['previsao'] ?? []);

        $html = '';

        if ($previsao !== []) {
            $html .= '<p class="text-sm mb-2">'.e(collect($previsao)->map(fn ($v, $k): string => Str::headline((string) $k).': '.(is_scalar($v) ? (string) $v : json_encode($v)))->implode(' · ')).'</p>';
        }

        if ($linhas === []) {
            return new HtmlString($html.'<p class="text-sm">Nenhuma linha para mostrar.</p>');
        }

        $colunas = array_keys((array) $linhas[0]);
        $html .= '<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr>';

        foreach ($colunas as $coluna) {
            $html .= '<th class="text-left px-2 py-1 font-medium">'.e((string) $coluna).'</th>';
        }

        $html .= '</tr></thead><tbody>';

        foreach ($linhas as $linha) {
            $html .= '<tr>';

            foreach ($colunas as $coluna) {
                $html .= '<td class="px-2 py-1">'.e(self::valorNaPrevia((string) $coluna, data_get($linha, $coluna))).'</td>';
            }

            $html .= '</tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }

    /** Na prévia, telefone, documento e e-mail saem mascarados para quem não tem `view pii`. */
    private static function valorNaPrevia(string $campo, mixed $valor): string
    {
        $texto = is_scalar($valor) || $valor === null ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE);

        if (Permissions::allows(Permissions::VIEW_PII)) {
            return $texto;
        }

        return match ($campo) {
            'telefone' => Pii::telefone($texto),
            'documento' => (string) Pii::documento($texto),
            'email' => (string) Pii::email($texto),
            default => $texto,
        };
    }

    private static function resumo(Get $get): HtmlString
    {
        $mapeamento = self::mapeamentoAssociativo((array) ($get('mapeamento') ?? []));
        $lista = (int) $get('contact_list_id') > 0 ? ContactList::query()->find((int) $get('contact_list_id')) : null;

        $itens = array_filter([
            'Arquivo' => (string) $get('nome_original') ?: 'CSV enviado',
            'Tipo' => $get('tipo') === ContactImport::TIPO_OPTOUT ? 'lista de opt-out (telefones bloqueados em todos os remetentes)' : 'contatos',
            'Base' => $lista?->nome,
            'Origem' => Contact::origemLabels()[(string) $get('origem')] ?? null,
            'Tipo dos contatos' => Contact::tipoLabels()[(string) $get('tipo_contato')] ?? null,
            'Consentimento' => Contact::consentimentoLabels()[(string) $get('consentimento_origem')] ?? null,
            'Mapeamento' => collect($mapeamento)->map(fn (string $campo, string $coluna): string => $coluna.' → '.$campo)->implode(', ') ?: null,
        ]);

        $html = '<dl class="text-sm space-y-1">';

        foreach ($itens as $rotulo => $valor) {
            $html .= '<div><dt class="inline font-medium">'.e($rotulo).':</dt> <dd class="inline">'.e((string) $valor).'</dd></div>';
        }

        $html .= '</dl><p class="text-sm mt-3">Telefone já cadastrado é atualizado (nome vazio não apaga nome preenchido; atributos fazem merge) e entra na base; contato em opt-out entra na base mas segue bloqueado (KB 4.4).</p>';

        return new HtmlString($html);
    }
}
