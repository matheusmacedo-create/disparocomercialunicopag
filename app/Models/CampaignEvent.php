<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha humana da campanha: quem criou, simulou, aprovou, iniciou, pausou (KB 4.6).
 *
 * @property int $id
 * @property int $campaign_id
 * @property ?int $user_id
 * @property string $evento
 * @property ?array<string, mixed> $detalhes
 */
class CampaignEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['campaign_id', 'user_id', 'ator', 'evento', 'detalhes', 'created_at'];

    protected function casts(): array
    {
        return ['detalhes' => 'array', 'created_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'criada' => 'Criada',
            'simulada' => 'Prévia gerada',
            'enviada_para_aprovacao' => 'Enviada para aprovação',
            'aprovada' => 'Aprovada',
            'reprovada' => 'Devolvida para ajuste',
            'iniciada' => 'Iniciada',
            'pausada' => 'Pausada',
            'retomada' => 'Retomada',
            'cancelada' => 'Cancelada',
            'concluida' => 'Concluída',
            'disjuntor' => 'Disjuntor (recusa da conta)',
            'engajamento' => 'Disjuntor de engajamento',
            'categoria_mudou' => 'Template reclassificado pela Meta',
            'aprovacao_invalidada' => 'Aprovação invalidada (algo mudou)',
            'tranche_enviada' => 'Primeira leva enviada',
            'tranche_liberada' => 'Leva seguinte liberada',
            'sonda_passou' => 'Sonda passou',
            'simulada_execucao' => 'Rodou em simulação',
            'teste_enviado' => 'Teste enviado',
            'duplicada' => 'Duplicada',
            'exportada' => 'Destinatários exportados',
        ];
    }
}
