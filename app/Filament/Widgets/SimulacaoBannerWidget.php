<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/** Banner no topo do painel enquanto o envio real estiver desligado (KB 2.1). */
class SimulacaoBannerWidget extends Widget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.simulacao-banner';

    public static function canView(): bool
    {
        return auth()->check() && ! config('disparo.envio_habilitado');
    }
}
