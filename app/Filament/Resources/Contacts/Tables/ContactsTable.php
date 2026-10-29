<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Filament\Resources\Contacts\Support\ContactActions;
use App\Filament\Support\Pii;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Segment;
use App\Models\Tag;
use App\Services\Segmentos\ConsultaDeSegmento;
use App\Support\Permissions;
use App\Support\Telefone;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        $fuso = 'America/Sao_Paulo';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lists:id,nome', 'tags:id,nome']))
            ->columns([
                TextColumn::make('telefone')
                    ->label('Telefone')
                    ->state(fn (Contact $record): string => Pii::telefone($record->telefone))
                    // A caixa de busca inteira passa por aqui: telefone, nome, empresa, documento (por hash)
                    // e, só para quem pode ver, e-mail. Uma coluna só evita OR espalhado.
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::buscar($query, $search)),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->description(fn (Contact $record): ?string => $record->empresa)
                    ->placeholder('—')
                    ->wrap()
                    ->sortable(),
                TextColumn::make('origem')
                    ->label('Origem')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => Contact::origemLabels()[$state] ?? (string) $state)
                    ->toggleable(),
                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => Contact::tipoLabels()[$state] ?? (string) $state)
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => Contact::statusLabels()[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => Contact::statusColor($state))
                    ->sortable(),
                TextColumn::make('lists.nome')
                    ->label('Bases')
                    ->badge()
                    ->color('primary')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('tags.nome')
                    ->label('Tags')
                    ->badge()
                    ->color('gray')
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ultimo_envio_em')
                    ->label('Último envio')
                    ->dateTime('d/m/Y H:i', $fuso)
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ultima_resposta_em')
                    ->label('Última resposta')
                    ->dateTime('d/m/Y H:i', $fuso)
                    ->placeholder('Nunca')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('consentimento')
                    ->label('Consent.')
                    ->state(fn (Contact $record): bool => $record->temConsentimento())
                    ->boolean()
                    ->tooltip(fn (Contact $record): ?string => $record->temConsentimento() ? (Contact::consentimentoLabels()[$record->consentimento_origem] ?? $record->consentimento_origem) : 'Sem consentimento registrado'),
                TextColumn::make('created_at')
                    ->label('Cadastrado em')
                    ->dateTime('d/m/Y', $fuso)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(Contact::statusLabels()),
                SelectFilter::make('origem')->label('Origem')->options(Contact::origemLabels()),
                SelectFilter::make('tipo')->label('Tipo')->options(Contact::tipoLabels()),
                SelectFilter::make('base')
                    ->label('Base')
                    ->options(fn (): array => ContactList::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('lists', fn (Builder $q) => $q->where('contact_lists.id', (int) $data['value']))
                        : $query),
                SelectFilter::make('tag')
                    ->label('Tag')
                    ->options(fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('tags', fn (Builder $q) => $q->where('tags.id', (int) $data['value']))
                        : $query),
                SelectFilter::make('segmento')
                    ->label('Segmento')
                    ->options(fn (): array => Segment::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $segmento = filled($data['value'] ?? null) ? Segment::query()->find((int) $data['value']) : null;

                        return $segmento === null
                            ? $query
                            : $query->whereIn('contacts.id', ConsultaDeSegmento::doSegmento($segmento)->select('contacts.id'));
                    }),
                TernaryFilter::make('consentimento')
                    ->label('Consentimento')
                    ->trueLabel('Com consentimento registrado')
                    ->falseLabel('Sem consentimento')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('consentimento_origem'),
                        false: fn (Builder $query) => $query->whereNull('consentimento_origem'),
                    ),
                TernaryFilter::make('sem_whatsapp')
                    ->label('Sem WhatsApp (131026)')
                    ->trueLabel('Em quarentena')
                    ->falseLabel('Com WhatsApp')
                    ->queries(
                        true: fn (Builder $query) => $query->where('sem_whatsapp_em', '>=', now()->subDays((int) config('disparo.guardas.sem_whatsapp_dias', 90))),
                        false: fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull('sem_whatsapp_em')->orWhere('sem_whatsapp_em', '<', now()->subDays((int) config('disparo.guardas.sem_whatsapp_dias', 90)))),
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ContactActions::bulkAdicionarBase(),
                    ContactActions::bulkAdicionarTag(),
                    ContactActions::bulkInscreverRegua(),
                    ContactActions::bulkBloquear(),
                ]),
            ]);
    }

    private static function buscar(Builder $query, string $search): Builder
    {
        $search = trim($search);
        $digitos = preg_replace('/\D/', '', $search) ?? '';

        return $query->where(function (Builder $q) use ($search, $digitos): void {
            $q->where('nome', 'like', '%'.$search.'%')
                ->orWhere('empresa', 'like', '%'.$search.'%');

            if (strlen($digitos) >= 8) {
                $q->orWhere(fn (Builder $q) => $q->porTelefone($search));
            }

            // CPF/CNPJ só pelo hash: o documento em si está criptografado e não é pesquisável.
            if (in_array(strlen($digitos), [11, 14], true)) {
                $q->orWhere('documento_hash', Telefone::hashDocumento($digitos));
            }

            if (Permissions::allows(Permissions::VIEW_PII)) {
                $q->orWhere('email', 'like', '%'.$search.'%');
            }
        });
    }
}
