<?php

namespace App\Filament\Resources\Suppressions;

use App\Filament\Resources\Suppressions\Pages\CreateSuppression;
use App\Filament\Resources\Suppressions\Pages\ListSuppressions;
use App\Filament\Resources\Suppressions\Schemas\SuppressionForm;
use App\Filament\Resources\Suppressions\Tables\SuppressionsTable;
use App\Models\Suppression;
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
 * Bloqueios (KB 4.8): quem nunca mais recebe, por quê, e as liberações de falso positivo. O badge do
 * menu é a "fila de bloqueios da semana para revisar" pedida na revisão de 28/10.
 */
class SuppressionResource extends Resource
{
    protected static ?string $model = Suppression::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::NoSymbol;

    protected static ?string $slug = 'bloqueios';

    protected static ?string $navigationLabel = 'Bloqueios';

    protected static ?string $modelLabel = 'bloqueio';

    protected static ?string $pluralModelLabel = 'bloqueios';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 60;

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['sender:id,nome', 'user:id,name', 'liberadaPor:id,name']);
    }

    public static function getNavigationBadge(): ?string
    {
        $total = Suppression::query()->vigentes()->where('created_at', '>=', now()->subDays(7))->count();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Bloqueios dos últimos 7 dias (para revisar falsos positivos)';
    }

    public static function form(Schema $schema): Schema
    {
        return SuppressionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppressionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppressions::route('/'),
            'create' => CreateSuppression::route('/create'),
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
        // Bloqueio não se edita: libera-se (com justificativa) ou cria-se outro.
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
