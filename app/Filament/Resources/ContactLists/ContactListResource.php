<?php

namespace App\Filament\Resources\ContactLists;

use App\Filament\Resources\ContactLists\Pages\CreateContactList;
use App\Filament\Resources\ContactLists\Pages\EditContactList;
use App\Filament\Resources\ContactLists\Pages\ListContactLists;
use App\Filament\Resources\ContactLists\Schemas\ContactListForm;
use App\Filament\Resources\ContactLists\Tables\ContactListsTable;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\ContactList;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Bases (KB 4.3): Sellers inativos 90d, Leads UnicoDrop out/2026... Base externa sempre exige consentimento. */
class ContactListResource extends Resource
{
    protected static ?string $model = ContactList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::RectangleStack;

    protected static ?string $slug = 'bases';

    protected static ?string $navigationLabel = 'Bases';

    protected static ?string $modelLabel = 'base';

    protected static ?string $pluralModelLabel = 'bases';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return ContactListForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactListsTable::configure($table);
    }

    /** A lista de contatos filtrada por esta base. */
    public static function urlDosContatos(ContactList $lista): string
    {
        return ContactResource::getUrl('index', ['tableFilters' => ['base' => ['value' => $lista->id]]]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactLists::route('/'),
            'create' => CreateContactList::route('/create'),
            'edit' => EditContactList::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(Permissions::MANAGE_CONTACTS);
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_CONTACTS);
    }

    public static function canDelete(Model $record): bool
    {
        // Campanhas e réguas apontam para a base pelo id: base com contatos é desativada, não apagada.
        return Permissions::allows(Permissions::MANAGE_CONTACTS)
            && $record instanceof ContactList
            && $record->contacts()->doesntExist();
    }
}
