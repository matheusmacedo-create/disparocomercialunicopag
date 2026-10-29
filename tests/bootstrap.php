<?php

/*
|--------------------------------------------------------------------------
| Isolamento por processo (mesmo esquema do KYC e do MED)
|--------------------------------------------------------------------------
|
| Duas execuções da suíte no mesmo clone compartilhariam o mesmo arquivo SQLite e o mesmo disco fake do
| Storage: uma truncaria as tabelas da outra no meio da transação. Aqui cada processo ganha seu próprio
| banco e seu próprio storage, com sufixo derivado de `TEST_TOKEN` (paratest) ou do PID. Os `env` do
| `phpunit.xml` não sobrescrevem variáveis já presentes no ambiente, por isso definir aqui - antes do
| PHPUnit ler a configuração - é o que vale.
|
*/

require __DIR__.'/../vendor/autoload.php';

$token = getenv('TEST_TOKEN') ?: getmypid();

$root = dirname(__DIR__);
$database = $root.'/storage/framework/testing/run-'.$token.'/database.sqlite';
$storage = $root.'/storage/framework/testing/run-'.$token.'/storage';

foreach ([
    $storage.'/app/public',
    $storage.'/app/private',
    $storage.'/framework/cache/data',
    $storage.'/framework/sessions',
    $storage.'/framework/testing',
    $storage.'/framework/views',
    $storage.'/logs',
] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}

touch($database);

foreach (['DB_DATABASE' => $database, 'TEST_STORAGE_PATH' => $storage] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

register_shutdown_function(static function () use ($root, $token): void {
    $directory = $root.'/storage/framework/testing/run-'.$token;

    if (! is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($directory);
});
