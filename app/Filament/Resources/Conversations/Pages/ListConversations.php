<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\WhatsappMessage;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** A fila: o que ninguém tratou primeiro; pedidos do titular (LGPD) com prazo em aba própria. */
class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    public function getSubheading(): ?string
    {
        return 'Respostas dos clientes aos remetentes da plataforma. Texto livre só dentro da janela de 24 h; fora dela, template.';
    }

    public function getTabs(): array
    {
        $contar = fn (callable $escopo): int => $escopo(WhatsappMessage::query()->inbound())->count();

        $abertas = fn (Builder $query): Builder => $query->naoTratadas();
        $lgpd = fn (Builder $query): Builder => $query->naoTratadas()->where(fn (Builder $q) => $q
            ->where('payload->classificacao', WhatsappMessage::CLASSIFICACAO_PEDIDO_LGPD)
            ->orWhereNotNull('payload->lgpd'));

        return [
            'abertas' => Tab::make('Sem tratamento')
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge(fn (): ?int => $contar($abertas) ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing($abertas),
            'lgpd' => Tab::make('Pedidos LGPD')
                ->icon('heroicon-o-shield-exclamation')
                ->badge(fn (): ?int => $contar($lgpd) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing($lgpd),
            'tratadas' => Tab::make('Tratadas')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('tratada_em')),
            'todas' => Tab::make('Todas'),
        ];
    }
}
