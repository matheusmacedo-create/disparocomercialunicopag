<?php

namespace App\Filament\Resources\Journeys\Support;

use App\Filament\Support\Fluxo;
use App\Models\Contact;
use App\Models\Journey;
use App\Models\User;
use App\Services\Reguas\FluxoDeRegua;
use App\Support\Permissions;
use App\Support\Telefone;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/** Ações da ficha da régua: a regra mora no {@see FluxoDeRegua}; aqui só tela, visibilidade e aviso. */
class JourneyActions
{
    public static function statusColor(?string $status): string
    {
        return match ($status) {
            Journey::STATUS_RASCUNHO => 'gray',
            Journey::STATUS_AGUARDANDO_APROVACAO => 'warning',
            Journey::STATUS_APROVADA => 'info',
            Journey::STATUS_ATIVA => 'success',
            Journey::STATUS_PAUSADA => 'warning',
            default => 'gray',
        };
    }

    public static function enviarParaAprovacao(): Action
    {
        return Action::make('enviar_para_aprovacao')
            ->label('Enviar para aprovação')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(fn (ViewRecord $livewire): string => self::resumo(self::regua($livewire)))
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_JOURNEYS)
                && self::regua($livewire)->status === Journey::STATUS_RASCUNHO)
            ->action(function (ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);

                if (Fluxo::tentar(fn () => $fluxo->enviarParaAprovacao($regua, self::usuario()))) {
                    Notification::make()->success()->title('Régua aguardando aprovação')->send();
                }

                $regua->refresh();
            });
    }

    public static function aprovar(): Action
    {
        return Action::make('aprovar')
            ->label('Aprovar')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading('Aprovar régua')
            ->modalDescription(fn (ViewRecord $livewire): string => self::resumo(self::regua($livewire)).' Quem criou não aprova a própria régua: ela roda todo dia, sozinha.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && self::regua($livewire)->status === Journey::STATUS_AGUARDANDO_APROVACAO)
            ->schema([
                Textarea::make('observacao')->label('Observação')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);

                if (Fluxo::tentar(fn () => $fluxo->aprovar($regua, self::usuario(), filled($data['observacao'] ?? null) ? (string) $data['observacao'] : null))) {
                    Notification::make()->success()->title('Régua aprovada')->body('Use "Ativar" para ela começar a inscrever e enviar.')->send();
                }

                $regua->refresh();
            });
    }

    public static function devolver(): Action
    {
        return Action::make('devolver')
            ->label('Devolver para ajuste')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && in_array(self::regua($livewire)->status, [Journey::STATUS_AGUARDANDO_APROVACAO, Journey::STATUS_APROVADA], true))
            ->schema([
                Textarea::make('motivo')->label('O que precisa mudar')->required()->minLength(5)->maxLength(500)->rows(3),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);

                if (Fluxo::tentar(fn () => $fluxo->devolver($regua, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Régua devolvida para ajuste')->send();
                }

                $regua->refresh();
            });
    }

    public static function ativar(): Action
    {
        return Action::make('ativar')
            ->label('Ativar')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(fn (ViewRecord $livewire): string => 'A régua passa a inscrever pelo gatilho e a enviar os passos nas rodadas, dentro das guardas do remetente e do máximo de '.self::regua($livewire)->max_por_dia.' por dia.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && in_array(self::regua($livewire)->status, [Journey::STATUS_APROVADA, Journey::STATUS_PAUSADA], true))
            ->action(function (ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);

                if (Fluxo::tentar(fn () => $fluxo->ativar($regua, self::usuario()))) {
                    Notification::make()->success()->title('Régua ativa')->send();
                }

                $regua->refresh();
            });
    }

    public static function pausar(): Action
    {
        return Action::make('pausar')
            ->label('Pausar')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_JOURNEYS)
                && self::regua($livewire)->status === Journey::STATUS_ATIVA)
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);

                if (Fluxo::tentar(fn () => $fluxo->pausar($regua, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Régua pausada')->body('As inscrições ficam onde estão; reativar retoma de onde parou.')->send();
                }

                $regua->refresh();
            });
    }

    public static function inscrever(): Action
    {
        return Action::make('inscrever')
            ->label('Inscrever contato')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('gray')
            ->modalDescription('Busca o contato pelo telefone e o inscreve no primeiro passo. Uma inscrição ativa por contato; quem já passou só volta depois do prazo de reinscrição.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_JOURNEYS)
                && self::regua($livewire)->status === Journey::STATUS_ATIVA)
            ->schema([
                TextInput::make('telefone')->label('Telefone do contato')->tel()->required()->maxLength(30)->placeholder('(21) 99876-5432'),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeRegua $fluxo): void {
                $regua = self::regua($livewire);
                $contato = Contact::query()->porTelefone((string) $data['telefone'])->first();

                if ($contato === null) {
                    Notification::make()->danger()->title('Contato não encontrado')->body('Não há contato com o telefone '.Telefone::mascarar(Telefone::normalizar((string) $data['telefone']) ?? (string) $data['telefone']).'. Importe ou cadastre antes.')->send();

                    return;
                }

                $inscreveu = false;

                if (Fluxo::tentar(function () use ($fluxo, $regua, $contato, &$inscreveu): void {
                    $inscreveu = $fluxo->inscrever($regua, $contato, self::usuario());
                })) {
                    $inscreveu
                        ? Notification::make()->success()->title('Contato inscrito no primeiro passo')->send()
                        : Notification::make()->warning()->title('Contato não inscrito')->body('Já tem inscrição ativa, a régua não tem passos ou ainda não passou o prazo de reinscrição.')->send();
                }
            });
    }

    public static function resumo(Journey $regua): string
    {
        $passos = $regua->steps()->count();
        $templates = $regua->steps()->where('tipo', 'template')->count();

        return sprintf('%d passo(s), %d template(s), no máximo %d envio(s) por dia pelo remetente "%s", intervalo mínimo de %d h entre templates.', $passos, $templates, (int) $regua->max_por_dia, (string) $regua->sender?->nome, (int) $regua->intervalo_minimo_horas);
    }

    private static function regua(ViewRecord $livewire): Journey
    {
        /** @var Journey $regua */
        $regua = $livewire->getRecord();

        return $regua;
    }

    private static function usuario(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
