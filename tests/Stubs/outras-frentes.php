<?php

/*
 * Stubs mínimos das classes de OUTRAS frentes (importação, contatos) que a frente do painel chama, para a
 * suíte desta frente rodar antes do merge. Cada classe só é declarada se a real ainda não existir no
 * autoload - depois do merge, as reais valem e isto vira letra morta. Nunca copiar para app/.
 */

namespace App\Jobs {
    use App\Models\ContactImport;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\SerializesModels;

    if (! class_exists(ProcessarImportacaoJob::class)) {
        class ProcessarImportacaoJob implements ShouldQueue
        {
            use Dispatchable;
            use Queueable;
            use SerializesModels;

            public function __construct(public ContactImport $import) {}

            public function handle(): void {}
        }
    }
}

namespace Tests\Stubs {
    use App\Models\Contact;
    use App\Models\User;

    /** Espião de AnonimizarContato: registra a chamada e marca o contato, sem a regra de verdade. */
    final class AnonimizadorEspiao
    {
        /** @var list<array{contact_id: int, user_id: int, motivo: string}> */
        public array $chamadas = [];

        public function executar(Contact $contato, User $user, string $motivo): void
        {
            $this->chamadas[] = ['contact_id' => $contato->id, 'user_id' => $user->id, 'motivo' => $motivo];
            $contato->forceFill(['status' => Contact::STATUS_ANONIMIZADO, 'anonimizado_em' => now()])->save();
        }
    }

    final class ExportadorFalso
    {
        public function executar(Contact $contato): array
        {
            return ['telefone' => $contato->telefone, 'nome' => $contato->nome];
        }
    }

    final class ImportadorCsvFalso
    {
        public array $chamadasDePrevia = [];

        public function sugerirMapeamento(array $cabecalho): array
        {
            $mapa = [];

            foreach ($cabecalho as $coluna) {
                $mapa[$coluna] = match (strtolower($coluna)) {
                    'phone', 'telefone', 'whatsapp', 'celular' => 'telefone',
                    'name', 'nome' => 'nome',
                    'email', 'e-mail' => 'email',
                    default => 'atributos.'.strtolower($coluna),
                };
            }

            return $mapa;
        }

        public function previa(string $caminho, array $mapeamento, array $opcoes): array
        {
            $this->chamadasDePrevia[] = compact('caminho', 'mapeamento', 'opcoes');

            return ['linhas' => [['telefone' => '+5521998765432', 'nome' => 'Ana']], 'previsao' => ['criados' => 1]];
        }
    }
}
