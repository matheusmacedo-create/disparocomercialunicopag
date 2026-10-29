<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignRecipient;
use App\Support\Mask;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A ficha da campanha: o que foi aprovado, o que saiu, a última prévia e a trilha humana. Amostra da
 * prévia (nomes) só com `view pii`.
 */
class CampaignInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Callout::make('Ambiente em SIMULAÇÃO')
                    ->warning()
                    ->icon(Heroicon::OutlinedBeaker)
                    ->description('DISPARO_ENVIO_HABILITADO=false: a campanha roda inteira como prévia executada (destinatários "simulado", com o motivo real de quem seria pulado) e nada chega ao WhatsApp. Só quem administra o servidor liga o envio.')
                    ->visible(fn (): bool => ! config('disparo.envio_habilitado'))
                    ->columnSpanFull(),

                Callout::make('Pausada')
                    ->danger()
                    ->icon(Heroicon::OutlinedPause)
                    ->description(fn (Campaign $record): string => (string) $record->pausada_motivo)
                    ->visible(fn (Campaign $record): bool => filled($record->pausada_motivo) && in_array($record->status, [Campaign::STATUS_PAUSADA, Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_CANCELADA], true))
                    ->columnSpanFull(),

                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Campanha')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => Campaign::statusLabels()[$state] ?? (string) $state)
                                    ->color(fn (?string $state): string => Campaign::statusColor($state)),
                                TextEntry::make('sender.nome')->label('Remetente')->helperText(fn (Campaign $record): ?string => $record->sender?->display_phone_number),
                                TextEntry::make('objetivo')->label('Objetivo')->formatStateUsing(fn (?string $state): string => Campaign::objetivoLabels()[$state] ?? (string) $state),
                                TextEntry::make('template_nome')
                                    ->label('Template')
                                    ->helperText(fn (Campaign $record): string => $record->template_idioma.($record->template_categoria ? ' · '.$record->template_categoria.' (congelada na aprovação)' : ' · categoria congela na aprovação')),
                                TextEntry::make('prioridade')->label('Prioridade'),
                                TextEntry::make('agendada_para')->label('Agendada para')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('creator.name')->label('Criada por')->placeholder('—'),
                                TextEntry::make('approver.name')
                                    ->label('Aprovada por')
                                    ->placeholder('—')
                                    ->helperText(fn (Campaign $record): ?string => $record->aprovado_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i')),
                                TextEntry::make('iniciada_em')->label('Iniciada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('concluida_em')->label('Encerrada em')->dateTime('d/m/Y H:i', 'America/Sao_Paulo')->placeholder('—'),
                                TextEntry::make('regras_resumo')
                                    ->label('Regras')
                                    ->state(fn (Campaign $record): string => self::regras($record))
                                    ->columnSpan(2),
                                TextEntry::make('observacoes')->label('Observações')->placeholder('—')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-line']),
                            ]),

                        Section::make('Totais')
                            ->description('Contagem dos destinatários congelados na aprovação. Taxas sobre o degrau anterior (entregues sobre enviados, lidos sobre entregues).')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('total_destinatarios')->label('Destinatários'),
                                TextEntry::make('enviados')->label('Enviados')->helperText(fn (Campaign $record): string => self::taxa($record->enviados, $record->total_destinatarios)),
                                TextEntry::make('entregues')->label('Entregues')->helperText(fn (Campaign $record): string => self::taxa($record->entregues, $record->enviados)),
                                TextEntry::make('lidos')->label('Lidos')->helperText(fn (Campaign $record): string => self::taxa($record->lidos, $record->entregues)),
                                TextEntry::make('respondidos')->label('Responderam')->helperText(fn (Campaign $record): string => self::taxa($record->respondidos, $record->entregues)),
                                TextEntry::make('falhas')->label('Falhas')->color(fn (Campaign $record): string => $record->falhas > 0 ? 'danger' : 'gray'),
                                TextEntry::make('pulados')->label('Pulados')->helperText('Guarda na hora do envio (opt-out, conversa aberta, variável vazia...).'),
                                TextEntry::make('custo_estimado')
                                    ->label('Custo estimado')
                                    ->state(fn (Campaign $record): string => 'R$ '.number_format((float) ($record->custo_estimado ?? 0), 2, ',', '.'))
                                    ->helperText(fn (Campaign $record): string => 'tranches enviadas: '.$record->tranches_enviadas),
                            ]),

                        Section::make('Prévia')
                            ->description(fn (Campaign $record): string => $record->simulacao === null
                                ? 'Ainda sem prévia. Gere a prévia para ver quem receberia e por que cada um ficaria de fora.'
                                : ($record->previaAtual() ? 'Prévia atual (posterior à última edição).' : 'ATENÇÃO: a campanha foi editada depois desta prévia. Gere outra antes de aprovar.'))
                            ->columns(3)
                            ->visible(fn (Campaign $record): bool => $record->simulacao !== null)
                            ->schema([
                                TextEntry::make('previa_elegiveis')
                                    ->label('Elegíveis')
                                    ->state(fn (Campaign $record): string => (string) ($record->simulacao['elegiveis'] ?? 0))
                                    ->badge()
                                    ->color('success'),
                                TextEntry::make('previa_excluidos')
                                    ->label('Excluídos')
                                    ->state(fn (Campaign $record): string => (int) ($record->simulacao['excluidos'] ?? 0).' de '.(int) ($record->simulacao['total'] ?? 0))
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('previa_gerada')
                                    ->label('Gerada em')
                                    ->state(fn (Campaign $record): string => isset($record->simulacao['gerada_em']) ? CarbonImmutable::parse((string) $record->simulacao['gerada_em'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '—')
                                    ->badge()
                                    ->color(fn (Campaign $record): string => $record->previaAtual() ? 'success' : 'danger'),
                                TextEntry::make('previa_motivos')
                                    ->label('Motivos de exclusão')
                                    ->state(fn (Campaign $record): array => collect((array) ($record->simulacao['motivos'] ?? []))
                                        ->map(fn ($n, $motivo): string => (CampaignRecipient::motivoLabels()[$motivo] ?? $motivo).': '.$n)
                                        ->values()
                                        ->all())
                                    ->listWithLineBreaks()
                                    ->placeholder('Nenhum excluído.')
                                    ->columnSpanFull(),
                                TextEntry::make('previa_custo')
                                    ->label('Custo estimado')
                                    ->state(fn (Campaign $record): string => 'R$ '.number_format((float) ($record->simulacao['custo_estimado'] ?? 0), 2, ',', '.').' ('.(string) ($record->simulacao['categoria'] ?? '?').')'),
                                TextEntry::make('previa_teto')
                                    ->label('Teto restante hoje')
                                    ->state(fn (Campaign $record): string => (int) ($record->simulacao['teto_restante_hoje'] ?? 0).' de '.(int) ($record->simulacao['teto_efetivo'] ?? 0)),
                                TextEntry::make('previa_dias')
                                    ->label('Dias estimados')
                                    ->state(fn (Campaign $record): string => (string) ($record->simulacao['dias_estimados'] ?? '?'))
                                    ->helperText(fn (Campaign $record): ?string => (int) ($record->simulacao['usam_padrao'] ?? 0) > 0 ? (int) $record->simulacao['usam_padrao'].' contato(s) usarão o padrão "tudo bem".' : null),
                                TextEntry::make('previa_avisos')
                                    ->label('Avisos')
                                    ->state(fn (Campaign $record): array => array_values((array) ($record->simulacao['avisos'] ?? [])))
                                    ->listWithLineBreaks()
                                    ->color('warning')
                                    ->visible(fn (Campaign $record): bool => filled($record->simulacao['avisos'] ?? null))
                                    ->columnSpanFull(),
                                TextEntry::make('previa_amostra')
                                    ->label('Amostra (como vai sair)')
                                    ->state(fn (Campaign $record): array => collect((array) ($record->simulacao['amostra'] ?? []))
                                        ->map(fn (array $item): string => (string) ($item['contato'] ?? '').' → '.(string) ($item['variaveis'] ?? ''))
                                        ->values()
                                        ->all())
                                    ->listWithLineBreaks()
                                    ->visible(fn (Campaign $record): bool => Permissions::allows(Permissions::VIEW_PII) && filled($record->simulacao['amostra'] ?? null))
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Trilha')
                            ->description('Quem fez o quê, inclusive o sistema (disjuntor, tranches, sonda).')
                            ->schema([
                                RepeatableEntry::make('trilha')
                                    ->hiddenLabel()
                                    ->state(fn (Campaign $record) => $record->events()->limit(40)->get())
                                    ->placeholder('Nada registrado ainda.')
                                    ->schema([
                                        TextEntry::make('evento')
                                            ->hiddenLabel()
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => CampaignEvent::labels()[$state] ?? $state)
                                            ->color(fn (string $state): string => match ($state) {
                                                'aprovada', 'concluida', 'sonda_passou', 'tranche_liberada' => 'success',
                                                'pausada', 'reprovada', 'aprovacao_invalidada', 'categoria_mudou' => 'warning',
                                                'cancelada', 'disjuntor', 'engajamento' => 'danger',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('ator')
                                            ->hiddenLabel()
                                            ->state(fn (CampaignEvent $record): string => (string) ($record->ator ?: 'sistema'))
                                            ->helperText(fn (CampaignEvent $record): string => $record->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? ''),
                                        TextEntry::make('detalhes')
                                            ->hiddenLabel()
                                            ->state(fn (CampaignEvent $record): ?string => self::detalhes((array) ($record->detalhes ?? [])))
                                            ->color('gray')
                                            ->visible(fn (CampaignEvent $record): bool => filled($record->detalhes)),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    private static function taxa(int $parte, int $total): string
    {
        return $total > 0 ? number_format(100 * $parte / $total, 1, ',', '.').'%' : '—';
    }

    private static function regras(Campaign $record): string
    {
        $partes = [];

        if ($record->regra('ignorar_conversa_aberta')) {
            $partes[] = 'ignora conversa aberta (utilidade)';
        }

        if (($max = (int) $record->regra('max_por_dia', 0)) > 0) {
            $partes[] = 'até '.$max.' por dia';
        }

        $dias = $record->diasDaSemana();

        if (count($dias) < 7) {
            $partes[] = 'dias: '.implode(', ', array_map(fn (int $d): string => mb_substr(CampaignForm::DIAS[$d] ?? (string) $d, 0, 3), $dias));
        }

        if (! empty(((array) ($record->variaveis ?? []))['permitir_links'])) {
            $partes[] = 'links em variáveis liberados';
        }

        return $partes === [] ? 'Só as guardas padrão do remetente.' : ucfirst(implode(' · ', $partes)).'.';
    }

    /** @param  array<string, mixed>  $detalhes */
    private static function detalhes(array $detalhes): ?string
    {
        $texto = collect($detalhes)
            ->map(function ($valor, $chave): string {
                $valor = is_scalar($valor) || $valor === null ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return $chave.': '.$valor;
            })
            ->implode(' · ');

        return $texto === '' ? null : mb_substr(Mask::scrub($texto), 0, 400);
    }
}
