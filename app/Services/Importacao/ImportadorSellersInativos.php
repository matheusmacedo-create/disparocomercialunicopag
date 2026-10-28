<?php

declare(strict_types=1);

namespace App\Services\Importacao;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactList;
use App\Models\JourneyEnrollment;
use App\Models\Tag;
use App\Services\Unicopag\UnicopagClient;
use App\Support\Mask;
use App\Support\Telefone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sellers inativos (KB 9): a lista do unico-api (endpoint ou arquivo JSON com o mesmo contrato) vira a
 * base "Sellers inativos" - e a base é VIVA: quem voltou a vender sai dela, vira `seller_ativo`, ganha a
 * tag `voltou_a_vender`, a inscrição ativa na régua encerra com motivo `converteu` e os destinatários de
 * campanha com envio nos 30 dias antes da venda ganham `convertido_em` (achado de 28/10: a régua seguia
 * cobrando quem já tinha voltado, e nada media conversão).
 *
 * Contrato de cada item: account_id (obrigatório), telefone?, nome?, email?, ultima_venda_em?,
 * dias_sem_venda?, etapa?, aprovado_em?. Sem telefone no item, o cadastro da conta é consultado; sem
 * telefone em lugar nenhum a conta é REJEITADA (erros + rejeitadas.csv, com o account_id para o comercial
 * completar) - nunca vira contato (`contacts.telefone` é obrigatório e único).
 */
final class ImportadorSellersInativos
{
    public const LISTA_NOME = 'Sellers inativos';

    public const LISTA_SLUG = 'sellers-inativos';

    public const TAG_VOLTOU_A_VENDER = 'voltou_a_vender';

    public const ETAPAS_DE_VENDA = ['vendeu', 'vendendo', 'ativo', 'ativa', 'voltou_a_vender'];

    public function __construct(private readonly UnicopagClient $unicopag) {}

    /** A lista do endpoint configurado (`UNICOPAG_SELLERS_ENDPOINT`). */
    public function doEndpoint(int $dias = 90, ?int $userId = null): ContactImport
    {
        return $this->importar($this->unicopag->sellersInativos($dias), $dias, $userId, ['fonte' => 'endpoint']);
    }

    /** A lista de um arquivo JSON (lista, ou objeto com `data`/`sellers`/`accounts`). */
    public function deArquivo(string $caminho, int $dias = 90, ?int $userId = null): ContactImport
    {
        $conteudo = @file_get_contents($caminho);

        if ($conteudo === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo de sellers.');
        }

        $json = json_decode($conteudo, true);

        if (! is_array($json)) {
            throw new \RuntimeException('O arquivo de sellers não é um JSON válido.');
        }

        return $this->importar(UnicopagClient::listaDe($json), $dias, $userId, ['fonte' => 'arquivo', 'nome_original' => basename($caminho)]);
    }

