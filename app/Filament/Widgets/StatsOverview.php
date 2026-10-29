<?php

namespace App\Filament\Widgets;

use App\Models\Campaign;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Os números do dia e da semana: o que saiu, chegou, respondeu, bloqueou; e o que pede gente. */
class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    protected function getStats(): array
    {
        $hoje = now('America/Sao_Paulo')->startOfDay();
        $semana = now()->subDays(7);

        $saidos = fn ($desde) => WhatsappMessage::query()
            ->where('direcao', WhatsappMessage::OUTBOUND)
            ->where('tipo', WhatsappMessage::TIPO_TEMPLATE)
            ->where('status', '!=', WhatsappMessage::STATUS_FAILED)
            ->where('enviado_em', '>=', $desde);

        $enviadosHoje = (int) $saidos($hoje)->count();
        $enviadosSemana = (int) $saidos($semana)->count();
        $entreguesSemana = (int) $saidos($semana)->whereIn('status', [WhatsappMessage::STATUS_DELIVERED, WhatsappMessage::STATUS_READ])->count();
        $respostasSemana = (int) WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->where('enviado_em', '>=', $semana)->count();
        $bloqueiosSemana = (int) Suppression::query()->where('created_at', '>=', $semana)->count();
        $emAndamento = (int) Campaign::query()->whereIn('status', [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_PAUSADA])->count();
        $aguardando = (int) Campaign::query()->where('status', Campaign::STATUS_AGUARDANDO_APROVACAO)->count();
        $semTratamento = (int) WhatsappMessage::query()->where('direcao', WhatsappMessage::INBOUND)->whereNull('tratada_em')->count();

        $taxa = $enviadosSemana > 0 ? round(100 * $entreguesSemana / $enviadosSemana) : null;

        return [
            Stat::make('Enviados hoje', $enviadosHoje)
                ->description($enviadosSemana.' nos últimos 7 dias')
                ->color('primary'),
            Stat::make('Entregues (7 dias)', $taxa === null ? '—' : $taxa.'%')
                ->description($entreguesSemana.' de '.$enviadosSemana.' templates')
                ->color($taxa !== null && $taxa < 80 ? 'warning' : 'success'),
            Stat::make('Respostas (7 dias)', $respostasSemana)
                ->description($semTratamento > 0 ? $semTratamento.' sem tratamento' : 'Nenhuma pendente')
                ->color($semTratamento > 0 ? 'warning' : 'success'),
            Stat::make('Bloqueios (7 dias)', $bloqueiosSemana)
                ->description('Pediram para parar, denúncias, preferência da Meta')
                ->color($bloqueiosSemana > 0 ? 'danger' : 'success'),
            Stat::make('Campanhas em andamento', $emAndamento)
                ->description($aguardando > 0 ? $aguardando.' aguardando aprovação' : 'Nenhuma aguardando aprovação')
                ->color($aguardando > 0 ? 'warning' : 'gray')
                ->url('/admin/campaigns?tab=em_andamento'),
        ];
    }
}
