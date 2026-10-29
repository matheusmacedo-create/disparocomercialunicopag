<?php

declare(strict_types=1);

namespace App\Services\Segmentos;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Regras JSON de um segmento → query de contatos (KB 4.3). Única tradução para SQL.
 *
 * Regras aceitas:
 *  - listas, excluir_listas: ids de contact_lists;
 *  - tags, excluir_tags: ids de tags;
 *  - origens, tipos: valores de contacts.origem / contacts.tipo;
 *  - status: valores de contacts.status (padrão ["ativo"]);
 *  - sem_envio_ha_dias: nenhum envio (ultimo_envio_em) nos últimos N dias (ou nunca);
 *  - sem_resposta_ha_dias: nenhuma resposta nos últimos N dias (ou nunca);
 *  - respondeu_ha_dias: respondeu nos últimos N dias;
 *  - criado_apos, criado_antes: data (Y-m-d);
 *  - sem_whatsapp: false = exclui quem está em quarentena 131026 (padrão true = não filtra);
 *  - atributos: [{campo, operador (=|!=|contem|vazio|nao_vazio), valor}] sobre atributos JSON;
 *  - recebeu_template, nao_recebeu_template: {nome, dias} - template outbound ao telefone (qualquer
 *    remetente, qualquer origem, inclusive histórico da Kapso) que a Meta não disse ter falhado, nos
 *    últimos N dias (dias vazio = desde sempre);
 *  - campanha: {campaign_id, status: [...]} - esteve na campanha com um destes status (vazio = qualquer).
 */
final class ConsultaDeSegmento
{
    /** @param  array<string, mixed>  $regras */
    public static function query(array $regras): Builder
    {
        $query = Contact::query();

        $status = self::lista($regras['status'] ?? null) ?: [Contact::STATUS_ATIVO];
        $query->whereIn('status', $status);

        if (($listas = self::ids($regras['listas'] ?? null)) !== []) {
            $query->whereHas('lists', fn (Builder $q) => $q->whereIn('contact_lists.id', $listas));
        }

        if (($excluirListas = self::ids($regras['excluir_listas'] ?? null)) !== []) {
            $query->whereDoesntHave('lists', fn (Builder $q) => $q->whereIn('contact_lists.id', $excluirListas));
        }

        if (($tags = self::ids($regras['tags'] ?? null)) !== []) {
            $query->whereHas('tags', fn (Builder $q) => $q->whereIn('tags.id', $tags));
        }

        if (($excluirTags = self::ids($regras['excluir_tags'] ?? null)) !== []) {
            $query->whereDoesntHave('tags', fn (Builder $q) => $q->whereIn('tags.id', $excluirTags));
        }

        if (($origens = self::lista($regras['origens'] ?? null)) !== []) {
            $query->whereIn('origem', $origens);
        }

        if (($tipos = self::lista($regras['tipos'] ?? null)) !== []) {
            $query->whereIn('tipo', $tipos);
        }

        if (($dias = self::inteiro($regras['sem_envio_ha_dias'] ?? null)) !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('ultimo_envio_em')->orWhere('ultimo_envio_em', '<', now()->subDays($dias)));
        }

