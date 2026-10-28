<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Filament\Resources\MessageTemplates\Pages\ViewMessageTemplate;
use App\Filament\Resources\MessageTemplates\Schemas\MessageTemplateInfolist;
use App\Filament\Resources\MessageTemplates\Tables\MessageTemplatesTable;
use App\Models\MessageTemplate;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Cache dos templates da Meta por remetente (KB 4.5): só leitura. Quem muda template é a Meta; aqui se
 * sincroniza e se confere categoria, status e qualidade antes de usar numa campanha.
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Configuração';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?string $modelLabel = 'template';

    protected static ?string $pluralModelLabel = 'templates';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function infolist(Schema $schema): Schema
    {
        return MessageTemplateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MessageTemplatesTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    public static function canView(Model $record): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
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

    public static function getPages(): array
    {
        return [
            'index' => ListMessageTemplates::route('/'),
            'view' => ViewMessageTemplate::route('/{record}'),
        ];
    }
}
