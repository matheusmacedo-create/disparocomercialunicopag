<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\Pii;
use App\Models\Contact;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A ficha do contato. Abaixo dela, os gerenciadores de relação (mensagens, campanhas, réguas,
 * bloqueios, trilha). Dado pessoal passa por {@see Pii}; atributos do CSV só com `view pii`.
 */
class ContactInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $fuso = 'America/Sao_Paulo';

        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Dados')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('telefone')->label('Telefone')->state(fn (Contact $record): string => Pii::telefone($record->telefone))->copyable(Pii::podeVer()),
                                TextEntry::make('nome')->label('Nome')->placeholder('—'),
                                TextEntry::make('primeiro_nome')->label('Primeiro nome (variável)')->placeholder('—'),
                                TextEntry::make('email')->label('E-mail')->state(fn (Contact $record): ?string => Pii::email($record->email))->placeholder('—'),
                                TextEntry::make('documento')->label('CPF/CNPJ')->state(fn (Contact $record): ?string => Pii::documento($record->documento))->placeholder('—'),
                                TextEntry::make('empresa')->label('Empresa / loja')->placeholder('—'),
                                TextEntry::make('origem')->label('Origem')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => Contact::origemLabels()[$state] ?? (string) $state),
                                TextEntry::make('tipo')->label('Tipo')->badge()->color('gray')->formatStateUsing(fn (?string $state): string => Contact::tipoLabels()[$state] ?? (string) $state),
                                TextEntry::make('account_id')->label('Conta no unico-api')->placeholder('—')->copyable(),
                                TextEntry::make('kapso_contact_id')->label('Contato na Kapso')->placeholder('—'),
                                TextEntry::make('created_at')->label('Cadastrado em')->dateTime('d/m/Y H:i', $fuso),
                                TextEntry::make('updated_at')->label('Atualizado em')->dateTime('d/m/Y H:i', $fuso),
                                TextEntry::make('observacoes')->label('Observações')->placeholder('—')->columnSpanFull(),
                            ]),

                        Section::make('Atributos importados do CSV')
                            ->description('Viram {{atributos.coluna}} nos templates. Só quem tem "ver dados sensíveis" vê os valores.')
                            ->visible(fn (Contact $record): bool => Permissions::allows(Permissions::VIEW_PII) && filled($record->atributos))
                            ->schema([
                                KeyValueEntry::make('atributos')->hiddenLabel()->keyLabel('Coluna')->valueLabel('Valor'),
                            ]),

                        Section::make('Bases e tags')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('lists.nome')->label('Bases')->badge()->color('primary')->placeholder('Em nenhuma base'),
                                TextEntry::make('tags.nome')->label('Tags')->badge()->color('gray')->placeholder('Sem tags'),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Estado')
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => Contact::statusLabels()[$state] ?? (string) $state)
                                    ->color(fn (?string $state): string => Contact::statusColor($state)),
                                TextEntry::make('optout_em')
                                    ->label('Pediu para parar em')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->helperText(fn (Contact $record): ?string => $record->optout_motivo ? 'Motivo: '.$record->optout_motivo.($record->optout_origem ? ' · origem: '.$record->optout_origem : '') : null)
                                    ->color('danger')
                                    ->visible(fn (Contact $record): bool => $record->optout_em !== null),
                                TextEntry::make('sem_whatsapp_em')
                                    ->label('Sem WhatsApp (131026)')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->helperText(fn (Contact $record): string => $record->semWhatsAppRecente() ? 'Em quarentena: fora de qualquer envio.' : 'Quarentena vencida.')
                                    ->color(fn (Contact $record): string => $record->semWhatsAppRecente() ? 'warning' : 'gray')
                                    ->visible(fn (Contact $record): bool => $record->sem_whatsapp_em !== null),
                                TextEntry::make('marketing_carencia_ate')
                                    ->label('Carência de marketing (131049) até')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->color('warning')
                                    ->visible(fn (Contact $record): bool => $record->emCarenciaDeMarketing()),
                                TextEntry::make('recusou_em')
                                    ->label('Recusou oferta em')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->helperText(fn (Contact $record): string => $record->recusas_total.' recusa(s); fora de MARKETING por 90 dias após a última.')
                                    ->color('warning')
                                    ->visible(fn (Contact $record): bool => $record->recusou_em !== null),
                                TextEntry::make('provavel_bloqueio_em')
                                    ->label('Provável bloqueio desde')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->helperText('Template enviado e nunca entregue: fora de MARKETING por 180 dias.')
                                    ->color('warning')
                                    ->visible(fn (Contact $record): bool => $record->provavel_bloqueio_em !== null),
                                TextEntry::make('anonimizado_em')
                                    ->label('Anonimizado em')
                                    ->dateTime('d/m/Y H:i', $fuso)
                                    ->visible(fn (Contact $record): bool => $record->anonimizado_em !== null),
                                IconEntry::make('consentimento')
                                    ->label('Consentimento registrado')
                                    ->state(fn (Contact $record): bool => $record->temConsentimento())
                                    ->boolean(),
                                TextEntry::make('consentimento_origem')
                                    ->label('Como consentiu')
                                    ->formatStateUsing(fn (?string $state): string => Contact::consentimentoLabels()[$state] ?? (string) $state)
                                    ->helperText(fn (Contact $record): ?string => collect([
                                        $record->consentimento_em?->timezone($fuso)->format('d/m/Y H:i'),
                                        $record->consentimento_canal ? 'canal: '.$record->consentimento_canal : null,
                                        $record->consentimento_detalhes,
                                    ])->filter()->implode(' · ') ?: null)
                                    ->visible(fn (Contact $record): bool => $record->temConsentimento()),
                            ]),

                        Section::make('Atividade')
                            ->schema([
                                TextEntry::make('contadores')
                                    ->label('Envios · entregues · lidos · respostas · falhas')
                                    ->state(fn (Contact $record): string => implode(' · ', [$record->envios_total, $record->entregues_total, $record->lidos_total, $record->respostas_total, $record->falhas_total])),
                                TextEntry::make('ultimo_envio_em')->label('Último envio')->dateTime('d/m/Y H:i', $fuso)->placeholder('Nunca'),
                                TextEntry::make('ultimo_template_em')->label('Último template')->dateTime('d/m/Y H:i', $fuso)->placeholder('Nunca'),
                                TextEntry::make('ultima_resposta_em')->label('Última resposta')->dateTime('d/m/Y H:i', $fuso)->placeholder('Nunca'),
                                TextEntry::make('janela')
                                    ->label('Janela de 24 h')
                                    ->state(fn (Contact $record): string => ($ate = WhatsappMessage::janelaAbertaAte($record->ultima_resposta_em))
                                        ? 'Aberta até '.$ate->timezone($fuso)->format('d/m H:i')
                                        : 'Fechada (só template)')
                                    ->badge()
                                    ->color(fn (Contact $record): string => WhatsappMessage::janelaAbertaAte($record->ultima_resposta_em) ? 'success' : 'gray'),
                            ]),
                    ]),
            ]);
    }
}
