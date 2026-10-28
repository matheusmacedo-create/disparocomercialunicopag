<?php

namespace App\Filament\Resources\Senders\Support;

use App\Filament\Support\ComandoDoPainel;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Ações da ficha do remetente (todas sob `manage senders`). Sincronizações e webhook rodam os comandos
 * `disparo:*` de forma tolerante: comando ainda inexistente vira aviso, não erro de tela.
 */
class SenderActions
{
    public static function sincronizarNumero(): Action
    {
        return Action::make('sincronizar_numero')
            ->label('Sincronizar número')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_SENDERS))
            ->action(function (ViewRecord $livewire): void {
                self::rodar('disparo:sincronizar-remetentes', ['sender' => self::remetente($livewire)->id], 'Número sincronizado (qualidade, tier, status)');
                self::remetente($livewire)->refresh();
            });
    }

    public static function sincronizarTemplates(): Action
    {
        return Action::make('sincronizar_templates')
            ->label('Sincronizar templates')
            ->icon(Heroicon::OutlinedDocumentText)
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_SENDERS))
            ->action(function (ViewRecord $livewire): void {
                self::rodar('disparo:sincronizar-templates', ['sender' => self::remetente($livewire)->id], 'Templates sincronizados com a Meta');
                self::remetente($livewire)->refresh();
            });
    }

    public static function registrarWebhook(): Action
    {
        return Action::make('registrar_webhook')
            ->label(fn (ViewRecord $livewire): string => filled(self::remetente($livewire)->webhook_secret) ? 'Rotacionar segredo do webhook' : 'Registrar webhook')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->requiresConfirmation()
            ->modalHeading('Registrar webhook na Kapso')
            ->modalDescription('Gera (ou troca) o segredo do webhook deste número e o cadastra na Kapso apontando para esta plataforma. O segredo aparece UMA vez na saída: copie se precisar; depois só rotacionando.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_SENDERS)
                && self::remetente($livewire)->provider === Sender::PROVIDER_KAPSO)
            ->action(function (ViewRecord $livewire): void {
                $sender = self::remetente($livewire);
                $resultado = ComandoDoPainel::rodar('disparo:registrar-webhook', ['sender' => $sender->id]);

                if (! $resultado['ok']) {
                    Notification::make()->warning()->title('Webhook não registrado')->body(e((string) $resultado['erro']).($resultado['saida'] !== '' ? '<br>'.nl2br(e($resultado['saida'])) : ''))->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Webhook registrado')->body(nl2br(e($resultado['saida'] ?: 'Segredo gerado e webhook cadastrado.')))->persistent()->send();
                $sender->refresh();
            });
    }

    public static function pausar(): Action
    {
        return Action::make('pausar')
            ->label('Pausar remetente')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->modalDescription('Nenhuma campanha, régua ou teste sai por este número até alguém religar. As campanhas em andamento ficam esperando.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_SENDERS)
                && self::remetente($livewire)->pausado_em === null)
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire): void {
                $sender = self::remetente($livewire);
                $sender->pausar('Pausado por '.auth()->user()?->name.': '.(string) $data['motivo']);
                SenderEvent::registrar($sender, 'pausado', ['motivo' => mb_substr((string) $data['motivo'], 0, 500)], auth()->id());
                Notification::make()->success()->title('Remetente pausado')->send();
                $sender->refresh();
            });
    }

    public static function religar(): Action
    {
        return Action::make('religar')
            ->label('Religar')
            ->icon(Heroicon::OutlinedPlay)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Religar o remetente')
            ->modalDescription(fn (ViewRecord $livewire): string => self::motivoDaPausa(self::remetente($livewire))
                .' Antes de religar, confira a qualidade do número no WhatsApp Manager (Meta Business): se ainda estiver RED ou YELLOW, insistir derruba o número de vez. Ao religar, o aquecimento recomeça do teto inicial.')
            ->modalSubmitActionLabel('Religar e reaquecer')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_SENDERS)
                && (self::remetente($livewire)->pausado_em !== null || self::remetente($livewire)->bloqueadoPorQualidade()))
            ->action(function (ViewRecord $livewire): void {
                $sender = self::remetente($livewire);
                $motivo = self::motivoDaPausa($sender);
                $sender->religar(true);
                SenderEvent::registrar($sender, 'religado', ['pausa_anterior' => mb_substr($motivo, 0, 500), 'reaquecer' => true], auth()->id());
                Notification::make()->success()->title('Remetente religado')->body('Aquecimento reiniciado: o teto sobe a cada dia GREEN.')->send();
                $sender->refresh();
            });
    }

    public static function motivoDaPausa(Sender $sender): string
    {
        $partes = [];

        if ($sender->bloqueio_qualidade_em !== null) {
            $partes[] = 'Bloqueado por qualidade em '.$sender->bloqueio_qualidade_em->timezone('America/Sao_Paulo')->format('d/m H:i').': '.($sender->bloqueio_qualidade_motivo ?: 'sem motivo registrado').'.';
        }

        if ($sender->pausado_em !== null) {
            $partes[] = 'Pausado em '.$sender->pausado_em->timezone('America/Sao_Paulo')->format('d/m H:i').': '.($sender->pausado_motivo ?: 'sem motivo registrado').'.';
        }

        return implode(' ', $partes) ?: 'Sem pausa registrada.';
    }

    /** @param  array<string, mixed>  $parametros */
    private static function rodar(string $comando, array $parametros, string $sucesso): void
    {
        $resultado = ComandoDoPainel::rodar($comando, $parametros);

        if (! $resultado['ok']) {
            Notification::make()->warning()->title('Não foi possível sincronizar')->body(e((string) $resultado['erro']).($resultado['saida'] !== '' ? '<br>'.nl2br(e($resultado['saida'])) : ''))->persistent()->send();

            return;
        }

        Notification::make()->success()->title($sucesso)->body(nl2br(e($resultado['saida'])))->send();
    }

    private static function remetente(ViewRecord $livewire): Sender
    {
        /** @var Sender $sender */
        $sender = $livewire->getRecord();

        return $sender;
    }
}
