<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Linha do tempo do contato: tudo o que saiu ou chegou por qualquer remetente, inclusive o histórico
 * importado. Texto do cliente só com `view conversations`; valores renderizados do template só com
 * `view pii` (KB 2.13).
 */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = 'Mensagens';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Linha do tempo')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('sender'))
            ->columns([
                TextColumn::make('enviado_em')->label('Quando')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->sortable(),
                TextColumn::make('direcao')
                    ->label('Direção')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === WhatsappMessage::INBOUND ? 'Recebida' : 'Enviada')
                    ->color(fn (?string $state): string => $state === WhatsappMessage::INBOUND ? 'primary' : 'gray'),
                TextColumn::make('sender.nome')->label('Remetente')->placeholder('—'),
                TextColumn::make('tipo')->label('Tipo')->placeholder('—'),
                TextColumn::make('template_nome')->label('Template')->placeholder('—')->limit(40)->tooltip(fn (WhatsappMessage $record): ?string => $record->template_nome),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => WhatsappMessage::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => WhatsappMessage::statusColor($state)),
                TextColumn::make('origem')->label('Origem')->badge()->color('gray'),
                TextColumn::make('conteudo')
                    ->label('Conteúdo')
                    ->state(fn (WhatsappMessage $record): ?string => self::conteudo($record))
                    ->wrap()
                    ->limit(160)
                    ->placeholder('—'),
                TextColumn::make('codigo_erro')
                    ->label('Erro')
                    ->placeholder('—')
                    ->color('danger')
                    ->tooltip(fn (WhatsappMessage $record): ?string => $record->erro),
            ])
            ->filters([
                SelectFilter::make('direcao')->label('Direção')->options([WhatsappMessage::INBOUND => 'Recebidas', WhatsappMessage::OUTBOUND => 'Enviadas']),
                SelectFilter::make('origem')->label('Origem')->options([
                    WhatsappMessage::ORIGEM_CAMPANHA => 'Campanha',
                    WhatsappMessage::ORIGEM_REGUA => 'Régua',
                    WhatsappMessage::ORIGEM_MANUAL => 'Manual',
                    WhatsappMessage::ORIGEM_HISTORICO_KAPSO => 'Histórico Kapso',
                    WhatsappMessage::ORIGEM_BROADCAST_KAPSO => 'Broadcast Kapso',
                    WhatsappMessage::ORIGEM_WEBHOOK => 'Visto pelo webhook',
                ]),
            ])
            ->defaultSort('enviado_em', 'desc')
            ->paginated([10, 25, 50]);
    }

    private static function conteudo(WhatsappMessage $mensagem): ?string
    {
        if ($mensagem->direcao === WhatsappMessage::INBOUND) {
            return Permissions::allows(Permissions::VIEW_CONVERSATIONS) ? $mensagem->conteudo : '(texto só com "ver conversas")';
        }

        if (Permissions::allows(Permissions::VIEW_PII)) {
            return $mensagem->conteudo;
        }

        return $mensagem->template_nome ? 'template '.$mensagem->template_nome : '(só com "ver dados sensíveis")';
    }
}
