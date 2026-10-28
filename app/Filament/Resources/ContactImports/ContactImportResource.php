<?php

namespace App\Filament\Resources\ContactImports;

use App\Filament\Resources\ContactImports\Pages\CreateContactImport;
use App\Filament\Resources\ContactImports\Pages\ListContactImports;
use App\Filament\Resources\ContactImports\Pages\ViewContactImport;
use App\Filament\Resources\ContactImports\Schemas\ContactImportInfolist;
use App\Filament\Resources\ContactImports\Tables\ContactImportsTable;
use App\Models\ContactImport;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Importações (KB 4.4): CSV com prévia do mapeamento antes de enfileirar, histórico da Kapso e o
 * resultado de cada uma (contagens, primeiros erros, arquivo de rejeitadas).
 */
class ContactImportResource extends Resource
{
    protected static ?string $model = ContactImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowUpTray;

    protected static ?string $slug = 'importacoes';

    protected static ?string $navigationLabel = 'Importações';

    protected static ?string $modelLabel = 'importação';

    protected static ?string $pluralModelLabel = 'importações';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 50;

    protected static bool $isGloballySearchable = false;

    /** Onde o job grava as linhas rejeitadas (disco `local`, pasta privada). */
    public static function caminhoDasRejeitadas(ContactImport $import): string
    {
        return 'importacoes/'.$import->id.'/rejeitadas.csv';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['list:id,nome', 'sender:id,nome', 'user:id,name']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactImportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactImports::route('/'),
            'create' => CreateContactImport::route('/create'),
            'view' => ViewContactImport::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::IMPORT_CONTACTS);
    }

    public static function canView(Model $record): bool
    {
        return Permissions::allows(Permissions::IMPORT_CONTACTS);
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(Permissions::IMPORT_CONTACTS);
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
