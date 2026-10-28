# Deploy no Laravel Forge

Mesmo servidor e mesmo esquema do KYC (`kyc.unicopag.com.br`) e do MED: servidor `ms-subscription`,
PHP 8.3+, deploy sem downtime, banco no mesmo RDS com schema e usuário próprios. Estes passos são feitos
por uma pessoa no painel do Forge - criar site, cron, worker e variáveis de produção não é tarefa do
agente (KB do KYC 8.4 e 13.5).

## 1. Banco

No RDS (o mesmo do KYC): schema `unicopag-disparo` e um usuário próprio só com permissão nele.

## 2. Site

Forge → servidor `ms-subscription` → **New Site**:

- Domínio: `disparo.unicopag.com.br` (sugestão - precisa de DNS apontando para o servidor).
- Projeto: Laravel, PHP **8.3** ou superior, web directory `/public`.
- Repositório: `matheusmacedo-create/disparocomercialunicopag`, branch **`main`**.
- **Zero downtime deployments**: ligado.
- **SSL**: Let's Encrypt. Obrigatório - a Kapso e a Meta chamam o webhook por https.
- Deploy script: o conteúdo de `deploy/forge-deploy.sh`.

## 3. Variáveis de ambiente (Site → Environment)

Partindo do `.env.example`, o que muda em produção:

```dotenv
APP_NAME="ÚnicoPag Disparos"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://disparo.unicopag.com.br
APP_TIMEZONE=America/Sao_Paulo
# APP_KEY: o Forge gera. Não troque depois - chaves e segredos dos remetentes são criptografados com ela.

DB_CONNECTION=mysql
DB_HOST=<host do RDS>
DB_PORT=3306
DB_DATABASE=unicopag-disparo
DB_USERNAME=<usuário do Disparo>
DB_PASSWORD=<senha>

# Sessão e cache no Redis do servidor (o KYC aprendeu que no banco remoto pesam em toda requisição).
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database

# Comece em SIMULAÇÃO. Só ligue depois de importar o histórico da Kapso de cada número e de rodar
# a primeira campanha simulada.
DISPARO_ENVIO_HABILITADO=false
# openssl rand -hex 32 - gerado uma vez, nunca trocado (os hashes de telefone deixariam de casar).
DISPARO_HASH_PEPPER=<segredo>

KAPSO_API_KEY=<chave do projeto Kapso>
API_KEY=<segredo da /api/v1, para o KYC e o MCP>
DISCORD_DISPARO_WEBHOOK_URL=<canal da operação>
DISCORD_DISPARO_ROLE_ID=<cargo a mencionar>
UNICOPAG_API_KEY=<chave própria do Disparo no unico-api>
```

Atenção: mudar o `.env` por comando não basta para a web. O PHP-FPM só lê os valores novos depois de um
redeploy (lição do KYC: o webhook respondia erro até o deploy recarregar o FPM). O artisan pega na hora.

## 4. Cron e worker

- **Scheduler**: Site → Scheduler → `php /home/forge/disparo.unicopag.com.br/current/artisan schedule:run`
  a cada minuto (`* * * * *`), usuário `forge`. Sem ele as campanhas, réguas e sincronizações "existem e
  nunca rodam" - `php artisan disparo:diagnostico` acusa pelo batimento.
- **Worker**: Server → Daemons (ou Site → Queue): `php artisan queue:work --tries=3 --timeout=600
  --sleep=3` no diretório `current`, usuário `forge`. Importações, prévias grandes e notificações passam
  por ele; o diagnóstico lê o batimento do worker (a cada 5 min).

## 5. Primeiro acesso

```bash
cd /home/forge/disparo.unicopag.com.br/current
php artisan make:filament-user        # o primeiro usuário vira Super Admin
```

Depois, no painel (`/admin`): Configuração → Remetentes → cadastrar o número (provider Kapso,
`phone_number_id`, categorias permitidas, teto, janela) → **Registrar webhook** (gera o segredo e cadastra
na Kapso) → **Sincronizar número** e **Sincronizar templates** → Importações → **Importar histórico da
Kapso** do número. Só então importar bases e criar a primeira campanha, em simulação.

## 6. Conferir

```bash
php artisan disparo:diagnostico
```

Mostra: interruptor de envio, pepper, batimentos do agendador e do worker, remetentes e seus webhooks na
Kapso, campanhas em andamento, webhooks sem processar e falhas recentes.
