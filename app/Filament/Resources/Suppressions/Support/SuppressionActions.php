<?php

namespace App\Filament\Resources\Suppressions\Support;

use App\Models\Suppression;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/** Ações sobre um bloqueio, usadas na tela Bloqueios e na ficha do contato. */
final class SuppressionActions
{
    /**
     * Desfazer um falso positivo do classificador: só `release suppressions`, com justificativa; nunca
     * para denúncia, preferência de marketing da Meta ou anonimização ({@see Suppression::liberavel()}).
     */
    public static function liberar(): Action
    {
        return Action::make('liberar')
            ->label('Liberar')
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->modalHeading('Desfazer o bloqueio (falso positivo)')
            ->modalDescription('O telefone volta a poder receber mensagens. Denúncia e preferência de marketing da Meta nunca são liberadas aqui. Quem liberou e por quê fica na trilha do contato.')
            ->modalSubmitActionLabel('Liberar')
            ->schema([
                Textarea::make('justificativa')
                    ->label('Justificativa')
                    ->required()
                    ->minLength(10)
                    ->maxLength(500)
                    ->rows(3)
                    ->helperText('Ex.: "não envie mais o boleto" - pedido ao atendimento, não opt-out.'),
            ])
            ->visible(fn (Suppression $record): bool => Permissions::allows(Permissions::RELEASE_SUPPRESSIONS) && $record->liberavel())
            ->action(function (array $data, Suppression $record): void {
                $liberou = $record->liberar((int) auth()->id(), (string) $data['justificativa']);

                $liberou
                    ? Notification::make()->success()->title('Bloqueio liberado')->body('O contato volta a ser elegível; a liberação ficou na trilha.')->send()
                    : Notification::make()->warning()->title('Este bloqueio não pode ser liberado')->body('Denúncia, preferência da Meta e anonimização são definitivos.')->send();
            });
    }
}