    /**
     * @param  list<array<string, mixed>>  $sellers
     * @param  array<string, mixed>  $parametros
     */
    public function importar(array $sellers, int $dias = 90, ?int $userId = null, array $parametros = []): ContactImport
    {
        $importacao = ContactImport::query()->create([
            'tipo' => ContactImport::TIPO_UNICOAPI,
            'nome_original' => $parametros['nome_original'] ?? null,
            'origem' => 'unicopag',
            'tipo_contato' => 'seller_inativo',
            'consentimento_origem' => 'cliente_ativo',
            'parametros' => ['dias' => $dias] + $parametros,
            'status' => ContactImport::STATUS_PROCESSANDO,
            'user_id' => $userId,
            'iniciado_em' => now(),
        ]);
        $importacao->forceFill(['contact_list_id' => self::lista()->id])->save();

        $rejeitadas = new Rejeitadas($importacao);
        $contagem = ['total_linhas' => 0, 'criados' => 0, 'atualizados' => 0, 'ignorados' => 0, 'invalidos' => 0, 'duplicados' => 0];
        $extra = ['convertidos' => 0, 'inscricoes_encerradas' => 0, 'destinatarios_convertidos' => 0, 'telefone_do_cadastro' => 0, 'sem_telefone' => 0, 'sem_account_id' => 0, 'rejeitadas' => 0];
        $lista = self::lista();
        $vistos = [];

        try {
            foreach (array_values($sellers) as $indice => $seller) {
                $linha = $indice + 1;
                $contagem['total_linhas']++;
                $accountId = trim((string) ($seller['account_id'] ?? $seller['id'] ?? ''));

                if ($accountId === '') {
                    $contagem['ignorados']++;
                    $extra['sem_account_id']++;
                    $rejeitadas->registrar($linha, null, 'sem account_id');

                    continue;
                }

                if (isset($vistos[$accountId])) {
                    $contagem['duplicados']++;
                    $rejeitadas->registrar($linha, null, 'conta repetida na lista', $accountId);

                    continue;
                }

                $vistos[$accountId] = true;

                $telefone = Telefone::normalizar(self::texto($seller, ['telefone', 'phone', 'whatsapp', 'celular', 'phone_number']));
                $nome = self::texto($seller, ['nome', 'name', 'primeiro_nome', 'first_name']);
                $empresa = self::texto($seller, ['empresa', 'loja', 'trade_name', 'nome_fantasia', 'store_name']);

                if ($telefone === null && $this->unicopag->cadastroConfigurado()) {
                    $cadastro = $this->unicopag->cadastro($accountId);
                    $telefone = $cadastro['telefone'];
                    $nome = $nome ?? $cadastro['nome'];
                    $empresa = $empresa ?? $cadastro['empresa'];

                    if ($telefone !== null) {
                        $extra['telefone_do_cadastro']++;
                    }
                }

                if ($telefone === null) {
                    $contagem['invalidos']++;
                    $extra['sem_telefone']++;
                    $rejeitadas->registrar($linha, self::texto($seller, ['telefone', 'phone', 'whatsapp', 'celular', 'phone_number']), 'sem telefone válido (nem no cadastro)', $accountId);

                    continue;
                }

                $resultado = DB::transaction(fn (): array => $this->gravar($seller, $accountId, $telefone, $nome, $empresa, $dias, $importacao, $lista));
                $contagem[$resultado['novo'] ? 'criados' : 'atualizados']++;
                $extra['convertidos'] += $resultado['converteu'] ? 1 : 0;
                $extra['inscricoes_encerradas'] += $resultado['inscricoes'];
                $extra['destinatarios_convertidos'] += $resultado['destinatarios'];
            }

            $extra['rejeitadas'] = $rejeitadas->fechar();

            $importacao->forceFill($contagem + ['status' => ContactImport::STATUS_CONCLUIDA, 'concluido_em' => now(), 'resultado' => $extra])->save();
        } catch (Throwable $e) {
            $rejeitadas->fechar();
            $importacao->forceFill($contagem + ['status' => ContactImport::STATUS_FALHOU, 'concluido_em' => now(), 'mensagem' => mb_substr(Mask::scrub($e->getMessage()), 0, 1000), 'resultado' => $extra])->save();
            Log::warning('Importação de sellers inativos falhou', ['importacao' => $importacao->id, 'erro' => mb_substr(Mask::scrub($e->getMessage()), 0, 300)]);

            throw $e;
        }

        return $importacao;
    }

