<?php

namespace App\Filament\Resources\Campaigns;

use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Campaigns\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\Campaigns\Schemas\CampaignForm;
use App\Filament\Resources\Campaigns\Schemas\CampaignInfolist;
use App\Filament\Resources\Campaigns\Tables\CampaignsTable;
use App\Models\Campaign;
use App\Services\Campanhas\FluxoDeCampanha;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Campanhas (KB 4.6, 11). O formulário só grava o que define o envio; toda transição de estado (prévia,
 * aprovação, início, pausa) passa por {@see FluxoDeCampanha} a partir das ações
 * da ficha - `status`, `aprovado_*` e `criado_por` ficam fora do formulário de propósito.
 */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Disparos';

    protected static ?string $navigationLabel = 'Campanhas';

    protected static ?string $modelLabel = 'campanha';

    protected static ?string $pluralModelLabel = 'campanhas';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'nome';

    public static function form(Schema $schema): Schema
    {
        return CampaignForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CampaignInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CampaignsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        if (! Permissions::allows(Permissions::APPROVE_CAMPAIGNS)) {
            return null;
        }

        $aguardando = Campaign::query()->where('status', Campaign::STATUS_AGUARDANDO_APROVACAO)->count();

        return $aguardando > 0 ? (string) $aguardando : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Campanhas aguardando aprovação';
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
        return Permissions::allows(Permissions::MANAGE_CAMPAIGNS);
    }

    /** Só rascunho e aguardando aprovação se editam; depois disso a campanha é o que foi aprovado. */
    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_CAMPAIGNS) && $record instanceof Campaign && $record->editavel();
    }

    public static function canDelete(Model $record): bool
    {
        // Campanha não some: cancela-se, e a trilha fica.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
