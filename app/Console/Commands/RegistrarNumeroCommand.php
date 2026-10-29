<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AchaRemetente;
use App\Models\Sender;
use App\Models\SenderEvent;
use App\Services\WhatsApp\MetaCloudClient;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Console\Command;

/**
 * Registro de um número direto na Cloud API da Meta (remetente `provider=meta`), portado do
 * `mcp/src/registrar.ts`: resolve o status DISCONNECTED de um número novo.
 *
 *   disparo:registrar-numero {sender} status                 mostra o estado do número na Meta
 *   disparo:registrar-numero {sender} solicitar [SMS|VOICE]  a Meta manda um código de 6 dígitos
 *   disparo:registrar-numero {sender} verificar 123456       confirma o código recebido
 *   disparo:registrar-numero {sender} registrar 654321       ativa o número com um PIN de 6 dígitos
 *
 * ATENÇÃO: registrar desconecta o aplicativo do WhatsApp do celular; o PIN vira a verificação em duas
 * etapas do número - guarde-o. Operação de infraestrutura: só quem administra o servidor roda.
 */
final class RegistrarNumeroCommand extends Command
{
    use AchaRemetente;

    protected $signature = 'disparo:registrar-numero {sender : id, phone_number_id ou nome do remetente} {etapa=status : status | solicitar | verificar | registrar} {valor? : SMS|VOICE (solicitar), código de 6 dígitos (verificar) ou PIN de 6 dígitos (registrar)}';

    protected $description = 'Registra um número na Cloud API da Meta (remetente provider=meta): status, solicitar código, verificar, registrar';

    public function handle(WhatsAppClientFactory $clientes): int
    {
        $sender = $this->acharRemetente((string) $this->argument('sender'));

        if ($sender === null) {
            $this->error('Remetente não encontrado: '.$this->argument('sender'));

            return self::FAILURE;
        }

        if ($sender->provider !== Sender::PROVIDER_META) {
            $this->error(sprintf('O remetente "%s" é da Kapso: o registro do número é feito no painel da Kapso, não aqui.', $sender->nome));

            return self::FAILURE;
        }

        $cliente = $clientes->para($sender);

        if (! $cliente instanceof MetaCloudClient || ! $cliente->configurado()) {
            $this->error(sprintf('O remetente "%s" está sem token da Meta (api_key ou META_WHATSAPP_TOKEN) ou sem phone_number_id.', $sender->nome));

            return self::FAILURE;
        }

        $etapa = strtolower((string) $this->argument('etapa'));
        $valor = (string) ($this->argument('valor') ?? '');

        try {
            return match ($etapa) {
                'status' => $this->status($sender, $cliente),
                'solicitar' => $this->solicitar($sender, $cliente, $valor),
                'verificar' => $this->verificar($sender, $cliente, $valor),
                'registrar' => $this->registrar($sender, $cliente, $valor),
                default => $this->etapaDesconhecida($etapa),
            };
        } catch (WhatsAppException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function status(Sender $sender, MetaCloudClient $cliente): int
    {
        $numero = $cliente->numero(fresco: true);

        if ($numero === null) {
            $this->error('A Meta não respondeu: confira o token e o phone_number_id do remetente.');

            return self::FAILURE;
        }

        $bruto = (array) ($numero['bruto'] ?? []);

        $this->table(['Campo', 'Valor'], [
            ['remetente', $sender->nome],
            ['phone_number_id', (string) $numero['phone_number_id']],
            ['display_phone_number', (string) ($numero['display_phone_number'] ?? '-')],
            ['verified_name', (string) ($numero['verified_name'] ?? '-')],
            ['quality_rating', (string) $numero['quality_rating']],
            ['messaging_limit_tier', (string) ($numero['messaging_limit_tier'] ?? '-')],
            ['status', (string) ($numero['status'] ?? '-')],
            ['code_verification_status', (string) ($bruto['code_verification_status'] ?? '-')],
            ['name_status', (string) ($bruto['name_status'] ?? '-')],
        ]);

        if (strtoupper((string) ($numero['status'] ?? '')) === 'DISCONNECTED') {
            $this->warn('Número DISCONNECTED: rode "solicitar", depois "verificar <código>" e "registrar <PIN>".');
        }

        return self::SUCCESS;
    }

    private function solicitar(Sender $sender, MetaCloudClient $cliente, string $valor): int
    {
        $metodo = strtoupper($valor ?: 'SMS');

        if (! in_array($metodo, ['SMS', 'VOICE'], true)) {
            $this->error('Método inválido: use SMS ou VOICE.');

            return self::FAILURE;
        }

        $cliente->solicitarCodigo($metodo);
        SenderEvent::registrar($sender, 'registro_numero', ['etapa' => 'solicitar', 'metodo' => $metodo]);
        $this->info(sprintf('Código solicitado por %s. Quando chegar, rode: disparo:registrar-numero %d verificar <código>', $metodo, $sender->id));

        return self::SUCCESS;
    }

    private function verificar(Sender $sender, MetaCloudClient $cliente, string $valor): int
    {
        if (preg_match('/^\d{6}$/', $valor) !== 1) {
            $this->error('Informe o código de 6 dígitos que a Meta enviou.');

            return self::FAILURE;
        }

        $cliente->verificarCodigo($valor);
        SenderEvent::registrar($sender, 'registro_numero', ['etapa' => 'verificar']);
        $this->info(sprintf('Código confirmado. Agora registre com um PIN de 6 dígitos: disparo:registrar-numero %d registrar <PIN>', $sender->id));

        return self::SUCCESS;
    }

    private function registrar(Sender $sender, MetaCloudClient $cliente, string $valor): int
    {
        if (preg_match('/^\d{6}$/', $valor) !== 1) {
            $this->error('Informe um PIN de 6 dígitos (guarde-o: é a verificação em duas etapas do número).');

            return self::FAILURE;
        }

        $cliente->registrarNumero($valor);
        SenderEvent::registrar($sender, 'registro_numero', ['etapa' => 'registrar']);
        $this->info('Número registrado na Cloud API. Guarde o PIN: ele é a verificação em duas etapas do número.');
        $this->line('Confira com: disparo:registrar-numero '.$sender->id.' status');

        return self::SUCCESS;
    }

    private function etapaDesconhecida(string $etapa): int
    {
        $this->error(sprintf('Etapa desconhecida "%s". Use: status | solicitar [SMS|VOICE] | verificar <código> | registrar <PIN>.', $etapa));

        return self::FAILURE;
    }
}
