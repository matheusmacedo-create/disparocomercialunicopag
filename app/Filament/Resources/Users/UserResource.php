<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Usuários do painel, como no MED: papel pelo catálogo de App\Support\Permissions, primeiro usuário vira
 * Super Admin. Diferenças desta plataforma: usuário é desativado, nunca apagado (a trilha guarda quem
 * aprovou o quê); ninguém edita o próprio papel nem se desativa; `telefone_teste` é o único destino do
 * "Enviar teste" das campanhas.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Users;

    protected static ?string $slug = 'usuarios';

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 90;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /** Desativar em vez de apagar; ninguém se desativa (e se tranca para fora). */
    public static function desativarAction(): Action
    {
        return Action::make('desativar')
            ->label('Desativar')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Desativar o acesso ao painel')
            ->modalDescription('O usuário não entra mais; a trilha do que ele fez fica. Dá para reativar depois.')
            ->visible(fn (User $record): bool => Permissions::allows(Permissions::MANAGE_USERS) && $record->ativo && $record->isNot(auth()->user()))
            ->action(function (User $record): void {
                $record->forceFill(['ativo' => false])->save();
                Notification::make()->success()->title('Usuário desativado')->send();
            });
    }

    public static function reativarAction(): Action
    {
        return Action::make('reativar')
            ->label('Reativar')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => Permissions::allows(Permissions::MANAGE_USERS) && ! $record->ativo)
            ->action(function (User $record): void {
                $record->forceFill(['ativo' => true])->save();
                Notification::make()->success()->title('Usuário reativado')->send();
            });
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::MANAGE_USERS);
    }

    public static function canCreate(): bool
    {
        return Permissions::allows(Permissions::MANAGE_USERS);
    }

    public static function canEdit(Model $record): bool
    {
        return Permissions::allows(Permissions::MANAGE_USERS);
    }

    public static function canDelete(Model $record): bool
    {
        // Nunca: `user_id` nas trilhas é nullOnDelete; apagar apagaria quem aprovou. Desativa-se.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