        if (($dias = self::inteiro($regras['sem_resposta_ha_dias'] ?? null)) !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('ultima_resposta_em')->orWhere('ultima_resposta_em', '<', now()->subDays($dias)));
        }

        if (($dias = self::inteiro($regras['respondeu_ha_dias'] ?? null)) !== null) {
            $query->where('ultima_resposta_em', '>=', now()->subDays($dias));
        }

        if (filled($regras['criado_apos'] ?? null)) {
            $query->whereDate('created_at', '>=', (string) $regras['criado_apos']);
        }

        if (filled($regras['criado_antes'] ?? null)) {
            $query->whereDate('created_at', '<=', (string) $regras['criado_antes']);
        }

        if (($regras['sem_whatsapp'] ?? true) === false) {
            $query->where(fn (Builder $q) => $q->whereNull('sem_whatsapp_em')->orWhere('sem_whatsapp_em', '<', now()->subDays((int) config('disparo.guardas.sem_whatsapp_dias', 90))));
        }

        foreach ((array) ($regras['atributos'] ?? []) as $filtro) {
            if (! is_array($filtro) || blank($filtro['campo'] ?? null)) {
                continue;
            }

            $campo = 'atributos->'.preg_replace('/[^a-z0-9_.\-]/i', '', (string) $filtro['campo']);
            $valor = (string) ($filtro['valor'] ?? '');

            match ($filtro['operador'] ?? '=') {
                '!=' => $query->where(fn (Builder $q) => $q->whereNull($campo)->orWhere($campo, '!=', $valor)),
                'contem' => $query->where($campo, 'like', '%'.$valor.'%'),
                'vazio' => $query->where(fn (Builder $q) => $q->whereNull($campo)->orWhere($campo, '')),
                'nao_vazio' => $query->whereNotNull($campo)->where($campo, '!=', ''),
                default => $query->where($campo, $valor),
            };
        }

        $recebeu = (array) ($regras['recebeu_template'] ?? []);

        if (filled($recebeu['nome'] ?? null)) {
            $query->whereExists(fn (QueryBuilder $q) => self::templatesAoTelefone($q, (string) $recebeu['nome'], self::inteiro($recebeu['dias'] ?? null)));
        }

        $naoRecebeu = (array) ($regras['nao_recebeu_template'] ?? []);

        if (filled($naoRecebeu['nome'] ?? null)) {
            $query->whereNotExists(fn (QueryBuilder $q) => self::templatesAoTelefone($q, (string) $naoRecebeu['nome'], self::inteiro($naoRecebeu['dias'] ?? null)));
        }

        $campanha = (array) ($regras['campanha'] ?? []);

        if ((int) ($campanha['campaign_id'] ?? 0) > 0) {
            $status = self::lista($campanha['status'] ?? null);

            $query->whereHas('campaignRecipients', fn (Builder $q) => $q
                ->where('campaign_id', (int) $campanha['campaign_id'])
                ->when($status !== [], fn (Builder $q) => $q->whereIn('status', $status)));
        }

        return $query;
    }

    /**
     * Templates que saíram ao telefone do contato (por telefone, não por contact_id: o histórico da Kapso
     * e o que o webhook viu sair por fora podem não ter o vínculo). Colunas qualificadas: `status` e
     * `tipo` existem nas duas tabelas.
     */
    private static function templatesAoTelefone(QueryBuilder $q, string $nome, ?int $dias): QueryBuilder
    {
        return $q->selectRaw('1')
            ->from('whatsapp_messages')
            ->whereColumn('whatsapp_messages.telefone', 'contacts.telefone')
            ->where('whatsapp_messages.direcao', WhatsappMessage::OUTBOUND)
            ->where('whatsapp_messages.tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('whatsapp_messages.template_nome', $nome)
            ->where('whatsapp_messages.status', '!=', WhatsappMessage::STATUS_FAILED)
            ->when($dias !== null, fn (QueryBuilder $q) => $q->where('whatsapp_messages.enviado_em', '>=', now()->subDays($dias)));
    }

    public static function doSegmento(Segment $segment): Builder
    {
        return self::query((array) $segment->regras);
    }

    /** @return list<string> */
    private static function lista(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = array_map('trim', explode(',', $valor));
        }

        return is_array($valor) ? array_values(array_filter(array_map(fn ($v): string => trim((string) $v), $valor), fn (string $v): bool => $v !== '')) : [];
    }

    /** @return list<int> */
    private static function ids(mixed $valor): array
    {
        return array_values(array_filter(array_map('intval', self::lista($valor)), fn (int $v): bool => $v > 0));
    }

    private static function inteiro(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return is_numeric($valor) && (int) $valor >= 0 ? (int) $valor : null;
    }
}
