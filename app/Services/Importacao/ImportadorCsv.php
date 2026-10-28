<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Jobs\ProcessarImportacaoJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Support\Mask;
use App\Support\Telefone;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Importação de CSV (KB 4.4): a planilha da UnicoDrop ou do comercial vira contatos numa base.
 *
 * Regras:
 *  - mapeamento coluna => telefone | nome | primeiro_nome | email | documento | empresa | account_id |
 *    atributos.<chave> | ignorar, com sugestão automática ({@see self::sugerirMapeamento()});
 *  - coluna sensível (cpf, cnpj, documento, rg, endereco, nascimento, renda, senha) só vai para campo
 *    tipado (`documento`, criptografado) ou é ignorada - nunca para `atributos`, que viram variável de
 *    template e ficam em claro (achado de LGPD de 28/10);
 *  - telefone normalizado ({@see Telefone::normalizar()}); inválido é rejeitado, repetido no arquivo também;
 *  - telefone já existente ATUALIZA: nome pela precedência das fontes (vazio não apaga), atributos fazem
 *    merge (chaves minúsculas), consentimento só preenche vazio; `optout`/`anonimizado` entra na base e
 *    segue bloqueado;
 *  - base que exige consentimento (ou origem externa) sem consentimento informado: importa mesmo assim e
 *    conta em `sem_consentimento` - a guarda do envio MARKETING barra;
 *  - `tipo=optout`: cada telefone vira supressão `importacao` (escopo tudo) e nenhum contato é criado;
 *  - TODAS as rejeitadas vão para rejeitadas.csv; o arquivo original é apagado depois de processar.
 */
final class ImportadorCsv
{
    public const CAMPO_TELEFONE = 'telefone';

    public const CAMPO_IGNORAR = 'ignorar';

    public const PREFIXO_ATRIBUTO = 'atributos.';

    /** Campos tipados do contato (cada um aceito uma vez por mapeamento). */
    public const CAMPOS_TIPADOS = ['telefone', 'nome', 'primeiro_nome', 'email', 'documento', 'empresa', 'account_id'];

    /** Nomes de coluna (normalizados) que sugerem cada campo tipado. */
    private const SUGESTOES = [
        'nome' => ['nome', 'name', 'nome completo', 'full name', 'cliente', 'contato', 'responsavel', 'nome do cliente', 'nome do contato', 'nome do responsavel'],
        'primeiro_nome' => ['primeiro nome', 'first name', 'firstname', 'primeiro_nome'],
        'email' => ['email', 'e mail', 'mail', 'e-mail', 'correio'],
        'empresa' => ['empresa', 'loja', 'company', 'razao social', 'nome fantasia', 'nome da loja', 'store', 'negocio', 'estabelecimento'],
        'account_id' => ['account id', 'account_id', 'accountid', 'account', 'conta', 'id da conta', 'id conta', 'id_conta'],
    ];

    /** Pedaço de nome de coluna que indica telefone mesmo com mais palavras ("telefone celular", "WhatsApp (com DDD)"). */
    private const TELEFONE_PARCIAL = ['telefone', 'whatsapp', 'celular', 'fone', 'phone'];

    /** Quantas linhas gravadas entre uma atualização dos contadores e outra (progresso no painel). */
    private const PROGRESSO_A_CADA = 200;

