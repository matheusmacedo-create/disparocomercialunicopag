<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uma importação (KB 4.4).
 *
 * @property int $id
 * @property string $tipo
 * @property ?string $arquivo
 * @property ?string $nome_original
 * @property ?array<string, string> $mapeamento
 * @property ?int $contact_list_id
 * @property ?int $sender_id
 * @property ?string $origem
 * @property ?string $tipo_contato
 * @property ?array<int, string> $tags
 * @property ?string $consentimento_origem
 * @property ?array<string, mixed> $parametros
 * @property string $status
 * @property int $total_linhas
 * @property int $criados
 * @property int $atualizados
 * @property int $ignorados
 * @property int $invalidos
 * @property int $duplicados
 * @property int $optouts
 * @property int $sem_consentimento
 * @property ?array<int, array<string, mixed>> $erros
 * @property ?array<string, mixed> $resultado
 * @property ?string $mensagem
 * @property ?int $user_id
 * @property ?CarbonInterface $iniciado_em
 * @property ?CarbonInterface $concluido_em
 */
class ContactImport extends Model
{
    use HasFactory;

    public const TIPO_CSV = 'csv';

    public const TIPO_KAPSO = 'kapso';

    public const TIPO_UNICOAPI = 'unicoapi';

    public const TIPO_OPTOUT = 'optout';

    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_PROCESSANDO = 'processando';

    public const STATUS_CONCLUIDA = 'concluida';

    public const STATUS_FALHOU = 'falhou';

    public const MAX_ERROS_GUARDADOS = 100;

    /** Onde ficam os arquivos de uma importação no disco `local` (storage/app/private). */
    public const PASTA = 'importacoes';

    protected $fillable = [
        'tipo', 'arquivo', 'nome_original', 'mapeamento', 'contact_list_id', 'sender_id', 'origem', 'tipo_contato',
        'tags', 'consentimento_origem', 'parametros', 'status', 'total_linhas', 'criados', 'atualizados', 'ignorados',
        'invalidos', 'duplicados', 'optouts', 'sem_consentimento', 'erros', 'resultado', 'mensagem', 'user_id',
        'iniciado_em', 'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'mapeamento' => 'array',
            'tags' => 'array',
            'parametros' => 'array',
            'erros' => 'array',
            'resultado' => 'array',
            'total_linhas' => 'integer',
            'criados' => 'integer',
            'atualizados' => 'integer',
            'ignorados' => 'integer',
            'invalidos' => 'integer',
            'duplicados' => 'integer',
            'optouts' => 'integer',
            'sem_consentimento' => 'integer',
            'iniciado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    /** Caminho (no disco `local`) do CSV com TODAS as linhas rejeitadas: linha, telefone mascarado, motivo. */
    public function caminhoDasRejeitadas(): string
    {
        return self::PASTA.'/'.$this->id.'/rejeitadas.csv';
    }

    public function temRejeitadas(): bool
    {
        return $this->id !== null && Storage::disk('local')->exists($this->caminhoDasRejeitadas());
    }

    /** Para o painel: o download do rejeitadas.csv (null quando não houve rejeição). */
    public function baixarRejeitadas(): ?StreamedResponse
    {
        if (! $this->temRejeitadas()) {
            return null;
        }

        return Storage::disk('local')->download($this->caminhoDasRejeitadas(), sprintf('importacao-%d-rejeitadas.csv', $this->id));
    }

    /** Parâmetro da importação (desde/até do histórico, dias dos inativos, consentimento_canal...). */
    public function parametro(string $chave, mixed $padrao = null): mixed
    {
        return ((array) ($this->parametros ?? []))[$chave] ?? $padrao;
    }

    /** O usuário que pediu a importação (para a notificação do painel); null nas rotinas. */
    public function solicitante(): ?User
    {
        return $this->user_id !== null ? $this->user()->first() : null;
    }

    /** Resumo das contagens numa linha, sem PII, para a notificação e a saída dos comandos. */
    public function resumo(): string
    {
        $partes = array_filter([
            $this->total_linhas > 0 ? $this->total_linhas.' linhas' : null,
            $this->criados > 0 ? $this->criados.' criados' : null,
            $this->atualizados > 0 ? $this->atualizados.' atualizados' : null,
            $this->optouts > 0 ? $this->optouts.' bloqueados' : null,
            $this->invalidos > 0 ? $this->invalidos.' inválidos' : null,
            $this->duplicados > 0 ? $this->duplicados.' duplicados' : null,
            $this->ignorados > 0 ? $this->ignorados.' ignorados' : null,
            $this->sem_consentimento > 0 ? $this->sem_consentimento.' sem consentimento' : null,
        ]);

        return $partes === [] ? 'nada a importar' : implode(', ', $partes);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'contact_list_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Guarda só os primeiros erros (linha e motivo; telefone mascarado por quem chama). */
    public function registrarErro(int|string $linha, string $motivo): void
    {
        $erros = (array) ($this->erros ?? []);

        if (count($erros) < self::MAX_ERROS_GUARDADOS) {
            $erros[] = ['linha' => $linha, 'motivo' => mb_substr($motivo, 0, 200)];
            $this->erros = $erros;
        }
    }

    public function emAndamento(): bool
    {
        return in_array($this->status, [self::STATUS_PENDENTE, self::STATUS_PROCESSANDO], true);
    }

    /** @return array<string, string> */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDENTE => 'Na fila',
            self::STATUS_PROCESSANDO => 'Processando',
            self::STATUS_CONCLUIDA => 'Concluída',
            self::STATUS_FALHOU => 'Falhou',
        ];
    }
}
