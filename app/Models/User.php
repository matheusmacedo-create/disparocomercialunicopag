<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        // Primeiro usuário vira Super Admin: sem isso, o `make:filament-user` de um ambiente novo criaria
        // alguém sem papel nenhum e ninguém entraria no painel (regra do MED).
        static::created(function (self $user): void {
            $role = Role::query()->where('name', Permissions::ROLE_SUPER_ADMIN)->first();

            if ($role && ! $role->users()->exists()) {
                $user->assignRole($role);
            }
        });
    }

    /** Painel só para quem tem papel: usuário sem papel não entra. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }
}
