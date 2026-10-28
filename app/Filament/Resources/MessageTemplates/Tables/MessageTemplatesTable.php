<?php

namespace App\Filament\Resources\MessageTemplates\Tables;

use App\Filament\Support\ComandoDoPainel;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MessageTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sender'))
            ->columns([
                TextColumn::make('sender.nome')->label('Remetente')->sortable(),
                TextColumn::make('nome')
                    ->label('Template')
                    ->searchable()
                    ->sortable()
                    ->description(fn (MessageTemplate $record): string => mb_substr($record->corpo(), 0, 90).(mb_strlen($record->corpo()) > 90 ? '…' : ''))
                    ->wrap(),
                TextColumn::make('idioma')->label('Idioma')->toggleable(),
                TextColumn::make('categoria')
                    ->label('Categoria')
                    ->badge()
                    ->color(fn (?string $state): string => self::corDaCategoria($state))
                    ->description(fn (MessageTemplate $record): ?string => self::alertaDeCategoria($record)),
                TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state): string => self::corDoStatus($state)),
                TextColumn::make('qualidade_score')
                    ->label('Qualidade')
                    ->state(fn (MessageTemplate $record): string => strtoupper((string) (($record->qualidade ?? [])['score'] ?? 'UNKNOWN')))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'GREEN' => 'success',
                        'YELLOW' => 'warning',
                        'RED' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('formato')
                    ->label('Formato')
                    ->state(fn (MessageTemplate $record): string => self::formato($record))
                    ->toggleable(),
                TextColumn::make('variaveis')
                    ->label('Variáveis')
                    ->state(fn (MessageTemplate $record): string => implode(', ', array_map(fn (string $v): string => '{{'.$v.'}}', $record->variaveisDoCorpo())) ?: '—'),
                TextColumn::make('sincronizado_em')->label('Sincronizado')->since()->placeholder('Nunca')->sortable(),
            ])
            ->filters([
                SelectFilter::make('sender_id')->label('Remetente')->options(fn (): array => Sender::query()->orderBy('nome')->pluck('nome', 'id')->all()),
                SelectFilter::make('categoria')->label('Categoria')->options(['MARKETING' => 'MARKETING', 'UTILITY' => 'UTILITY', 'AUTHENTICATION' => 'AUTHENTICATION']),
                SelectFilter::make('status')->label('Status')->options(['APPROVED' => 'APPROVED', 'PENDING' => 'PENDING', 'REJECTED' => 'REJECTED', 'PAUSED' => 'PAUSED', 'DISABLED' => 'DISABLED']),
            ])
            ->defaultSort('nome')
            ->headerActions([
                self::sincronizar(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function sincronizar(): Action
    {
        return Action::make('sincronizar')
            ->label('Sincronizar com a Meta')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_SENDERS))
            ->action(function (): void {
                $resultado = ComandoDoPainel::rodar('disparo:sincronizar-templates');

                $resultado['ok']
                    ? Notification::make()->success()->title('Templates sincronizados')->body(nl2br(e($resultado['saida'])))->send()
                    : Notification::make()->warning()->title('Não foi possível sincronizar')->body(e((string) $resultado['erro']))->persistent()->send();
            });
    }

    public static function corDaCategoria(?string $categoria): string
    {
        return match (strtoupper((string) $categoria)) {
            'MARKETING' => 'warning',
            'UTILITY' => 'info',
            default => 'gray',
        };
    }

    public static function corDoStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'APPROVED' => 'success',
            'PENDING' => 'warning',
            default => 'danger',
        };
    }

    public static function formato(MessageTemplate $record): string
    {
        $partes = [];

        if (($cabecalho = $record->formatoDoCabecalho()) !== null) {
            $partes[] = 'cabeçalho '.strtolower($cabecalho);
        }

        $partes[] = strtolower((string) ($record->parameter_format ?: 'positional'));

        if ($record->temBotaoDeUrlDinamica()) {
            $partes[] = 'URL dinâmica';
        }

        return implode(' · ', $partes);
    }

    /** MARKETING num remetente que só manda UTILITY: a campanha nem chega a usar. */
    public static function alertaDeCategoria(MessageTemplate $record): ?string
    {
        if ($record->sender !== null && ! $record->sender->permiteCategoria($record->categoria)) {
            return 'Remetente só permite '.implode('/', $record->sender->categoriasPermitidas());
        }

        return null;
    }
}
