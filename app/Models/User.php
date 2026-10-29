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
        'ativo',
        'telefone_teste',
    ];

    /** Usuário novo nasce ativo (e a instância recém-criada já sabe disso sem reler o banco). */
    protected $attributes = ['ativo' => true];

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
            'ativo' => 'boolean',
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

    /**
     * Painel só para quem tem papel E está ativo: usuário é desativado, nunca apagado (a trilha guarda o
     * `user_id` de quem aprovou), e desativado não entra mais.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->ativo && $this->roles()->exists();
    }
}
