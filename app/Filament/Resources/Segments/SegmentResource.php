<?php

namespace App\Filament\Resources\Segments;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Segments\Pages\CreateSegment;
use App\Filament\Resources\Segments\Pages\EditSegment;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Schemas\SegmentForm;
use App\Filament\Resources\Segments\Tables\SegmentsTable;
use App\Models\Segment;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Segmentos (KB 4.3): filtro salvo; a tradução para SQL é só da ConsultaDeSegmento. */
class SegmentResource extends Resource
{
    protected static ?string $model = Segment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Funnel;

    protected static ?string $slug = 'segmentos';

    protected static ?string $navigationLabel = 'Segmentos';

    protected static ?string $modelLabel = 'segmento';

    protected static ?string $pluralModelLabel = 'segmentos';

    protected static string|UnitEnum|null $navigationGroup = 'Contatos';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'nome';

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return SegmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SegmentsTable::configure($table);
    }

    public static function urlDosContatos(Segment $segmento): string
    {
        return ContactResource::getUrl('index', ['tableFilters' => ['segmento' => ['value' => $segmento->id]]]);
    }

    /**
     * Tira do JSON o que o formulário deixa vazio (null, '', []), para as regras guardadas serem só as
     * regras de verdade. Chaves aninhadas (campanha, recebeu_template...) somem quando ficam vazias.
     *
     * @param  array<string, mixed>  $regras
     * @return array<string, mixed>
     */
    public static function limparRegras(array $regras): array
    {
        $limpas = [];

        foreach ($regras as $chave => $valor) {
            if (is_array($valor)) {
                $valor = array_is_list($valor) ? array_values(array_filter($valor, fn ($v): bool => $v !== null && $v !== '')) : self::limparRegras($valor);
            }

            if ($valor === null || $valor === '' || $valor === [] || ($chave === 'sem_whatsapp' && $valor === true)) {
                continue;
            }

            $limpas[$chave] = $valor;
        }

        return $limpas;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSegments::route('/'),
            'create' => CreateSegment::route('/create'),
            'edit' => EditSegment::route('/{record}/edit'),
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
