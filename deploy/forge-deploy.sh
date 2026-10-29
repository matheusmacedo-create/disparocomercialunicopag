# Script de deploy do site no Laravel Forge (Site > Deployments > Deploy script).
# Pressupõe "zero downtime deployments" ligado, como o KYC e o MED (/home/forge/<site>/current).
# As macros $CREATE_RELEASE / $ACTIVATE_RELEASE / $RESTART_QUEUES e as variáveis $FORGE_* são do Forge -
# confira com o script padrão que o Forge gera para o site e mantenha a ordem abaixo.

$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# CSS/JS/fontes do Filament ficam fora do git (public/css|js|fonts/filament): publica a cada release.
# O composer já faz isso no post-autoload-dump (filament:upgrade); a linha abaixo é a rede de segurança.
$FORGE_PHP artisan filament:assets

npm ci
npm run build

# Migra antes de ativar a release: a versão nova só entra no ar com o banco pronto.
# A migração de papéis (2026_10_28_000002) também roda aqui.
$FORGE_PHP artisan migrate --force

$FORGE_PHP artisan optimize
$FORGE_PHP artisan filament:optimize

$ACTIVATE_RELEASE()

# O worker (daemon queue:work) segura o código antigo em memória até reiniciar: importações e jobs
# de prévia rodariam com a versão velha. Reinicia pelo sinal do Laravel, já com a release nova ativa.
# Nunca escreva o nome de uma macro do Forge (cifrão + nome + parênteses) em comentário: o Forge a
# expande ali também e o deploy quebra com "syntax error".
$FORGE_PHP artisan queue:restart

$RESTART_QUEUES()
