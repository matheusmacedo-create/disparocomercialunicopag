<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mask;
use App\Support\Telefone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quem nunca mais recebe (KB 4.8). A única pergunta que o envio faz é {@see self::bloqueia()}.
 *
 * @property int $id
 * @property string $telefone_hash
 * @property ?string $telefone
 * @property ?int $sender_id
 * @property string $chave
 * @property string $escopo
 * @property string $motivo
 * @property ?string $origem
 * @property ?string $wamid
 * @property ?string $detalhes
 * @property ?int $user_id
 * @property ?CarbonInterface $criado_em
 */
class Suppression extends Model
{
    use HasFactory;

    public const ESCOPO_TUDO = 'tudo';

    public const ESCOPO_MARKETING = 'marketing';

    public const MOTIVO_PEDIU_PARA_PARAR = 'pediu_para_parar';

    public const MOTIVO_DENUNCIOU = 'denunciou';

    public const MOTIVO_META_MARKETING_STOPPED = 'meta_marketing_stopped';

    public const MOTIVO_MANUAL = 'manual';

    public const MOTIVO_IMPORTACAO = 'importacao';

    public const MOTIVO_HISTORICO = 'historico';

    public const MOTIVO_ANONIMIZADO = 'anonimizado';

    protected $fillable = ['telefone_hash', 'telefone', 'sender_id', 'chave', 'escopo', 'motivo', 'origem', 'wamid', 'detalhes', 'user_id', 'created_at', 'liberada_em', 'liberada_por', 'liberacao_motivo'];

    protected function casts(): array
    {
        return ['liberada_em' => 'datetime'];
    }

    /** Motivos que o painel nunca desfaz: denúncia e a preferência que só o cliente muda na Meta. */
    public const MOTIVOS_IRREVERSIVEIS = [self::MOTIVO_DENUNCIOU, self::MOTIVO_META_MARKETING_STOPPED, self::MOTIVO_ANONIMIZADO];

    public function scopeVigentes(Builder $query): Builder
    {
        return $query->whereNull('liberada_em');
    }

    public function vigente(): bool
    {
        return $this->liberada_em === null;
    }

    public function liberavel(): bool
    {
        return $this->vigente() && ! in_array($this->motivo, self::MOTIVOS_IRREVERSIVEIS, true);
    }

