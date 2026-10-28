<?php

declare(strict_types=1);

namespace App\Services\Segmentos;

use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Builder;

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
 *  - atributos: [{campo, operador (=|!=|contem|vazio|nao_vazio), valor}] sobre atributos JSON.
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

        return $query;
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
