<?php

namespace App\Filament\Widgets;

use App\Models\Sender;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Remetente Kapso cujo histórico não foi importado nas últimas 24 h: conversas abertas e pedidos para
 * parar recentes podem estar faltando nas guardas (KB 2.7).
 */
class HistoricoDesatualizadoWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.historico-desatualizado';

    public static function canView(): bool
    {
        return auth()->check() && self::desatualizados()->isNotEmpty();
    }

    /** @return Collection<int, Sender> */
    public static function desatualizados(): Collection
    {
        return Sender::query()
            ->where('provider', Sender::PROVIDER_KAPSO)
            ->where('ativo', true)
            ->where(fn ($q) => $q->whereNull('historico_importado_ate')->orWhere('historico_importado_ate', '<', now()->subDay()))
            ->orderBy('nome')
            ->get();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['remetentes' => self::desatualizados()];
    }
}
