<?php

namespace App\Filament\Resources\Journeys;

use App\Filament\Resources\Journeys\Pages\CreateJourney;
use App\Filament\Resources\Journeys\Pages\EditJourney;
use App\Filament\Resources\Journeys\Pages\ListJourneys;
use App\Filament\Resources\Journeys\Pages\ViewJourney;
use App\Filament\Resources\Journeys\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Journeys\Schemas\JourneyForm;
use App\Filament\Resources\Journeys\Schemas\JourneyInfolist;
use App\Filament\Resources\Journeys\Tables\JourneysTable;
use App\Models\Journey;
use App\Services\Reguas\FluxoDeRegua;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Réguas de nutrição (KB 4.9). Mesma máquina de estados da campanha: o formulário grava a régua e os
 * passos; aprovar, ativar e pausar passam por {@see FluxoDeRegua}, e qualquer
 * edição volta a rascunho e desativa.
 */
class JourneyResource extends Resource
{
    protected static ?string $model = Journey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Disparos';

    protected static ?string $navigationLabel = 'Réguas';

    protected static ?string $modelLabel = 'régua';

    protected static ?string $pluralModelLabel = 'réguas';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return JourneyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JourneyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JourneysTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EnrollmentsRelationManager::class,
        ];
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
        return Permissions::allows(Permissions::MANAGE_JOURNEYS);
    }

    /** Editar uma régua ativa é permitido: ela volta a rascunho e para (FluxoDeRegua::aoEditar). */
    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_JOURNEYS);
    }

    public static function canDelete(Model $record): bool
    {
        // Régua com inscrições e trilha não some: pausa-se.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJourneys::route('/'),
            'create' => CreateJourney::route('/create'),
            'view' => ViewJourney::route('/{record}'),
            'edit' => EditJourney::route('/{record}/edit'),
        ];
    }
}
