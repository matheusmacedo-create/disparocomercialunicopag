<?php

namespace App\Filament\Resources\Contacts\RelationManagers;

use App\Models\ContactEvent;
use App\Support\Permissions;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Trilha imutável do contato: bloqueios, liberações, consentimento, leitura de conversa, anonimização. */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Trilha';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Permissions::allows(Permissions::VIEW_CONTACTS);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s', 'America/Sao_Paulo'),
                TextColumn::make('evento')
                    ->label('Evento')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => ContactEvent::labels()[$state] ?? (string) $state),
                TextColumn::make('ator')->label('Quem')->placeholder('sistema'),
                TextColumn::make('detalhes')
                    ->label('Detalhes')
                    ->state(fn (ContactEvent $record): ?string => self::resumo((array) ($record->detalhes ?? [])))
                    ->wrap()
                    ->placeholder('—')
                    // Justificativas e motivos podem citar o titular: só com `view pii`.
                    ->visible(fn (): bool => Permissions::allows(Permissions::VIEW_PII)),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }

    /** @param  array<string, mixed>  $detalhes */
    private static function resumo(array $detalhes): ?string
    {
        $partes = [];

        foreach ($detalhes as $chave => $valor) {
            $partes[] = $chave.': '.(is_scalar($valor) || $valor === null ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE));
        }

        return $partes === [] ? null : implode(' · ', $partes);
    }
}