    /**
     * Falso positivo desfeito por quem tem `release suppressions`, com justificativa e trilha por contato;
     * nunca para denúncia nem para a preferência da Meta.
     */
    public function liberar(int $userId, string $motivo): bool
    {
        if (! $this->liberavel()) {
            return false;
        }

        // A chave única libera o lugar para uma supressão futura do mesmo telefone.
        $this->forceFill(['liberada_em' => now(), 'liberada_por' => $userId, 'liberacao_motivo' => mb_substr($motivo, 0, 500), 'chave' => $this->chave.'#'.now()->timestamp])->save();

        ContactEvent::registrar($this->telefone_hash, 'bloqueio_liberado', ['supressao_id' => $this->id, 'motivo_original' => $this->motivo, 'justificativa' => mb_substr($motivo, 0, 500)], $userId);

        if ($this->escopo === self::ESCOPO_TUDO && $this->telefone !== null && ! self::query()->vigentes()->where('telefone_hash', $this->telefone_hash)->where('escopo', self::ESCOPO_TUDO)->exists()) {
            Contact::query()->where('telefone', $this->telefone)->where('status', Contact::STATUS_OPTOUT)->get()
                ->each(fn (Contact $contato) => $contato->forceFill(['status' => Contact::STATUS_ATIVO, 'optout_em' => null, 'optout_motivo' => null, 'optout_origem' => null])->save());
        }

        return true;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    /** Quem bloqueou à mão (painel). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function liberadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberada_por');
    }

    /** @return array<string, string> */
    public static function escopoLabels(): array
    {
        return [self::ESCOPO_TUDO => 'Tudo (qualquer template, todos os remetentes)', self::ESCOPO_MARKETING => 'Só marketing'];
    }

    /**
     * `hash:todos` = escopo tudo; `hash:{sender}` = marketing daquele remetente; `hash:marketing` = marketing
     * global (recusas repetidas). Escopos diferentes nunca dividem a chave: um "pare" nunca é engolido por
     * uma recusa de marketing já gravada.
     */
    public static function chaveDe(string $telefoneE164, ?int $senderId, string $escopo = self::ESCOPO_TUDO): string
    {
        if ($escopo === self::ESCOPO_TUDO) {
            return Telefone::hash($telefoneE164).':todos';
        }

        return Telefone::hash($telefoneE164).':'.($senderId ?? 'marketing');
    }

    /**
     * Grava a supressão (o primeiro momento fica; a repetição não muda nada) e marca o contato, se
     * existir. Invariante: escopo `tudo` vale para todos os remetentes (sender_id NULL), sempre; só a
     * preferência de marketing da Meta é por remetente. True = gravou agora.
     */
    public static function suprimir(
        string $telefoneE164,
        string $motivo,
        ?string $origem = null,
        ?int $senderId = null,
        string $escopo = self::ESCOPO_TUDO,
        ?string $wamid = null,
        ?string $detalhes = null,
        ?int $userId = null,
        ?CarbonInterface $quando = null,
    ): bool {
        if ($escopo === self::ESCOPO_TUDO) {
            $senderId = null;
        }

        // createOrFirst: webhook e painel no mesmo segundo não estouram a chave única.
        $registro = self::query()->createOrFirst(
            ['chave' => self::chaveDe($telefoneE164, $senderId, $escopo)],
            [
                'telefone_hash' => Telefone::hash($telefoneE164),
                'telefone' => $telefoneE164,
                'sender_id' => $senderId,
                'escopo' => $escopo,
                'motivo' => mb_substr($motivo, 0, 50),
                'origem' => $origem !== null ? mb_substr($origem, 0, 30) : null,
                'wamid' => $wamid,
                'detalhes' => $detalhes !== null ? mb_substr(Mask::scrub($detalhes), 0, 500) : null,
                'user_id' => $userId,
                'created_at' => $quando ?? now(),
            ],
        );

        if ($registro->wasRecentlyCreated) {
            ContactEvent::registrar($registro->telefone_hash, 'bloqueado', ['supressao_id' => $registro->id, 'motivo' => $registro->motivo, 'escopo' => $escopo, 'sender_id' => $senderId], $userId);
        }

        if ($escopo === self::ESCOPO_TUDO) {
            Contact::query()->where('telefone', $telefoneE164)->get()
                ->each(fn (Contact $contato) => $contato->marcarOptout($motivo, $origem ?? 'resposta', $quando));
        }

        return $registro->wasRecentlyCreated;
    }

    /**
     * Por que o telefone está bloqueado para este remetente e categoria (null = livre): `tudo` em qualquer
     * remetente (ou no global) bloqueia sempre; `marketing` só bloqueia template MARKETING no mesmo remetente.
     */
    public static function bloqueia(string $telefoneE164, ?Sender $sender, ?string $categoria): ?string
    {
        $hash = Telefone::hash($telefoneE164);

        $registros = self::query()
            ->vigentes()
            ->where('telefone_hash', $hash)
            ->where(fn (Builder $q) => $q->whereNull('sender_id')->when($sender !== null, fn (Builder $q) => $q->orWhere('sender_id', $sender->id)))
            ->get();

        foreach ($registros as $registro) {
            if ($registro->escopo === self::ESCOPO_TUDO) {
                return $registro->motivo;
            }
        }

        if (strtoupper((string) $categoria) === Sender::CATEGORIA_MARKETING) {
            foreach ($registros as $registro) {
                if ($registro->escopo === self::ESCOPO_MARKETING) {
                    return $registro->motivo;
                }
            }
        }

        return null;
    }

    /** Todos os hashes bloqueados de escopo `tudo` (para a montagem do público em lote). @return array<string, string> hash => motivo */
    public static function hashesBloqueados(?Sender $sender, ?string $categoria): array
    {
        $query = self::query()
            ->vigentes()
            ->where(fn (Builder $q) => $q->whereNull('sender_id')->when($sender !== null, fn (Builder $q) => $q->orWhere('sender_id', $sender->id)));

        if (strtoupper((string) $categoria) !== Sender::CATEGORIA_MARKETING) {
            $query->where('escopo', self::ESCOPO_TUDO);
        }

        $hashes = [];

        foreach ($query->get(['telefone_hash', 'escopo', 'motivo']) as $registro) {
            $hashes[$registro->telefone_hash] = $hashes[$registro->telefone_hash] ?? $registro->motivo;
        }

        return $hashes;
    }

    /** @return array<string, string> */
    public static function motivoLabels(): array
    {
        return [
            self::MOTIVO_PEDIU_PARA_PARAR => 'Pediu para parar',
            self::MOTIVO_DENUNCIOU => 'Denunciou',
            self::MOTIVO_META_MARKETING_STOPPED => 'Parou marketing na Meta',
            self::MOTIVO_MANUAL => 'Bloqueio manual',
            self::MOTIVO_IMPORTACAO => 'Lista de opt-out importada',
            self::MOTIVO_HISTORICO => 'Pediu para parar (histórico)',
            self::MOTIVO_ANONIMIZADO => 'Anonimizado (direito de exclusão)',
            self::MOTIVO_RECUSAS => 'Recusou ofertas duas vezes',
        ];
    }

    /** Duas recusas ("não", "agora não") a template MARKETING. */
    public const MOTIVO_RECUSAS = 'recusas_repetidas';

    /** A preferência da Meta voltou (`resume`): só a supressão de marketing deste remetente cai; a textual nunca. */
    public static function retomarMarketing(string $telefoneE164, ?int $senderId): void
    {
        self::query()->vigentes()
            ->where('telefone_hash', Telefone::hash($telefoneE164))
            ->where('escopo', self::ESCOPO_MARKETING)
            ->where('motivo', self::MOTIVO_META_MARKETING_STOPPED)
            ->when($senderId !== null, fn (Builder $q) => $q->where('sender_id', $senderId))
            ->get()
            ->each(fn (self $s) => $s->forceFill(['liberada_em' => now(), 'liberacao_motivo' => 'a Meta avisou que o cliente retomou as mensagens de marketing', 'chave' => $s->chave.'#'.now()->timestamp])->save());
    }
}