    /**
     * Cria a importação a partir de um arquivo (o CSV é copiado para o disco `local`, o original fica com
     * quem chamou) e, se pedido, já enfileira o processamento.
     *
     * @param  array<string, string>  $mapeamento  coluna => campo
     * @param  array<string, mixed>  $opcoes  tipo (csv|optout), contact_list_id, origem, tipo_contato, tags
     *                                        (lista de nomes), consentimento_origem, consentimento_canal,
     *                                        consentimento_detalhes, consentimento_em
     */
    public static function criar(string $caminhoDoArquivo, string $nomeOriginal, array $mapeamento, array $opcoes = [], ?User $user = null, bool $enfileirar = true): ContactImport
    {
        $mapeamento = self::normalizarMapeamento($mapeamento);
        self::exigirMapeamentoValido($mapeamento);

        $tipo = ($opcoes['tipo'] ?? ContactImport::TIPO_CSV) === ContactImport::TIPO_OPTOUT ? ContactImport::TIPO_OPTOUT : ContactImport::TIPO_CSV;
        $origem = (string) ($opcoes['origem'] ?? 'csv');

        if (! in_array($origem, Contact::ORIGENS, true)) {
            throw ValidationException::withMessages(['origem' => ['Origem desconhecida: '.$origem.'.']]);
        }

        $importacao = ContactImport::query()->create([
            'tipo' => $tipo,
            'nome_original' => mb_substr($nomeOriginal, 0, 255),
            'mapeamento' => $mapeamento,
            'contact_list_id' => isset($opcoes['contact_list_id']) ? (int) $opcoes['contact_list_id'] : null,
            'origem' => $origem,
            'tipo_contato' => isset($opcoes['tipo_contato']) && in_array($opcoes['tipo_contato'], Contact::TIPOS, true) ? $opcoes['tipo_contato'] : null,
            'tags' => array_values(array_filter(array_map(fn ($t): string => trim((string) $t), (array) ($opcoes['tags'] ?? [])))),
            'consentimento_origem' => filled($opcoes['consentimento_origem'] ?? null) ? mb_substr((string) $opcoes['consentimento_origem'], 0, 30) : null,
            'parametros' => array_filter([
                'consentimento_canal' => $opcoes['consentimento_canal'] ?? null,
                'consentimento_detalhes' => $opcoes['consentimento_detalhes'] ?? null,
                'consentimento_em' => isset($opcoes['consentimento_em']) ? Carbon::parse($opcoes['consentimento_em'])->toIso8601String() : null,
            ]),
            'status' => ContactImport::STATUS_PENDENTE,
            'user_id' => $user?->id,
        ]);

        $destino = ContactImport::PASTA.'/'.$importacao->id.'/original.csv';
        Storage::disk('local')->put($destino, (string) file_get_contents($caminhoDoArquivo));
        $importacao->forceFill(['arquivo' => $destino])->save();

        if ($enfileirar) {
            ProcessarImportacaoJob::dispatch($importacao->id);
        }

        return $importacao;
    }

    /**
     * Sugestão de mapeamento para o cabeçalho: detecta o telefone (phone, telefone, whatsapp, celular,
     * numero, fone), nome/e-mail/documento/empresa/conta, manda coluna sensível para `documento` ou
     * `ignorar`, e o resto para `atributos.<chave>`.
     *
     * @param  list<string>  $cabecalho
     * @return array<string, string> coluna => campo
     */
    public static function sugerirMapeamento(array $cabecalho): array
    {
        $mapeamento = [];
        $usados = [];
        $colunasTelefone = array_map(self::normalizarNome(...), (array) config('disparo.importacao.colunas_telefone', []));

        // Primeira passada: telefone por nome exato (a coluna "numero" só vale exata - "numero do pedido" não).
        foreach ($cabecalho as $coluna) {
            if (! isset($usados[self::CAMPO_TELEFONE]) && in_array(self::normalizarNome($coluna), $colunasTelefone, true)) {
                $mapeamento[$coluna] = self::CAMPO_TELEFONE;
                $usados[self::CAMPO_TELEFONE] = true;
            }
        }

        foreach ($cabecalho as $coluna) {
            if (isset($mapeamento[$coluna])) {
                continue;
            }

            $nome = self::normalizarNome($coluna);

            if (! isset($usados[self::CAMPO_TELEFONE]) && Str::contains($nome, self::TELEFONE_PARCIAL)) {
                $mapeamento[$coluna] = self::CAMPO_TELEFONE;
                $usados[self::CAMPO_TELEFONE] = true;

                continue;
            }

            if (self::sensivel($nome)) {
                $documento = ! isset($usados['documento']) && Str::contains($nome, ['cpf', 'cnpj', 'documento']);
                $mapeamento[$coluna] = $documento ? 'documento' : self::CAMPO_IGNORAR;
                $usados['documento'] = $usados['documento'] ?? $documento;

                continue;
            }

            $sugerido = null;

            foreach (self::SUGESTOES as $campo => $nomes) {
                if (! isset($usados[$campo]) && in_array($nome, $nomes, true)) {
                    $sugerido = $campo;
                    break;
                }
            }

            if ($sugerido !== null) {
                $mapeamento[$coluna] = $sugerido;
                $usados[$sugerido] = true;

                continue;
            }

            $chave = self::chaveDeAtributo($coluna);
            $mapeamento[$coluna] = $chave === '' ? self::CAMPO_IGNORAR : self::PREFIXO_ATRIBUTO.$chave;
        }

        return $mapeamento;
    }

