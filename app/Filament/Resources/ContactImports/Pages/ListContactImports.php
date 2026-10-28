<?php

namespace App\Filament\Resources\ContactImports\Pages;

use App\Filament\Resources\ContactImports\ContactImportResource;
use App\Jobs\ImportarHistoricoKapsoJob;
use App\Models\Sender;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListContactImports extends ListRecords
{
    protected static string $resource = ContactImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Importar CSV'),
            $this->importarHistoricoKapso(),
        ];
    }

    /**
     * Histórico do número na Kapso (KB 2.7, 7): quem já recebeu o quê, quem respondeu, quem pediu para
     * parar. Só `manage senders` (Admin): é cópia de conversas, auditada, antes de qualquer campanha a
     * uma base velha.
     */
    private function importarHistoricoKapso(): Action
    {
        return Action::make('importarHistoricoKapso')
            ->label('Importar histórico da Kapso')
            ->icon(Heroicon::ArrowDownTray)
            ->color('gray')
            ->modalHeading('Importar o histórico de um número na Kapso')
            ->modalDescription('Lê conversas e mensagens do número (só metadados e o resultado do classificador; o texto não fica) e reconstrói quem recebeu o quê, quem respondeu e quem pediu para parar. Roda em fila; o aviso chega no sininho.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                Select::make('sender_id')
                    ->label('Remetente (Kapso)')
                    ->options(fn (): array => Sender::query()->where('provider', Sender::PROVIDER_KAPSO)->orderBy('nome')->pluck('nome', 'id')->all())
                    ->required()
                    ->native(false),
                DatePicker::make('desde')->label('Desde')->native(false)->displayFormat('d/m/Y')->maxDate(today())->helperText('Em branco: continua de onde a última importação parou (ou a janela inicial configurada).'),
                DatePicker::make('ate')->label('Até')->native(false)->displayFormat('d/m/Y')->maxDate(today()),
                Toggle::make('contatos')->label('Completar nomes pelos contatos da Kapso (--contatos)')->default(true),
                Toggle::make('broadcasts')->label('Importar os broadcasts do painel da Kapso (--broadcasts)')->default(true),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_SENDERS))
            ->action(function (array $data): void {
                ImportarHistoricoKapsoJob::dispatch(
                    (int) $data['sender_id'],
                    filled($data['desde'] ?? null) ? (string) $data['desde'] : null,
                    filled($data['ate'] ?? null) ? (string) $data['ate'] : null,
                    (bool) ($data['contatos'] ?? true),
                    (bool) ($data['broadcasts'] ?? true),
                    auth()->id(),
                );

                Notification::make()->info()->title('Importação do histórico na fila')->body('Leva alguns minutos; você recebe o aviso no sininho quando terminar.')->send();
            });
    }
}
