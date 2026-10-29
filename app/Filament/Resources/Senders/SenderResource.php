<?php

namespace App\Filament\Resources\Senders;

use App\Filament\Resources\Senders\Pages\CreateSender;
use App\Filament\Resources\Senders\Pages\EditSender;
use App\Filament\Resources\Senders\Pages\ListSenders;
use App\Filament\Resources\Senders\Pages\ViewSender;
use App\Filament\Resources\Senders\Schemas\SenderForm;
use App\Filament\Resources\Senders\Schemas\SenderInfolist;
use App\Filament\Resources\Senders\Tables\SendersTable;
use App\Models\Sender;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Remetentes (KB 4.1): um número, um provedor, uma política. Só `manage senders` entra aqui: a chave da
 * API e o segredo do webhook ficam criptografados e nunca aparecem (o campo da chave é só de escrita).
 */
class SenderResource extends Resource
{
    protected static ?string $model = Sender::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Configuração';

    protected static ?string $navigationLabel = 'Remetentes';

    protected static ?string $modelLabel = 'remetente';

    protected static ?string $pluralModelLabel = 'remetentes';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return SenderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SenderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SendersTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::MANAGE_SENDERS);
    }

    public static function canView(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_SENDERS);
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(Permissions::MANAGE_SENDERS);
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_SENDERS);
    }

    public static function canDelete(Model $record): bool
    {
        // Remetente com trilha e mensagens não some: desativa-se ("Ativo" desligado).
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSenders::route('/'),
            'create' => CreateSender::route('/create'),
            'view' => ViewSender::route('/{record}'),
            'edit' => EditSender::route('/{record}/edit'),
        ];
    }
}