    /**
     * O que impede o mapeamento de ser usado (lista vazia = ok): sem telefone, telefone ou campo tipado
     * repetido, destino desconhecido, coluna sensível em `atributos`.
     *
     * @param  array<string, string>  $mapeamento
     * @return list<string>
     */
    public static function problemasDoMapeamento(array $mapeamento): array
    {
        $problemas = [];
        $contagem = [];

        foreach (self::normalizarMapeamento($mapeamento) as $coluna => $campo) {
            if ($campo === self::CAMPO_IGNORAR) {
                continue;
            }

            if (str_starts_with($campo, self::PREFIXO_ATRIBUTO)) {
                $chave = substr($campo, strlen(self::PREFIXO_ATRIBUTO));

                if ($chave === '') {
                    $problemas[] = sprintf('A coluna "%s" está mapeada para um atributo sem nome.', $coluna);
                } elseif (self::sensivel(self::normalizarNome($coluna)) || self::sensivel(self::normalizarNome($chave))) {
                    $problemas[] = sprintf('A coluna "%s" contém dado sensível: só pode ir para "documento" ou ser ignorada, nunca para atributos (viram variável de template e ficam em claro).', $coluna);
                }

                continue;
            }

            if (! in_array($campo, self::CAMPOS_TIPADOS, true)) {
                $problemas[] = sprintf('A coluna "%s" aponta para um campo desconhecido ("%s").', $coluna, $campo);

                continue;
            }

            $contagem[$campo] = ($contagem[$campo] ?? 0) + 1;
        }

        if (($contagem[self::CAMPO_TELEFONE] ?? 0) === 0) {
            $problemas[] = 'Nenhuma coluna foi mapeada como telefone.';
        }

        foreach ($contagem as $campo => $vezes) {
            if ($vezes > 1) {
                $problemas[] = sprintf('O campo "%s" foi mapeado em %d colunas; só pode ser uma.', $campo, $vezes);
            }
        }

        return $problemas;
    }

    /** @param  array<string, string>  $mapeamento */
    public static function exigirMapeamentoValido(array $mapeamento): void
    {
        $problemas = self::problemasDoMapeamento($mapeamento);

        if ($problemas !== []) {
            throw ValidationException::withMessages(['mapeamento' => $problemas]);
        }
    }

