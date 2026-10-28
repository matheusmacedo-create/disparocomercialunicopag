<?php

namespace App\Filament\Resources\Senders\Pages;

use App\Filament\Resources\Senders\SenderResource;
use App\Filament\Resources\Senders\Support\SenderActions;
use App\Filament\Resources\Senders\Widgets\QualidadeDoRemetenteChart;
use App\Models\Sender;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSender extends ViewRecord
{
    protected static string $resource = SenderResource::class;

    public function getTitle(): string
    {
        /** @var Sender $sender */
        $sender = $this->getRecord();

        return $sender->rotulo();
    }

    protected function getHeaderActions(): array
    {
        return [
            SenderActions::religar(),
            SenderActions::pausar(),
            SenderActions::sincronizarNumero(),
            ActionGroup::make([
                EditAction::make()->label('Editar'),
                SenderActions::sincronizarTemplates(),
                SenderActions::registrarWebhook(),
            ])->label('Mais')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            QualidadeDoRemetenteChart::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
