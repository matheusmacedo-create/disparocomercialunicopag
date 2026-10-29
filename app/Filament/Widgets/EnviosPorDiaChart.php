<?php

namespace App\Filament\Widgets;

use App\Models\WhatsappMessage;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Onda dos últimos 30 dias: templates enviados, entregues e lidos por dia, e respostas recebidas no dia. */
class EnviosPorDiaChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Envios por dia (30 dias)';

    protected ?string $description = 'Templates que saíram pelo número (qualquer origem: campanhas, réguas, KYC, painel da Kapso), quantos foram entregues e lidos, e as respostas que chegaram no dia.';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        return Cache::remember('painel:envios-por-dia', now()->addMinutes(5), function (): array {
            $inicio = now()->subDays(29)->startOfDay();
            $dias = collect(range(0, 29))->map(fn (int $i): string => $inicio->copy()->addDays($i)->toDateString());

            $saidas = WhatsappMessage::query()
                ->selectRaw('date(enviado_em) as dia, count(*) as enviados, sum(case when status in (?, ?) then 1 else 0 end) as entregues, sum(case when status = ? then 1 else 0 end) as lidos', [WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ, WhatsappMessage::STATUS_READ])
                ->where('direcao', WhatsappMessage::OUTBOUND)
                ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
                ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
                ->where('enviado_em', '>=', $inicio)
                ->groupBy(DB::raw('date(enviado_em)'))
                ->get()
                ->keyBy('dia');

            $respostas = WhatsappMessage::query()
                ->selectRaw('date(enviado_em) as dia, count(*) as total')
                ->where('direcao', WhatsappMessage::INBOUND)
                ->where('enviado_em', '>=', $inicio)
                ->groupBy(DB::raw('date(enviado_em)'))
                ->get()
                ->keyBy('dia');

            $serie = fn (string $campo, $fonte): array => $dias->map(fn (string $dia): int => (int) ($fonte->get($dia)?->{$campo} ?? 0))->all();

            return [
                'datasets' => [
                    ['label' => 'Enviados', 'data' => $serie('enviados', $saidas), 'borderColor' => '#2e4374', 'backgroundColor' => 'rgba(46, 67, 116, 0.15)', 'fill' => 'start', 'tension' => 0.3],
                    ['label' => 'Entregues', 'data' => $serie('entregues', $saidas), 'borderColor' => '#0ea5e9', 'backgroundColor' => 'rgba(14, 165, 233, 0.1)', 'fill' => false, 'tension' => 0.3],
                    ['label' => 'Lidos', 'data' => $serie('lidos', $saidas), 'borderColor' => '#22c55e', 'backgroundColor' => 'rgba(34, 197, 94, 0.1)', 'fill' => false, 'tension' => 0.3],
                    ['label' => 'Respostas', 'data' => $serie('total', $respostas), 'borderColor' => '#f59e0b', 'backgroundColor' => 'rgba(245, 158, 11, 0.1)', 'fill' => false, 'tension' => 0.3],
                ],
                'labels' => $dias->map(fn (string $dia): string => substr($dia, 8, 2).'/'.substr($dia, 5, 2))->all(),
            ];
        });
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['display' => true, 'position' => 'bottom']],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ];
    }
}