    /** A base "Sellers inativos" (criada se faltar). */
    public static function lista(): ContactList
    {
        return ContactList::query()->firstOrCreate(
            ['slug' => self::LISTA_SLUG],
            ['nome' => self::LISTA_NOME, 'descricao' => 'Contas aprovadas sem venda recente (sincronizada com o unico-api). Quem volta a vender sai sozinho.', 'dono' => ContactList::DONO_UNICOPAG, 'exige_consentimento' => false, 'ativa' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $seller
     * @return array{novo: bool, converteu: bool, inscricoes: int, destinatarios: int}
     */
    private function gravar(array $seller, string $accountId, string $telefone, ?string $nome, ?string $empresa, int $dias, ContactImport $importacao, ContactList $lista): array
    {
        $ultimaVenda = self::momento($seller, ['ultima_venda_em', 'last_sale_at', 'ultima_venda']);
        $aprovadoEm = self::momento($seller, ['aprovado_em', 'approved_at', 'data_cadastro', 'created_at']);
        $etapa = self::texto($seller, ['etapa', 'stage', 'status']);
        $diasSemVenda = self::texto($seller, ['dias_sem_venda', 'days_without_sale']);
        $voltouAVender = ($etapa !== null && in_array(mb_strtolower($etapa), self::ETAPAS_DE_VENDA, true))
            || ($ultimaVenda !== null && $ultimaVenda->gt(now()->subDays($dias)));

        $contato = Contact::query()->where('telefone', $telefone)->first();
        $novo = $contato === null;

        if ($novo) {
            $contato = new Contact([
                'telefone' => $telefone,
                'origem' => 'unicopag',
                'tipo' => 'seller_inativo',
                'status' => Contact::STATUS_ATIVO,
                'atributos' => [],
            ]);
        }

        if ($contato->anonimizado()) {
            // Direito de exclusão exercido: a conta não volta a ser vinculada à pessoa.
            return ['novo' => false, 'converteu' => false, 'inscricoes' => 0, 'destinatarios' => 0];
        }

        $contato->atualizarNome($nome, 'unicoapi');
        $contato->account_id = mb_substr($accountId, 0, 64);
        $contato->tipo = $voltouAVender ? 'seller_ativo' : 'seller_inativo';

        if (filled($empresa)) {
            $contato->empresa = mb_substr((string) Contact::nomeHigienizado($empresa), 0, 255);
        }

        if (blank($contato->email) && filled($email = self::texto($seller, ['email'])) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contato->email = mb_substr(mb_strtolower((string) $email), 0, 255);
        }

        // As variáveis que o template de recuperação precisa (dado específico da conta: sem ele a Meta
        // reclassifica o template como MARKETING).
        $contato->atributos = array_merge((array) ($contato->atributos ?? []), array_filter([
            'ultima_venda_em' => $ultimaVenda?->format('d/m/Y'),
            'dias_sem_venda' => $diasSemVenda ?? ($ultimaVenda !== null ? (string) (int) $ultimaVenda->diffInDays(now()) : null),
            'etapa' => $etapa,
            'data_cadastro' => $aprovadoEm?->format('d/m/Y'),
        ], fn ($v): bool => $v !== null && $v !== ''));

        if (blank($contato->consentimento_origem)) {
            $contato->fill([
                'consentimento_origem' => 'cliente_ativo',
                'consentimento_em' => $aprovadoEm ?? now(),
                'consentimento_canal' => 'unicoapi',
                'consentimento_detalhes' => 'conta '.$accountId.' aprovada na ÚnicoPag (relação contratual)',
            ]);
        }

        $contato->save();

        $tag = Tag::query()->firstOrCreate(['nome' => self::TAG_VOLTOU_A_VENDER]);

        if (! $voltouAVender) {
            $lista->contacts()->syncWithoutDetaching([$contato->id => ['adicionado_em' => now(), 'contact_import_id' => $importacao->id]]);
            $contato->tags()->detach($tag->id);

            return ['novo' => $novo, 'converteu' => false, 'inscricoes' => 0, 'destinatarios' => 0];
        }

        return ['novo' => $novo, 'converteu' => true] + $this->converter($contato, $ultimaVenda ?? CarbonImmutable::now(), $lista, $tag);
    }

    /**
     * Seller que voltou a vender: sai da base, ganha a tag, a inscrição ativa na régua encerra como
     * `converteu` e o destinatário de campanha com envio na janela antes da venda vira conversão.
     *
     * @return array{inscricoes: int, destinatarios: int}
     */
    private function converter(Contact $contato, CarbonImmutable $venda, ContactList $lista, Tag $tag): array
    {
        $lista->contacts()->detach($contato->id);
        $contato->tags()->syncWithoutDetaching([$tag->id => ['created_at' => now()]]);

        $inscricoes = 0;

        foreach (JourneyEnrollment::query()->with('journey')->where('contact_id', $contato->id)->where('status', JourneyEnrollment::STATUS_ATIVA)->get() as $inscricao) {
            $inscricao->forceFill(['convertido_em' => $venda, 'conversao_origem' => 'venda'])->save();

            if ($inscricao->journey === null || $inscricao->journey->sair_ao_converter) {
                $inscricao->encerrar(JourneyEnrollment::STATUS_SAIU, 'converteu');
                $inscricoes++;
            }
        }

        $janela = max(1, (int) config('disparo.importacao.conversao_janela_dias', 30));
        $destinatarios = 0;

        $query = CampaignRecipient::query()
            ->where('contact_id', $contato->id)
            ->whereIn('status', CampaignRecipient::STATUS_ENVIADOS)
            ->whereNull('convertido_em')
            ->whereBetween('enviado_em', [$venda->subDays($janela), $venda]);

        foreach ($query->get() as $destinatario) {
            $destinatario->forceFill(['convertido_em' => $venda, 'conversao_origem' => 'venda'])->save();
            $destinatarios++;
        }

        return ['inscricoes' => $inscricoes, 'destinatarios' => $destinatarios];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $chaves
     */
    private static function texto(array $item, array $chaves): ?string
    {
        foreach ($chaves as $chave) {
            $valor = $item[$chave] ?? null;

            if ((is_string($valor) || is_int($valor)) && trim((string) $valor) !== '') {
                return trim((string) $valor);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $chaves
     */
    private static function momento(array $item, array $chaves): ?CarbonImmutable
    {
        $valor = self::texto($item, $chaves);

        if ($valor === null) {
            return null;
        }

        try {
            // No fuso da aplicação (ISO com "Z" entraria deslocado no banco).
            return CarbonImmutable::parse($valor)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    /** Apaga o arquivo que a importação guardou (nunca houve: a lista vem em memória), por simetria. */
    public static function limparArquivo(ContactImport $importacao): void
    {
        if (filled($importacao->arquivo)) {
            Storage::disk('local')->delete((string) $importacao->arquivo);
            $importacao->forceFill(['arquivo' => null])->save();
        }
    }
}
