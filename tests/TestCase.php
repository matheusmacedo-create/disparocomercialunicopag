<?php

namespace Tests;

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aponta o storage para o diretório exclusivo deste processo (ver tests/bootstrap.php).
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($storage = getenv('TEST_STORAGE_PATH')) {
            $app->useStoragePath($storage);
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste fala com a Kapso, a Meta ou o Discord de verdade: chamada sem Http::fake
        // correspondente estoura em vez de sair para a rede.
        Http::preventStrayRequests();

        // Os testes não dependem do build do front (npm run build).
        $this->withoutVite();
    }

    /** Usuário com o papel pedido (as permissões vêm do seeder, como em produção). */
    protected function usuario(string $papel = Permissions::ROLE_SUPER_ADMIN, array $atributos = []): User
    {
        (new RolePermissionSeeder)->run();

        $user = User::factory()->create($atributos);
        $user->syncRoles([$papel]);

        return $user->fresh();
    }
}