    /**
     * Prévia antes de enfileirar: as primeiras linhas já mapeadas e normalizadas, mais a contagem prevista
     * do arquivo inteiro (criados, atualizados, inválidos, duplicados, sem consentimento). Não grava nada.
     * `telefone` vem em E.164: o painel mostra mascarado a quem não tem `view pii`.
     *
     * @param  array<string, string>  $mapeamento
     * @param  array<string, mixed>  $opcoes  tipo, contact_list_id, origem, consentimento_origem
     * @return array{linhas: list<array<string, mixed>>, previsao: array<string, int>}
     */
    public function previa(string $caminho, array $mapeamento, array $opcoes = []): array
    {
        $mapeamento = self::normalizarMapeamento($mapeamento);
        self::exigirMapeamentoValido($mapeamento);

        $limite = max(1, (int) config('disparo.importacao.previa_linhas', 10));
        $optout = ($opcoes['tipo'] ?? null) === ContactImport::TIPO_OPTOUT;
        $exigeConsentimento = $this->exigeConsentimento(isset($opcoes['contact_list_id']) ? (int) $opcoes['contact_list_id'] : null, (string) ($opcoes['origem'] ?? 'csv'));
        $comConsentimento = filled($opcoes['consentimento_origem'] ?? null);

        $previsao = ['total' => 0, 'validos' => 0, 'criados' => 0, 'atualizados' => 0, 'invalidos' => 0, 'duplicados' => 0, 'ignorados' => 0, 'optouts' => 0, 'bloqueados' => 0, 'sem_consentimento' => 0];
        $linhas = [];
        $vistos = [];
        $lote = [];

        $fecharLote = function () use (&$lote, &$previsao, $optout, $exigeConsentimento, $comConsentimento): void {
            if ($lote === []) {
                return;
            }

            $existentes = Contact::query()->whereIn('telefone', $lote)->get(['telefone', 'status', 'consentimento_origem'])->keyBy('telefone');
            $bloqueados = Suppression::query()->vigentes()->whereNull('sender_id')->where('escopo', Suppression::ESCOPO_TUDO)->whereIn('telefone', $lote)->pluck('telefone')->flip();

            foreach ($lote as $telefone) {
                if ($optout) {
                    $previsao['optouts']++;

                    continue;
                }

                $existente = $existentes->get($telefone);
                $existente !== null ? $previsao['atualizados']++ : $previsao['criados']++;

                if (($existente !== null && $existente->status !== Contact::STATUS_ATIVO) || isset($bloqueados[$telefone])) {
                    $previsao['bloqueados']++;
                }

                if ($exigeConsentimento && ! $comConsentimento && blank($existente?->consentimento_origem)) {
                    $previsao['sem_consentimento']++;
                }
            }

            $lote = [];
        };

        foreach (LeitorDeCsv::linhas($caminho) as $numero => $registro) {
            $previsao['total']++;
            $dados = self::mapear($registro, $mapeamento);
            $motivo = null;

            if ($dados['telefone'] === null) {
                $motivo = $dados['telefone_original'] === '' ? 'sem telefone' : 'telefone inválido';
                $dados['telefone_original'] === '' ? $previsao['ignorados']++ : $previsao['invalidos']++;
            } elseif (isset($vistos[$dados['telefone']])) {
                $motivo = 'telefone repetido no arquivo (linha '.$vistos[$dados['telefone']].')';
                $previsao['duplicados']++;
            } else {
                $vistos[$dados['telefone']] = $numero;
                $previsao['validos']++;
                $lote[] = $dados['telefone'];

                if (count($lote) >= 500) {
                    $fecharLote();
                }
            }

            if (count($linhas) < $limite) {
                $linhas[] = [
                    'linha' => $numero,
                    'telefone' => $dados['telefone'],
                    'telefone_mascarado' => $dados['telefone'] !== null ? Telefone::mascarar($dados['telefone']) : null,
                    'telefone_original' => $dados['telefone_original'],
                    'nome' => $dados['nome'],
                    'primeiro_nome' => $dados['primeiro_nome'] ?? ($dados['nome'] !== null ? Contact::primeiroNomeDe($dados['nome']) : null),
                    'email' => $dados['email'],
                    'documento' => $dados['documento'] !== null ? Mask::documento($dados['documento']) : null,
                    'empresa' => $dados['empresa'],
                    'account_id' => $dados['account_id'],
                    'atributos' => $dados['atributos'],
                    'motivo' => $motivo,
                ];
            }
        }

        $fecharLote();

        return ['linhas' => $linhas, 'previsao' => $previsao];
    }

