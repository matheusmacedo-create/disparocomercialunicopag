<?php

namespace App\Filament\Resources\Senders\Widgets;

use App\Models\Sender;
use App\Models\SenderQualitySnapshot;
use Filament\Widgets\ChartWidget;

/** Qualidade do número nas últimas leituras (hora a hora): 3 = GREEN, 2 = YELLOW, 1 = RED, 0 = desconhecida. */
class QualidadeDoRemetenteChart extends ChartWidget
{
    public ?Sender $record = null;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Qualidade do número (últimos 7 dias)';

    protected ?string $description = '3 = GREEN · 2 = YELLOW · 1 = RED · 0 = sem leitura. Leitura de hora em hora pelo disparo:sincronizar-remetentes.';

    protected ?string $maxHeight = '220px';

    protected function getData(): array
    {
        $snapshots = $this->record === null
            ? collect()
            : SenderQualitySnapshot::query()
                ->where('sender_id', $this->record->id)
                ->where('medido_em', '>=', now()->subDays(7))
                ->orderBy('medido_em')
                ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Qualidade',
                    'data' => $snapshots->map(fn (SenderQualitySnapshot $s): int => match ($s->qualidade) {
                        Sender::QUALIDADE_GREEN => 3,
                        Sender::QUALIDADE_YELLOW => 2,
                        Sender::QUALIDADE_RED => 1,
                        default => 0,
                    })->values()->all(),
                    'fill' => 'start',
                    'tension' => 0.2,
                ],
            ],
            'labels' => $snapshots->map(fn (SenderQualitySnapshot $s): string => $s->medido_em?->timezone('America/Sao_Paulo')->format('d/m H\h') ?? '')->values()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['min' => 0, 'max' => 3, 'ticks' => ['stepSize' => 1]]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
