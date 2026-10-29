<?php

namespace App\Filament\Resources\Conversations;

use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Tables\ConversationsTable;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Conversas: as respostas dos clientes (mensagens inbound) para alguém tratar - o "balcão" que a
 * revisão de 28/10 cobrou. Quem lê fica na trilha do contato; texto só com `view conversations`.
 */
class ConversationResource extends Resource
{
    protected static ?string $model = WhatsappMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChatBubbleLeftRight;

    protected static ?string $slug = 'conversas';

    protected static ?string $navigationLabel = 'Conversas';

    protected static ?string $modelLabel = 'conversa';

    protected static ?string $pluralModelLabel = 'conversas';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 70;

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->inbound()
            ->with(['sender:id,nome', 'contact', 'tratadaPor:id,name']);
    }

    public static function getNavigationBadge(): ?string
    {
        $total = WhatsappMessage::query()->inbound()->naoTratadas()->count();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Respostas de clientes sem tratamento';
    }

    public static function table(Table $table): Table
    {
        return ConversationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_CONVERSATIONS);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