    /** Roda a importação (chamado pelo job; em teste e no comando, de forma síncrona). */
    public function processar(ContactImport $importacao): ContactImport
    {
        if ($importacao->status === ContactImport::STATUS_CONCLUIDA) {
            return $importacao;
        }

        $importacao->forceFill(['status' => ContactImport::STATUS_PROCESSANDO, 'iniciado_em' => now(), 'erros' => null, 'mensagem' => null])->save();
        $rejeitadas = new Rejeitadas($importacao);

        try {
            $mapeamento = self::normalizarMapeamento((array) ($importacao->mapeamento ?? []));
            self::exigirMapeamentoValido($mapeamento);

            if (blank($importacao->arquivo) || ! Storage::disk('local')->exists((string) $importacao->arquivo)) {
                throw new \RuntimeException('O arquivo da importação não está mais no servidor.');
            }

            $caminho = Storage::disk('local')->path((string) $importacao->arquivo);
            $optout = $importacao->tipo === ContactImport::TIPO_OPTOUT;
            $lista = $importacao->contact_list_id !== null ? ContactList::query()->find($importacao->contact_list_id) : null;
            $tags = $optout ? [] : array_map(fn (string $nome): Tag => Tag::query()->firstOrCreate(['nome' => mb_substr($nome, 0, 60)]), (array) ($importacao->tags ?? []));
            $exigeConsentimento = $this->exigeConsentimento($lista?->id, (string) $importacao->origem);
            $contagem = ['total_linhas' => 0, 'criados' => 0, 'atualizados' => 0, 'ignorados' => 0, 'invalidos' => 0, 'duplicados' => 0, 'optouts' => 0, 'sem_consentimento' => 0];
            $extra = ['bloqueados' => 0, 'documentos_invalidos' => 0, 'emails_invalidos' => 0];
            $vistos = [];
            $desdeOProgresso = 0;

            foreach (LeitorDeCsv::linhas($caminho) as $numero => $registro) {
                $contagem['total_linhas']++;
                $dados = self::mapear($registro, $mapeamento);

                if ($dados['telefone'] === null) {
                    if ($dados['telefone_original'] === '') {
                        $contagem['ignorados']++;
                        $rejeitadas->registrar($numero, null, 'sem telefone');
                    } else {
                        $contagem['invalidos']++;
                        $rejeitadas->registrar($numero, $dados['telefone_original'], 'telefone inválido');
                    }

                    continue;
                }

                if (isset($vistos[$dados['telefone']])) {
                    $contagem['duplicados']++;
                    $rejeitadas->registrar($numero, $dados['telefone'], 'telefone repetido no arquivo (linha '.$vistos[$dados['telefone']].')');

                    continue;
                }

                $vistos[$dados['telefone']] = $numero;

                if ($optout) {
                    Suppression::suprimir($dados['telefone'], Suppression::MOTIVO_IMPORTACAO, 'csv', null, Suppression::ESCOPO_TUDO, null, 'lista de opt-out importada (#'.$importacao->id.')', $importacao->user_id);
                    $contagem['optouts']++;
                } else {
                    $resultado = DB::transaction(fn (): array => $this->gravar($dados, $importacao, $lista, $tags, $exigeConsentimento));
                    $contagem[$resultado['novo'] ? 'criados' : 'atualizados']++;
                    $contagem['sem_consentimento'] += $resultado['sem_consentimento'] ? 1 : 0;
                    $extra['bloqueados'] += $resultado['bloqueado'] ? 1 : 0;
                    $extra['documentos_invalidos'] += $resultado['documento_invalido'] ? 1 : 0;
                    $extra['emails_invalidos'] += $resultado['email_invalido'] ? 1 : 0;
                }

                if (++$desdeOProgresso >= self::PROGRESSO_A_CADA) {
                    $importacao->forceFill($contagem)->save();
                    $desdeOProgresso = 0;
                }
            }

            $extra['rejeitadas'] = $rejeitadas->fechar();
            $this->apagarOriginal($importacao);

            $importacao->forceFill($contagem + [
                'status' => ContactImport::STATUS_CONCLUIDA,
                'concluido_em' => now(),
                'resultado' => $extra,
            ])->save();
        } catch (Throwable $e) {
            $rejeitadas->fechar();
            $mensagem = $e instanceof ValidationException ? implode(' ', collect($e->errors())->flatten()->all()) : Mask::scrub($e->getMessage());
            $importacao->forceFill(['status' => ContactImport::STATUS_FALHOU, 'concluido_em' => now(), 'mensagem' => mb_substr($mensagem, 0, 1000)])->save();
            Log::warning('Importação de CSV falhou', ['importacao' => $importacao->id, 'erro' => mb_substr($mensagem, 0, 300)]);

            if (! $e instanceof ValidationException) {
                $this->notificar($importacao);

                throw $e;
            }
        }

        $this->notificar($importacao);

        return $importacao;
    }

