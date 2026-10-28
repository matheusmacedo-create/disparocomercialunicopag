<?php

declare(strict_types=1);

namespace App\Services\Campanhas;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\MessageTemplate;
use App\Models\Segment;
use App\Models\Sender;
use App\Models\Suppression;
use App\Services\Envio\Guardas;
use App\Services\Envio\MontadorDeComponentes;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Variaveis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Do público da campanha (listas + segmento - exclusões) aos destinatários, com o motivo de quem fica de
 * fora (KB 4.6). A mesma passagem serve à prévia (só conta) e à montagem real (grava os destinatários).
 */
final class MontadorDePublico
{
    /** Contatos por lote, para não carregar a base inteira em memória. */
    private const LOTE = 500;

    /**
     * Prévia: contagens e motivos, sem gravar nada e sem chamar provedor.
     *
     * @return array{elegiveis: int, excluidos: int, total: int, motivos: array<string, int>, amostra: list<array<string, string>>, gerada_em: string}
     */
    public function simular(Campaign $campaign): array
    {
        $resumo = ['elegiveis' => 0, 'excluidos' => 0, 'total' => 0, 'motivos' => [], 'amostra' => [], 'avisos' => [], 'usam_padrao' => 0];
        $sender = $campaign->sender;
        $template = $this->template($campaign);

        $this->percorrer($campaign, function (Contact $contato, ?string $motivo, array $variaveis) use (&$resumo): void {
            $resumo['total']++;

            if ($motivo === null) {
                $resumo['elegiveis']++;

                if (in_array('tudo bem', $variaveis, true)) {
                    $resumo['usam_padrao']++;
                }

                if (count($resumo['amostra']) < 5) {
                    $resumo['amostra'][] = ['contato' => $contato->rotulo(), 'variaveis' => implode(' | ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($variaveis), $variaveis))];
                }
            } else {
                $resumo['excluidos']++;
                $resumo['motivos'][$motivo] = ($resumo['motivos'][$motivo] ?? 0) + 1;
            }
        });

        arsort($resumo['motivos']);

        // O que a aprovação precisa ver (KB 2.2): custo, teto restante e em quantos dias sai.
        $categoria = strtoupper((string) $template?->categoria);
        $tarifa = (float) config('disparo.custo.'.($categoria === Sender::CATEGORIA_MARKETING ? 'marketing' : 'utility'), 0);
        $resumo['categoria'] = $categoria ?: null;
        $resumo['custo_estimado'] = round($resumo['elegiveis'] * $tarifa, 2);
        $resumo['teto_efetivo'] = $sender->tetoEfetivo();
        $resumo['teto_restante_hoje'] = max(0, $sender->tetoEfetivo() - $sender->templatesNasUltimas24h());
        $porDia = $categoria === Sender::CATEGORIA_MARKETING ? max(1, $sender->tetoDeMarketing()) : max(1, (int) floor($sender->tetoEfetivo() * (1 - (float) config('disparo.fila.reserva_reguas', 0.3))));
        $resumo['dias_estimados'] = (int) ceil($resumo['elegiveis'] / $porDia);

        if ($motivoRemetente = Guardas::motivoDoRemetente($sender, $template, true)) {
            $resumo['avisos'][] = 'Remetente ou template não permite enviar agora: '.$motivoRemetente.'.';
        }

        if ($sender->provider === Sender::PROVIDER_KAPSO && ($sender->historico_importado_ate === null || $sender->historico_importado_ate->lt(now()->subDay()))) {
            $resumo['avisos'][] = 'O histórico da Kapso deste número não foi importado nas últimas 24 h: conversas abertas e pedidos para parar recentes podem estar faltando.';
        }

        if ($categoria === Sender::CATEGORIA_MARKETING && $campaign->objetivo === Campaign::OBJETIVO_OFERTAR) {
            $resumo['avisos'][] = 'Campanha MARKETING: a primeira leva é de no máximo '.(int) config('disparo.tranches.primeira_max', 50).' contatos e a campanha espera '.(int) config('disparo.tranches.espera_minutos', 90).' min lendo os webhooks antes de seguir.';
        }

        if (! config('disparo.envio_habilitado')) {
            $resumo['avisos'][] = 'Ambiente em SIMULAÇÃO (DISPARO_ENVIO_HABILITADO=false): a campanha vai rodar inteira sem mandar nada.';
        }

        $resumo['gerada_em'] = now()->toIso8601String();

        return $resumo;
    }

    private function template(Campaign $campaign): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('sender_id', $campaign->sender_id)
            ->where('nome', $campaign->template_nome)
            ->where('idioma', $campaign->template_idioma)
            ->first();
    }

    /**
     * Montagem real: grava um destinatário por contato (`pendente` ou `pulado` com o motivo). Idempotente:
     * contato que já tem linha na campanha não é recriado.
     *
     * @return array{criados: int, pulados: int}
     */
    public function montar(Campaign $campaign): array
    {
        $resultado = ['criados' => 0, 'pulados' => 0];
        $existentes = $campaign->recipients()->pluck('contact_id')->flip()->all();

        $this->percorrer($campaign, function (Contact $contato, ?string $motivo, array $variaveis) use ($campaign, $existentes, &$resultado): void {
            if (isset($existentes[$contato->id])) {
                return;
            }

            CampaignRecipient::query()->create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contato->id,
                'telefone' => $contato->telefone,
                'variaveis' => $variaveis,
                'status' => $motivo === null ? CampaignRecipient::STATUS_PENDENTE : CampaignRecipient::STATUS_PULADO,
                'motivo' => $motivo,
            ]);

            $motivo === null ? $resultado['criados']++ : $resultado['pulados']++;
        });

        $campaign->recontar();

