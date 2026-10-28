<?php

namespace App\Filament\Resources\Conversations\Tables;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Support\ContactActions;
use App\Filament\Support\Pii;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ConversationsTable
{
    /**
     * Onde a Kapso mostra a conversa; o id vem de `whatsapp_messages.kapso_conversation_id`. Padrão
     * [inferido] da documentação do inbox - ajustar se a Kapso usar outro caminho.
     */
    public const KAPSO_CONVERSA_URL = 'https://app.kapso.ai/conversations/%s';

    public static function configure(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->columns([
                TextColumn::make('enviado_em')->label('Recebida em')->dateTime('d/m/Y H:i', $fuso)->sortable(),
                TextColumn::make('sender.nome')->label('Remetente')->placeholder('—'),
                TextColumn::make('contato')
                    ->label('Contato')
                    ->state(fn (WhatsappMessage $record): string => Pii::telefone($record->contact?->telefone ?? $record->telefone))
                    ->description(fn (WhatsappMessage $record): ?string => $record->contact?->anonimizado() ? null : ($record->contact?->nome ?: $record->contact?->empresa))
                    ->url(fn (WhatsappMessage $record): ?string => $record->contact ? ContactResource::getUrl('view', ['record' => $record->contact]) : null)
                    ->weight('medium'),
                TextColumn::make('classificacao')
                    ->label('Classificação')
                    ->badge()
                    ->state(fn (WhatsappMessage $record): ?string => $record->classificacao())
                    ->formatStateUsing(fn (?string $state): string => self::classificacaoLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        WhatsappMessage::CLASSIFICACAO_PEDIDO_LGPD, 'pedido_para_parar', 'parar', 'denunciou' => 'danger',
                        'agora_nao', 'recusou' => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('prazo_lgpd')
                    ->label('Prazo LGPD')
                    ->state(fn (WhatsappMessage $record): ?string => $record->prazoLgpd()?->timezone($fuso)->format('d/m/Y'))
                    ->description(fn (WhatsappMessage $record): ?string => ($prazo = $record->prazoLgpd()) ? $prazo->diffForHumans() : null)
                    ->color('danger')
                    ->weight('bold')
                    ->placeholder('—'),
                TextColumn::make('janela')
                    ->label('Janela 24 h')
                    ->badge()
                    ->state(fn (WhatsappMessage $record): string => ($ate = WhatsappMessage::janelaAbertaAte($record->contact?->ultima_resposta_em))
                        ? 'Aberta até '.$ate->timezone($fuso)->format('d/m H:i')
                        : 'Fechada')
                    ->color(fn (WhatsappMessage $record): string => WhatsappMessage::janelaAbertaAte($record->contact?->ultima_resposta_em) ? 'success' : 'gray'),
                TextColumn::make('conteudo')
                    ->label('Mensagem')
                    ->wrap()
                    ->limit(140)
                    ->tooltip(fn (WhatsappMessage $record): ?string => $record->conteudo)
                    ->placeholder('(sem texto: mídia, botão ou histórico)')
                    ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_CONVERSATIONS)),
                TextColumn::make('tratada_em')
                    ->label('Tratamento')
                    ->badge()
                    ->state(fn (WhatsappMessage $record): string => $record->tratada_em ? 'Tratada' : 'Pendente')
                    ->color(fn (WhatsappMessage $record): string => $record->tratada_em ? 'success' : 'warning')
                    ->description(fn (WhatsappMessage $record): ?string => $record->tratada_em
                        ? collect([$record->tratada_em->timezone($fuso)->format('d/m H:i'), $record->tratadaPor?->name])->filter()->implode(' · ')
                        : null),
            ])
            ->filters([
                SelectFilter::make('sender_id')->label('Remetente')->relationship('sender', 'nome'),
                SelectFilter::make('classificacao')
                    ->label('Classificação')
                    ->options(self::classificacaoLabels())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('payload->classificacao', (string) $data['value'])
                        : $query),
            ])
            ->defaultSort('enviado_em', 'desc')
            ->recordActions([
                self::marcarTratada(),
                ContactActions::responder(),
                self::abrirNaKapso(),
                self::atenderPedidoLgpd(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('marcar_tratadas')
                        ->label('Marcar como tratadas')
                        ->icon('heroicon-o-check')
                        ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_CONVERSATIONS))
                        ->action(function (Collection $records): void {
                            /** @var User $user */
                            $user = auth()->user();
                            $records->each(fn (WhatsappMessage $mensagem) => $mensagem->marcarTratada($user));
                            Notification::make()->success()->title($records->count().' conversa(s) marcada(s) como tratada(s)')->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /** @return array<string, string> classificações que o processador de eventos grava em `payload.classificacao` */
    public static function classificacaoLabels(): array
    {
        return [
            WhatsappMessage::CLASSIFICACAO_PEDIDO_LGPD => 'Pedido LGPD',
            'pedido_para_parar' => 'Pediu para parar',
            'denunciou' => 'Denunciou',
            'agora_nao' => 'Agora não',
            'quero_receber' => 'Quero receber',
            'resposta' => 'Resposta',
        ];
    }

    private static function marcarTratada(): Action
    {
        return Action::make('marcar_tratada')
            ->label('Marcar tratada')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (WhatsappMessage $record): bool => Permissions::allows(Permissions::VIEW_CONVERSATIONS) && $record->tratada_em === null)
            ->action(function (WhatsappMessage $record): void {
                /** @var User $user */
                $user = auth()->user();
                $record->marcarTratada($user);
                Notification::make()->success()->title('Conversa marcada como tratada')->send();
            });
    }

    private static function abrirNaKapso(): Action
    {
        return Action::make('abrir_na_kapso')
            ->label('Abrir na Kapso')
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('gray')
            ->url(fn (WhatsappMessage $record): string => sprintf(self::KAPSO_CONVERSA_URL, rawurlencode((string) $record->kapso_conversation_id)))
            ->openUrlInNewTab()
            ->visible(fn (WhatsappMessage $record): bool => filled($record->kapso_conversation_id));
    }

    private static function atenderPedidoLgpd(): Action
    {
        return Action::make('atender_lgpd')
            ->label('Atender pedido LGPD')
            ->icon('heroicon-o-shield-exclamation')
            ->color('danger')
            ->url(fn (WhatsappMessage $record): ?string => $record->contact ? ContactResource::getUrl('view', ['record' => $record->contact]) : null)
            ->visible(fn (WhatsappMessage $record): bool => $record->pedidoLgpd() && $record->contact !== null);
    }
}
