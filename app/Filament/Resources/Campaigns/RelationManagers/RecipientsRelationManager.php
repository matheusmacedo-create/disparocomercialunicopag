<?php

namespace App\Filament\Resources\Campaigns\RelationManagers;

use App\Filament\Resources\Campaigns\Support\CampaignActions;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Support\Mask;
use App\Support\Permissions;
use App\Support\Telefone;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Destinatários da campanha (congelados na aprovação). Telefone mascarado e sem variáveis para quem não
 * tem `view pii`; a exportação segue a mesma regra.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    protected static ?string $title = 'Destinatários';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CAMPAIGNS);
    }

    public function table(Table $table): Table
    {
        $pii = Permissions::allows(Permissions::VIEW_PII);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('contact'))
            ->columns([
                TextColumn::make('telefone')
                    ->label('Telefone')
                    ->formatStateUsing(fn (string $state): string => $pii ? Telefone::formatar($state) : Telefone::mascarar($state))
                    ->searchable($pii),
                TextColumn::make('contact.nome')->label('Contato')->placeholder('—')->limit(30),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => CampaignRecipient::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => CampaignRecipient::statusColor($state)),
                TextColumn::make('motivo')
                    ->label('Motivo')
                    ->formatStateUsing(fn (?string $state): string => CampaignRecipient::motivoLabels()[$state] ?? (string) $state)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('erro')
                    ->label('Erro')
                    ->formatStateUsing(fn (?string $state): string => Mask::scrub($state))
                    ->limit(60)
                    ->tooltip(fn (CampaignRecipient $record): ?string => filled($record->erro) ? Mask::scrub($record->erro) : null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('enviado_em')->label('Enviado')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->sortable(),
                TextColumn::make('entregue_em')->label('Entregue')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->toggleable(),
                TextColumn::make('lido_em')->label('Lido')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('respondido_em')->label('Respondeu')->dateTime('d/m H:i', 'America/Sao_Paulo')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tentativas')->label('Tentativas')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variaveis')
                    ->label('Variáveis')
                    ->state(fn (CampaignRecipient $record): string => collect((array) ($record->variaveis ?? []))->map(fn ($v, $k): string => $k.'='.$v)->implode(' | '))
                    ->visible($pii)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->multiple()->options(CampaignRecipient::statusLabels()),
                SelectFilter::make('motivo')->label('Motivo')->multiple()->options(CampaignRecipient::motivoLabels()),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 100])
            ->headerActions([
                Action::make('exportar')
                    ->label('Exportar CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_CAMPAIGNS))
                    ->action(function (RelationManager $livewire): StreamedResponse {
                        /** @var Campaign $campanha */
                        $campanha = $livewire->getOwnerRecord();
                        $pii = Permissions::allows(Permissions::VIEW_PII);
                        $caminho = CampaignActions::exportarCsv($campanha, $pii);
                        $campanha->registrarAcao('exportada', ['arquivo' => basename($caminho), 'com_pii' => $pii]);

                        return Storage::disk('local')->download($caminho, basename($caminho));
                    }),
            ]);
    }
}