    /**
     * Grava (ou atualiza) um contato a partir de uma linha já mapeada.
     *
     * @param  array<string, mixed>  $dados
     * @param  list<Tag>  $tags
     * @return array{novo: bool, bloqueado: bool, sem_consentimento: bool, documento_invalido: bool, email_invalido: bool}
     */
    private function gravar(array $dados, ContactImport $importacao, ?ContactList $lista, array $tags, bool $exigeConsentimento): array
    {
        $origem = (string) ($importacao->origem ?: 'csv');
        $contato = Contact::query()->where('telefone', $dados['telefone'])->first();
        $novo = $contato === null;

        if ($novo) {
            $contato = new Contact([
                'telefone' => $dados['telefone'],
                'origem' => $origem,
                'tipo' => $importacao->tipo_contato ?: 'lead',
                'status' => Contact::STATUS_ATIVO,
                'atributos' => [],
            ]);
        } elseif (filled($importacao->tipo_contato)) {
            // A origem é a da primeira vez que o telefone apareceu; o tipo é o que a importação diz dele.
            $contato->tipo = $importacao->tipo_contato;
        }

        // O nome vem de uma planilha, seja ela rotulada csv, unicodrop ou unicopag: precedência de planilha
        // (abaixo do cadastro do unico-api, acima do perfil do WhatsApp).
        $contato->atualizarNome($dados['nome'], 'csv');

        if (filled($dados['primeiro_nome'])) {
            $contato->primeiro_nome = Contact::primeiroNomeDe((string) $dados['primeiro_nome']) ?? $contato->primeiro_nome;
        }

        $emailInvalido = false;

        if (filled($dados['email'])) {
            $email = mb_strtolower(trim((string) $dados['email']));
            filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $contato->email = mb_substr($email, 0, 255) : $emailInvalido = true;
        }

        $documentoInvalido = false;

        if (filled($dados['documento'])) {
            $digitos = preg_replace('/\D/', '', (string) $dados['documento']) ?? '';
            in_array(strlen($digitos), [11, 14], true) ? $contato->documento = $digitos : $documentoInvalido = true;
        }

        if (filled($dados['empresa'])) {
            $contato->empresa = mb_substr(Contact::nomeHigienizado((string) $dados['empresa']) ?? '', 0, 255) ?: $contato->empresa;
        }

        if (filled($dados['account_id'])) {
            $contato->account_id = mb_substr(trim((string) $dados['account_id']), 0, 64);
        }

        if ($dados['atributos'] !== []) {
            $contato->atributos = array_merge((array) ($contato->atributos ?? []), $dados['atributos']);
        }

        if (blank($contato->consentimento_origem) && filled($importacao->consentimento_origem)) {
            $em = $importacao->parametro('consentimento_em');
            $contato->fill([
                'consentimento_origem' => $importacao->consentimento_origem,
                'consentimento_canal' => mb_substr((string) ($importacao->parametro('consentimento_canal') ?: 'importacao'), 0, 30),
                'consentimento_detalhes' => mb_substr((string) ($importacao->parametro('consentimento_detalhes') ?: 'importação #'.$importacao->id.' ('.$importacao->nome_original.')'), 0, 500),
                'consentimento_em' => filled($em) ? Carbon::parse((string) $em) : now(),
            ]);
        }

        $contato->save();

        // Telefone já bloqueado (pediu para parar antes, anonimizado, lista de opt-out): entra na base
        // como `optout`, para o painel mostrar por que não recebe.
        $bloqueado = ! $contato->ativo();

        if ($novo && ($motivo = Suppression::bloqueia($contato->telefone, null, null)) !== null) {
            $contato->marcarOptout($motivo, 'supressao');
            $bloqueado = true;
        }

        if ($lista !== null) {
            $lista->contacts()->syncWithoutDetaching([$contato->id => ['adicionado_em' => now(), 'contact_import_id' => $importacao->id]]);
        }

        foreach ($tags as $tag) {
            $contato->tags()->syncWithoutDetaching([$tag->id => ['created_at' => now()]]);
        }

        return [
            'novo' => $novo,
            'bloqueado' => $bloqueado,
            'sem_consentimento' => $exigeConsentimento && ! $contato->temConsentimento(),
            'documento_invalido' => $documentoInvalido,
            'email_invalido' => $emailInvalido,
        ];
    }

    /**
     * Uma linha do CSV pelo mapeamento: telefone normalizado (null = inválido), campos tipados e os
     * atributos com chave minúscula.
     *
     * @param  array<string, string>  $registro
     * @param  array<string, string>  $mapeamento
     * @return array{telefone: ?string, telefone_original: string, nome: ?string, primeiro_nome: ?string, email: ?string, documento: ?string, empresa: ?string, account_id: ?string, atributos: array<string, string>}
     */
    public static function mapear(array $registro, array $mapeamento): array
    {
        $dados = ['telefone' => null, 'telefone_original' => '', 'nome' => null, 'primeiro_nome' => null, 'email' => null, 'documento' => null, 'empresa' => null, 'account_id' => null, 'atributos' => []];

        foreach ($mapeamento as $coluna => $campo) {
            $valor = trim((string) ($registro[$coluna] ?? ''));

            if ($campo === self::CAMPO_IGNORAR) {
                continue;
            }

            if ($campo === self::CAMPO_TELEFONE) {
                $dados['telefone_original'] = $valor;
                $dados['telefone'] = Telefone::normalizar($valor);

                continue;
            }

            if (str_starts_with($campo, self::PREFIXO_ATRIBUTO)) {
                $chave = substr($campo, strlen(self::PREFIXO_ATRIBUTO));

                if ($chave !== '' && $valor !== '') {
                    $dados['atributos'][$chave] = mb_substr($valor, 0, 500);
                }

                continue;
            }

            if (in_array($campo, self::CAMPOS_TIPADOS, true) && $valor !== '') {
                $dados[$campo] = $valor;
            }
        }

        return $dados;
    }

