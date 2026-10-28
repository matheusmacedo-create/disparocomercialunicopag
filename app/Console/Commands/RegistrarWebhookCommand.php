<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AchaRemetente;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Services\Webhooks\NormalizadorKapso;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Console\Command;

/**
 * Cadastra (ou atualiza) na Kapso o webhook deste sistema para o número do remetente (KB 10): gera um
 * segredo novo, registra a URL com a chave aleatória do remetente e os eventos de mensagem mais a
 * preferência de marketing. O segredo fica criptografado no remetente e nunca sai na tela nem no log.
 *
 * Convive com os webhooks do KYC e do MED no mesmo número (a Kapso aceita vários por número): cada um tem
 * URL e segredo próprios. Se a Kapso recusar, o segredo antigo volta - senão o webhook em produção
 * passaria a responder 401 para sempre.
 */
final class RegistrarWebhookCommand extends Command
{
    use AchaRemetente;

    protected $signature = 'disparo:registrar-webhook {sender : id, phone_number_id ou nome do remetente}';

    protected $description = 'Gera o segredo e cadastra na Kapso o webhook do remetente (mensagens e preferência de marketing)';

    public function handle(WhatsAppClientFactory $clientes): int
    {
        $sender = $this->acharRemetente((string) $this->argument('sender'));

        if ($sender === null) {
            $this->error('Remetente não encontrado: '.$this->argument('sender'));

            return self::FAILURE;
        }

        if ($sender->provider !== Sender::PROVIDER_KAPSO) {
            $this->error(sprintf('O remetente "%s" não é da Kapso: o webhook do provedor Meta é o do App (META_WEBHOOK_VERIFY_TOKEN / META_APP_SECRET), configurado no Meta for Developers.', $sender->nome));

            return self::FAILURE;
        }

        $kapso = $clientes->kapso($sender);

        if (! $kapso->configurado()) {
            $this->error(sprintf('O remetente "%s" está sem chave da Kapso (api_key ou KAPSO_API_KEY) ou sem phone_number_id.', $sender->nome));

            return self::FAILURE;
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->warn('APP_URL não é https: a Kapso só entrega webhook em URL pública com https. Confira o .env antes de usar em produção.');
        }

        $antigo = $sender->webhook_secret;
        $segredo = $sender->rotacionarSegredoDoWebhook();
        $url = route('webhooks.kapso', ['webhookKey' => $sender->webhook_key]);
        $eventos = array_values(array_unique(array_merge(
            (array) config('disparo.provedores.kapso.webhook_events', []),
            [NormalizadorKapso::EVENTO_PREFERENCIA],
        )));

        try {
            $resposta = $kapso->registrarWebhook($url, $segredo, $eventos);
        } catch (WhatsAppException $e) {
            $sender->forceFill(['webhook_secret' => $antigo])->save();
            $this->error('A Kapso recusou o cadastro do webhook; o segredo anterior foi mantido. '.$e->getMessage());

            return self::FAILURE;
        }

        // A resposta pode ecoar o segredo: ele não vai para a trilha nem para a saída além da linha abaixo.
        $resposta = self::semSegredos($resposta);

        SenderEvent::registrar($sender, 'webhook_registrado', [
            'url' => $url,
            'eventos' => $eventos,
            'webhook_id' => $resposta['id'] ?? null,
            'active' => $resposta['active'] ?? null,
            'segredo_rotacionado' => true,
        ]);

        $this->info('Webhook cadastrado na Kapso para o remetente "'.$sender->nome.'".');
        $this->line('URL: '.$url);
        $this->line('Eventos: '.implode(', ', $eventos));
        $this->newLine();
        $this->info('Segredo novo gerado, enviado à Kapso e gravado criptografado no remetente (não é mostrado: rode de novo para rotacionar).');

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $dados
     * @return array<array-key, mixed>
     */
    private static function semSegredos(array $dados): array
    {
        $limpo = [];

        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && preg_match('/secret|token|password|senha/i', $chave) === 1) {
                continue;
            }

            $limpo[$chave] = is_array($valor) ? self::semSegredos($valor) : $valor;
        }

        return $limpo;
    }
}