        return $resultado;
    }

    /**
     * A query do público (sem as guardas): listas e/ou segmento e/ou campanha anterior
     * (`campanha_anterior` = {campaign_id, status: [...]}: "quem recebeu A e não respondeu"), menos exclusões.
     */
    public function queryDoPublico(Campaign $campaign): Builder
    {
        $publico = (array) ($campaign->publico ?? []);
        $listas = array_values(array_filter(array_map('intval', (array) ($publico['listas'] ?? []))));
        $segmento = isset($publico['segment_id']) && (int) $publico['segment_id'] > 0 ? Segment::query()->find((int) $publico['segment_id']) : null;
        $anterior = (array) ($publico['campanha_anterior'] ?? []);

        // Sem segmento, a base entra inteira: quem está em opt-out ou inválido aparece na prévia como
        // excluído, com o motivo - o operador vê quanto da base já pediu para parar.
        $query = $segmento !== null
            ? \App\Services\Segmentos\ConsultaDeSegmento::doSegmento($segmento)
            : Contact::query();

        if ($listas !== []) {
            $query->whereHas('lists', fn (Builder $q) => $q->whereIn('contact_lists.id', $listas));
        }

        if ((int) ($anterior['campaign_id'] ?? 0) > 0) {
            $statuses = array_values(array_filter(array_map('strval', (array) ($anterior['status'] ?? []))));
            $query->whereHas('campaignRecipients', fn (Builder $q) => $q
                ->where('campaign_id', (int) $anterior['campaign_id'])
                ->when($statuses !== [], fn (Builder $q) => $q->whereIn('status', $statuses)));
        }

        if (($excluirListas = array_values(array_filter(array_map('intval', (array) ($publico['excluir_listas'] ?? []))))) !== []) {
            $query->whereDoesntHave('lists', fn (Builder $q) => $q->whereIn('contact_lists.id', $excluirListas));
        }

        if (($excluirTags = array_values(array_filter(array_map('intval', (array) ($publico['excluir_tags'] ?? []))))) !== []) {
            $query->whereDoesntHave('tags', fn (Builder $q) => $q->whereIn('tags.id', $excluirTags));
        }

        return $query;
    }

    /** O público tem alguma base que exige consentimento registrado. */
    public function exigeConsentimento(Campaign $campaign): bool
    {
        $listas = array_values(array_filter(array_map('intval', (array) (((array) ($campaign->publico ?? []))['listas'] ?? []))));

        return $listas !== [] && ContactList::query()->whereIn('id', $listas)->where('exige_consentimento', true)->exists();
    }

    /**
     * Percorre o público aplicando as guardas do contato e a montagem das variáveis.
     *
     * @param  callable(Contact, ?string, array<string, string>): void  $cada
     */
    private function percorrer(Campaign $campaign, callable $cada): void
    {
        $sender = $campaign->sender;
        $template = $this->template($campaign);
        $contexto = [
            'regras' => (array) ($campaign->regras ?? []),
            'exige_consentimento' => $this->exigeConsentimento($campaign),
            'objetivo' => $campaign->objetivo,
            'origem' => Guardas::ORIGEM_CAMPANHA,
            // Na montagem, janela e dia da semana não contam: o executor adia, não descarta.
            'simulacao' => true,
        ];
        $bloqueados = Suppression::hashesBloqueados($sender, ($campaign->objetivoComercial() || Guardas::marketing($template)) ? Sender::CATEGORIA_MARKETING : $template?->categoria);
        $vistos = [];

        $this->queryDoPublico($campaign)
            ->with(['lists:id', 'tags:id'])
            ->orderBy('contacts.id')
            ->chunkById(self::LOTE, function ($contatos) use ($cada, $sender, $template, $contexto, $bloqueados, &$vistos): void {
                foreach ($contatos as $contato) {
                    $variaveis = [];
                    $motivo = null;

                    if (isset($vistos[$contato->telefone])) {
                        $motivo = Guardas::DUPLICADO;
                    } elseif (isset($bloqueados[$contato->telefone_hash])) {
                        $vistos[$contato->telefone] = true;
                        $motivo = Guardas::SUPRIMIDO;
                    } else {
                        $vistos[$contato->telefone] = true;
                        $motivo = Guardas::motivoDoContato($contato, $sender, $template, $contexto);
                    }

                    if ($motivo === null && $template !== null) {
                        try {
                            $dados = Variaveis::doContato($contato);
                            $componentes = MontadorDeComponentes::montar($template->definicao(), (array) ($campaign->variaveis ?? []), $dados);
                            $variaveis = self::achatar($componentes);
                        } catch (WhatsAppException $e) {
                            $motivo = $e->codigo === 'variavel_vazia' ? Guardas::VARIAVEL_VAZIA : 'template:'.$e->codigo;
                        }
                    }

                    $cada($contato, $motivo, $variaveis);
                }
            }, 'contacts.id', 'id');
    }

    /**
     * Os valores renderizados, para congelar no destinatário: ["body.1" => "Ana", "button.0" => "x"].
     *
     * @param  list<array<string, mixed>>  $componentes
     * @return array<string, string>
     */
    public static function achatar(array $componentes): array
    {
        $valores = [];

        foreach ($componentes as $componente) {
            $tipo = (string) ($componente['type'] ?? '');

            foreach (array_values((array) ($componente['parameters'] ?? [])) as $i => $parametro) {
                $chave = $parametro['parameter_name'] ?? ($tipo.'.'.($tipo === 'button' ? ($componente['index'] ?? '0') : (string) ($i + 1)));
                $valores[$chave] = (string) ($parametro['text'] ?? ($parametro['image']['link'] ?? $parametro['document']['link'] ?? $parametro['video']['link'] ?? ''));
            }
        }

        return $valores;
    }
}
