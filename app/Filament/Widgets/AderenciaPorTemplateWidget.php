<?php

namespace App\Filament\Widgets;

use App\Services\Metricas\AderenciaDeTemplates;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Ranking dos templates que mais tiveram resposta do contato nos últimos 90 dias (KB 12). */
class AderenciaPorTemplateWidget extends Widget
{
    public const DIAS = 90;

    public const MINIMO_ENVIADOS = 5;

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.aderencia-por-template';

    /** @return Collection<int, array<string, mixed>> */
    public function linhas(): Collection
    {
        return Cache::remember('painel:aderencia-por-template', now()->addMinutes(10), fn (): Collection => app(AderenciaDeTemplates::class)
            ->calcular(now()->subDays(self::DIAS), null, self::MINIMO_ENVIADOS)
            ->take(15));
    }

    public function dias(): int
    {
        return self::DIAS;
    }
}
