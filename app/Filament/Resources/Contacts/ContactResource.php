<?php

namespace App\Filament\Resources\Contacts;

use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\RelationManagers\CampaignRecipientsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\JourneyEnrollmentsRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\Contacts\RelationManagers\SuppressionsRelationManager;
use App\Filament\Resources\Contacts\Schemas\ContactForm;
use App\Filament\Resources\Contacts\Schemas\ContactInfolist;
use App\Filament\Resources\Contacts\Tables\ContactsTable;
use App\Filament\Support\Pii;
use App\Models\Contact;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Contatos (KB 4.2): a lista, a ficha e as ações sobre o titular. Telefone, documento e e-mail passam
 * por {@see Pii}; toda ação confere a permissão no servidor.
 */
class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static ?string $slug = 'contatos';

    protected static ?string $navigationLabel = 'Contatos';

    protected static ?string $modelLabel = 'contato';

    protected static ?string $pluralModelLabel = 'contatos';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 10;

    // A busca global mostraria telefone no resultado para qualquer papel: fica desligada.
    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return ContactForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
            CampaignRecipientsRelationManager::class,
            JourneyEnrollmentsRelationManager::class,
            SuppressionsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public static function canView(Model $record): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(Permissions::MANAGE_CONTACTS);
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_CONTACTS)
            && $record instanceof Contact
            && ! $record->anonimizado();
    }

    public static function canDelete(Model $record): bool
    {
        // Contato nunca é apagado: opt-out, trilha e supressão dependem dele. O direito de exclusão
        // é a anonimização (KB 2.13).
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
