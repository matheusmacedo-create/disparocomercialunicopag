<?php

namespace App\Filament\Resources\Senders\Schemas;

use App\Filament\Resources\Senders\Support\SenderActions;
use App\Filament\Resources\Senders\Tables\SendersTable;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Models\WhatsappMessage;
use App\Support\Mask;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** A ficha do número: dados da Meta, teto e uso por origem, pausas, webhook e trilha. Sem segredos. */
class SenderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('Remetente parado')
                    ->danger()
                    ->icon(Heroicon::OutlinedPause)
                    ->description(fn (Sender $record): string => SenderActions::motivoDaPausa($record)
                        .($record->sonda_em !== null ? ' Próxima sonda: '.$record->sonda_em->timezone('America/Sao_Paulo')->format('d/m H:i').'.' : ''))
                    ->visible(fn (Sender $record): bool => $record->pausado_em !== null || $record->bloqueadoPorQualidade())
                    ->columnSpanFull(),

                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Número')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('display_phone_number')->label('Número')->placeholder('Sincronize para ler'),
                                TextEntry::make('verified_name')->label('Nome verificado')->placeholder('—'),
                                TextEntry::make('qualidade')->label('Qualidade')->badge()->color(fn (?string $state): string => SendersTable::corDaQualidade($state)),
                                TextEntry::make('limite_tier')->label('Tier (limite da Meta)')->placeholder('—')->helperText(fn (Sender $record): ?string => ($tier = $record->limiteDoTier()) !== null ? 'A plataforma usa até '.(int) floor($tier * (float) config('disparo.fila.fracao_do_tier', 0.8)).' por dia' : null),
                                TextEntry::make('status_meta')->label('Status na Meta')->placeholder('—'),
                                TextEntry::make('provider')->label('Provedor')->formatStateUsing(fn (?string $state): string => Sender::provedores()[$state] ?? (string) $state),
                                TextEntry::make('finalidade')->label('Finalidade')->formatStateUsing(fn (?string $state): string => Sender::finalidades()[$state] ?? (string) $state),
                                TextEntry::make('phone_number_id')->label('Phone number ID')->copyable(),
                                TextEntry::make('waba_id')->label('WABA')->placeholder('—')->copyable(),
                                TextEntry::make('saude_resumo')
                                    ->label('Saúde (Kapso)')
                                    ->state(fn (Sender $record): ?string => self::saude((array) ($record->saude ?? [])))
                                    ->placeholder('Sem leitura')
                                    ->helperText(fn (Sender $record): ?string => $record->sincronizado_em ? 'Lida em '.$record->sincronizado_em->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : null)
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Teto e envios')
                            ->description('Templates nas últimas 24 h por origem. O que sai pelo painel da Kapso ou pelo KYC entra como "webhook"/"histórico".')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('teto_efetivo')
                                    ->label('Teto efetivo hoje')
                                    ->state(fn (Sender $record): string => $record->tetoEfetivo().' de '.$record->teto_diario.' configurado')
                                    ->helperText(fn (Sender $record): string => $record->aquecimento_inicio
                                        ? 'Aquecimento desde '.$record->aquecimento_inicio->format('d/m/Y').' ('.$record->diasDeAquecimentoConcluidos().' dia(s) GREEN concluídos)'
                                        : 'Sem aquecimento'),
                                TextEntry::make('enviados_24h')
                                    ->label('Enviados nas últimas 24 h')
                                    ->state(fn (Sender $record): string => $record->templatesNasUltimas24h().' (marketing: '.$record->templatesNasUltimas24h(Sender::CATEGORIA_MARKETING).' de '.$record->tetoDeMarketing().')'),
                                TextEntry::make('politica')
                                    ->label('Política')
                                    ->state(fn (Sender $record): string => sprintf('%s · %d/min · %s-%s (%s)', implode('/', $record->categoriasPermitidas()), $record->msgs_por_minuto, $record->janela_inicio, $record->janela_fim, $record->fuso)),
                                TextEntry::make('por_origem')
                                    ->label('Por origem (24 h)')
                                    ->state(fn (Sender $record): array => self::porOrigem($record))
                                    ->listWithLineBreaks()
                                    ->columnSpanFull(),
                            ]),

                        Section::make('Webhook')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('webhook_url')
                                    ->label('URL')
                                    ->state(fn (Sender $record): string => $record->provider === Sender::PROVIDER_META ? url('/api/webhooks/meta') : url('/api/webhooks/kapso/'.$record->webhook_key))
                                    ->copyable()
                                    ->columnSpanFull(),
                                TextEntry::make('webhook_segredo')
                                    ->label('Segredo')
                                    ->state(fn (Sender $record): string => filled($record->webhook_secret) ? 'Configurado (nunca exibido)' : 'Não configurado: registre o webhook')
                                    ->badge()
                                    ->color(fn (Sender $record): string => filled($record->webhook_secret) ? 'success' : 'danger'),
                                TextEntry::make('historico_importado_ate')
                                    ->label('Histórico da Kapso importado até')
                                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                                    ->placeholder('Nunca')
                                    ->color(fn (Sender $record): string => $record->historico_importado_ate === null || $record->historico_importado_ate->lt(now()->subDay()) ? 'warning' : 'gray'),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Trilha')
                            ->schema([
                                RepeatableEntry::make('trilha')
                                    ->hiddenLabel()
                                    ->state(fn (Sender $record) => SenderEvent::query()->where('sender_id', $record->id)->latest('id')->limit(40)->get())
                                    ->placeholder('Nada registrado ainda.')
                                    ->schema([
                                        TextEntry::make('evento')->hiddenLabel()->badge()->color(fn (string $state): string => match ($state) {
                                            'religado', 'webhook_registrado' => 'success',
                                            'pausado', 'politica_alterada', 'qualidade' => 'warning',
                                            'bloqueio_qualidade', 'disjuntor' => 'danger',
                                            default => 'gray',
                                        }),
                                        TextEntry::make('ator')
                                            ->hiddenLabel()
                                            ->state(fn (SenderEvent $record): string => (string) ($record->ator ?: 'sistema'))
                                            ->helperText(fn (SenderEvent $record): string => $record->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? ''),
                                        TextEntry::make('detalhes')
                                            ->hiddenLabel()
                                            ->state(fn (SenderEvent $record): ?string => self::detalhes((array) ($record->detalhes ?? [])))
                                            ->color('gray')
                                            ->visible(fn (SenderEvent $record): bool => filled($record->detalhes)),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    /** @return list<string> */
    private static function porOrigem(Sender $record): array
    {
        $rotulos = [
            WhatsappMessage::ORIGEM_CAMPANHA => 'Campanhas',
            WhatsappMessage::ORIGEM_REGUA => 'Réguas',
            WhatsappMessage::ORIGEM_MANUAL => 'Manual (testes, respostas)',
            WhatsappMessage::ORIGEM_WEBHOOK => 'Visto pelo webhook (painel da Kapso / KYC)',
            WhatsappMessage::ORIGEM_HISTORICO_KAPSO => 'Histórico importado',
            WhatsappMessage::ORIGEM_BROADCAST_KAPSO => 'Broadcasts da Kapso',
        ];

        return collect($rotulos)->map(fn (string $rotulo, string $origem): string => $rotulo.': '.$record->templatesNasUltimas24h(null, $origem))->values()->all();
    }

    /** Só o que não é segredo e é escalar: a resposta da Kapso pode ecoar chaves. @param  array<string, mixed>  $saude */
    private static function saude(array $saude): ?string
    {
        $linhas = collect($saude)
            ->reject(fn ($valor, $chave): bool => preg_match('/secret|key|token|password/i', (string) $chave) === 1)
            ->map(fn ($valor, $chave): ?string => is_scalar($valor) || $valor === null ? $chave.': '.(is_bool($valor) ? ($valor ? 'sim' : 'não') : (string) $valor) : null)
            ->filter()
            ->implode(' · ');

        return $linhas === '' ? null : mb_substr(Mask::scrub($linhas), 0, 600);
    }

    /** @param  array<string, mixed>  $detalhes */
    private static function detalhes(array $detalhes): ?string
    {
        $texto = collect($detalhes)
            ->reject(fn ($valor, $chave): bool => preg_match('/secret|key|token|password/i', (string) $chave) === 1)
            ->map(fn ($valor, $chave): string => $chave.': '.(is_scalar($valor) || $valor === null ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE)))
            ->implode(' · ');

        return $texto === '' ? null : mb_substr(Mask::scrub($texto), 0, 400);
    }
}