    /**
     * Mapeamento com os destinos limpos: campo em minúsculas, chave de atributo normalizada
     * (`atributos.Última Venda` => `atributos.ultima_venda`), coluna sem destino = ignorar.
     *
     * @param  array<string, mixed>  $mapeamento
     * @return array<string, string>
     */
    public static function normalizarMapeamento(array $mapeamento): array
    {
        $limpo = [];

        foreach ($mapeamento as $coluna => $campo) {
            $campo = mb_strtolower(trim((string) $campo));

            if ($campo === '' || $campo === self::CAMPO_IGNORAR) {
                $limpo[(string) $coluna] = self::CAMPO_IGNORAR;

                continue;
            }

            if (str_starts_with($campo, self::PREFIXO_ATRIBUTO)) {
                $limpo[(string) $coluna] = self::PREFIXO_ATRIBUTO.self::chaveDeAtributo(substr($campo, strlen(self::PREFIXO_ATRIBUTO)));

                continue;
            }

            $limpo[(string) $coluna] = $campo;
        }

        return $limpo;
    }

    /** Chave de atributo a partir do nome da coluna: minúscula, sem acento, `_` no lugar de espaço. */
    public static function chaveDeAtributo(string $coluna): string
    {
        return mb_substr(Str::slug(Str::ascii(trim($coluna)), '_'), 0, 60);
    }

    /** Nome de coluna normalizado para comparação: minúsculo, sem acento, sem pontuação, espaços únicos. */
    private static function normalizarNome(string $coluna): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($coluna))));
    }

    /** O nome (normalizado) contém uma das palavras da denylist? */
    private static function sensivel(string $nomeNormalizado): bool
    {
        $compacto = str_replace(' ', '', $nomeNormalizado);

        foreach ((array) config('disparo.importacao.denylist', []) as $palavra) {
            $palavra = self::normalizarNome((string) $palavra);

            if ($palavra !== '' && (str_contains($nomeNormalizado, $palavra) || str_contains($compacto, str_replace(' ', '', $palavra)))) {
                return true;
            }
        }

        return false;
    }

    private function exigeConsentimento(?int $listaId, string $origem): bool
    {
        if (in_array($origem, Contact::ORIGENS_EXTERNAS, true)) {
            return true;
        }

        return $listaId !== null && (bool) ContactList::query()->whereKey($listaId)->value('exige_consentimento');
    }

    private function apagarOriginal(ContactImport $importacao): void
    {
        if (filled($importacao->arquivo)) {
            Storage::disk('local')->delete((string) $importacao->arquivo);
            $importacao->forceFill(['arquivo' => null])->save();
        }
    }

    /** Aviso no sininho de quem pediu a importação (nada de PII: só contagens). */
    private function notificar(ContactImport $importacao): void
    {
        $user = $importacao->solicitante();

        if ($user === null) {
            return;
        }

        try {
            $concluida = $importacao->status === ContactImport::STATUS_CONCLUIDA;
            $notificacao = Notification::make()
                ->title($concluida ? 'Importação concluída' : 'Importação falhou')
                ->body($concluida ? sprintf('%s: %s.', $importacao->nome_original ?: 'arquivo', $importacao->resumo()) : (string) $importacao->mensagem);

            $concluida ? $notificacao->success() : $notificacao->danger();
            $notificacao->sendToDatabase($user);
        } catch (Throwable $e) {
            Log::warning('Não foi possível avisar o usuário da importação', ['importacao' => $importacao->id, 'erro' => $e->getMessage()]);
        }
    }
}
