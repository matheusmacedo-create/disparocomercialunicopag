<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Tags\Pages\CreateTag;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Filament\Resources\Tags\Tables\TagsTable;
use App\Models\Tag;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Tag;

    protected static ?string $slug = 'tags';

    protected static ?string $navigationLabel = 'Tags';

    protected static ?string $modelLabel = 'tag';

    protected static ?string $pluralModelLabel = 'tags';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return TagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TagsTable::configure($table);
    }

    public static function urlDosContatos(Tag $tag): string
    {
        return ContactResource::getUrl('index', ['tableFilters' => ['tag' => ['value' => $tag->id]]]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTags::route('/'),
            'create' => CreateTag::route('/create'),
            'edit' => EditTag::route('/{record}/edit'),
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
        return Permissions::allows(Permissions::MANAGE_CONTACTS);
    }
}
