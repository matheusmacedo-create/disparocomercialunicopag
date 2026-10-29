<?php

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Papéis e permissões nascem com a migração, não com um seeder que alguém precisa lembrar de rodar no
 * deploy. Quem já usava o painel antes dos papéis existirem vira Super Admin (regra do MED).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolePermissionSeeder)->run();

        User::query()->each(fn (User $user) => $user->assignRole(Permissions::ROLE_SUPER_ADMIN));
    }

    public function down(): void
    {
        //
    }
};
