<?php

declare(strict_types=1);

namespace App\Services\Contatos;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\Suppression;
use App\Support\Mask;
use App\Support\Telefone;
use Carbon\CarbonInterface;

/**
 * Direito de acesso (LGPD, Art. 18, II): tudo o que a plataforma tem sobre o titular, em estrutura
 * pronta para virar JSON ou PDF - o contato, os metadados das mensagens (o texto do cliente não volta
 * para ele por aqui: é dele, e a finalidade é dizer o que tratamos), as campanhas e réguas em que entrou,
 * os bloqueios e a trilha. Quem chama é o painel, atrás de `view pii`.
 */
final class ExportarDadosDoTitular
{
    /** @return array<string, mixed> */
    public function executar(Contact $contato): array
    {
        $data = fn (?CarbonInterface $momento): ?string => $momento?->toIso8601String();

        return [
            'gerado_em' => now()->toIso8601String(),
            'contato' => [
                'telefone' => Telefone::anonimizado($contato->telefone) ? null : Telefone::formatar($contato->telefone),
                'nome' => $contato->nome,
                'primeiro_nome' => $contato->primeiro_nome,
                'email' => $contato->email,
                'documento' => $contato->documento !== null ? Mask::documentoFormatado($contato->documento) : null,
                'empresa' => $contato->empresa,
                'origem' => $contato->origem,
                'tipo' => $contato->tipo,
                'account_id' => $contato->account_id,
                'atributos' => (array) ($contato->atributos ?? []),
                'status' => $contato->status,
                'consentimento' => array_filter([
                    'origem' => $contato->consentimento_origem,
                    'canal' => $contato->consentimento_canal,
                    'detalhes' => $contato->consentimento_detalhes,
                    'em' => $data($contato->consentimento_em),
                ]),
                'optout' => array_filter(['em' => $data($contato->optout_em), 'motivo' => $contato->optout_motivo, 'origem' => $contato->optout_origem]),
                'criado_em' => $data($contato->created_at),
                'ultimo_envio_em' => $data($contato->ultimo_envio_em),
                'ultima_resposta_em' => $data($contato->ultima_resposta_em),
                'anonimizado_em' => $data($contato->anonimizado_em),
                'totais' => ['envios' => $contato->envios_total, 'entregues' => $contato->entregues_total, 'lidos' => $contato->lidos_total, 'respostas' => $contato->respostas_total, 'falhas' => $contato->falhas_total],
            ],
            'bases' => $contato->lists()->get()->map(fn ($lista): array => ['nome' => $lista->nome, 'dono' => $lista->dono, 'adicionado_em' => $lista->pivot->adicionado_em])->values()->all(),
            'tags' => $contato->tags()->pluck('nome')->values()->all(),
            'mensagens' => $contato->messages()->get()->map(fn ($m): array => [
                'direcao' => $m->direcao,
                'tipo' => $m->tipo,
                'template' => $m->template_nome,
                'status' => $m->status,
                'origem' => $m->origem,
                'enviado_em' => $data($m->enviado_em),
                'entregue_em' => $data($m->entregue_em),
                'lido_em' => $data($m->lido_em),
                'remetente' => $m->sender?->rotulo(),
            ])->values()->all(),
            'campanhas' => $contato->campaignRecipients()->with('campaign')->get()->map(fn ($d): array => [
                'campanha' => $d->campaign?->nome,
                'status' => $d->status,
                'motivo' => $d->motivo,
                'enviado_em' => $data($d->enviado_em),
                'respondido_em' => $data($d->respondido_em),
                'convertido_em' => $data($d->convertido_em),
            ])->values()->all(),
            'reguas' => $contato->journeyEnrollments()->with('journey')->get()->map(fn ($i): array => [
                'regua' => $i->journey?->nome,
                'status' => $i->status,
                'motivo_saida' => $i->motivo_saida,
                'iniciada_em' => $data($i->iniciada_em),
                'encerrada_em' => $data($i->encerrada_em),
            ])->values()->all(),
            'bloqueios' => Suppression::query()->where('telefone_hash', $contato->telefone_hash)->get()->map(fn (Suppression $s): array => [
                'motivo' => $s->motivo,
                'escopo' => $s->escopo,
                'origem' => $s->origem,
                'remetente' => $s->sender?->rotulo(),
                'criado_em' => $data($s->created_at),
                'liberado_em' => $data($s->liberada_em),
            ])->values()->all(),
            'trilha' => ContactEvent::query()->where('telefone_hash', $contato->telefone_hash)->orderBy('id')->get()->map(fn (ContactEvent $e): array => [
                'evento' => $e->evento,
                'em' => $data($e->created_at),
                'detalhes' => $e->detalhes,
            ])->values()->all(),
        ];
    }
}
