<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Catálogo de permissões e papéis do painel (spatie/laravel-permission), no mesmo desenho do KYC e do
 * MED: os nomes moram aqui para o código não espalhar string solta - o seeder, os resources e as ações
 * leem daqui. KB 11.
 */
final class Permissions
{
    public const VIEW_CONTACTS = 'view contacts';

    // Criar/editar contato, base, tag, segmento; bloquear/desbloquear; anonimizar.
    public const MANAGE_CONTACTS = 'manage contacts';

    // Importar CSV, histórico da Kapso, sellers inativos.
    public const IMPORT_CONTACTS = 'import contacts';

    // Ler o que o cliente escreveu e responder (operacional). Leitura fica na trilha do contato.
    public const VIEW_CONVERSATIONS = 'view conversations';

    // CPF/CNPJ sem máscara, e-mail, telefone completo, atributos do CSV, variáveis renderizadas, exportações.
    public const VIEW_PII = 'view pii';

    // Direito de exclusão: anonimizar contato (irreversível).
    public const ANONYMIZE_CONTACTS = 'anonymize contacts';

    // Desfazer um bloqueio (falso positivo do classificador), com justificativa.
    public const RELEASE_SUPPRESSIONS = 'release suppressions';

    public const VIEW_CAMPAIGNS = 'view campaigns';

    // Criar, simular, enviar para aprovação, pausar, cancelar campanha; enviar teste.
    public const MANAGE_CAMPAIGNS = 'manage campaigns';

    // Aprovar, iniciar e retomar campanha e régua (quatro olhos, KB 2.2).
    public const APPROVE_CAMPAIGNS = 'approve campaigns';

    public const MANAGE_JOURNEYS = 'manage journeys';

    // Remetentes (chaves, teto, janela, pausa, religar), templates, webhooks, histórico da Kapso e sellers do unico-api.
    public const MANAGE_SENDERS = 'manage senders';

    public const MANAGE_USERS = 'manage users';

    public const ROLE_SUPER_ADMIN = 'Super Admin';

    public const ROLE_ADMIN = 'Admin';

    public const ROLE_OPERATOR = 'Operador';

    public const ROLE_VIEWER = 'Visualizador';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::VIEW_CONTACTS,
            self::MANAGE_CONTACTS,
            self::IMPORT_CONTACTS,
            self::VIEW_CONVERSATIONS,
            self::VIEW_PII,
            self::ANONYMIZE_CONTACTS,
            self::RELEASE_SUPPRESSIONS,
            self::VIEW_CAMPAIGNS,
            self::MANAGE_CAMPAIGNS,
            self::APPROVE_CAMPAIGNS,
            self::MANAGE_JOURNEYS,
            self::MANAGE_SENDERS,
            self::MANAGE_USERS,
        ];
    }

    /**
     * O Super Admin passa por cima de tudo pelo Gate::before (AppServiceProvider), mas recebe todas também -
     * assim a tela de usuários mostra o que ele pode de verdade.
     *
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => self::all(),
            self::ROLE_ADMIN => [
                self::VIEW_CONTACTS,
                self::MANAGE_CONTACTS,
                self::IMPORT_CONTACTS,
                self::VIEW_CONVERSATIONS,
                self::VIEW_PII,
                self::ANONYMIZE_CONTACTS,
                self::RELEASE_SUPPRESSIONS,
                self::VIEW_CAMPAIGNS,
                self::MANAGE_CAMPAIGNS,
                self::APPROVE_CAMPAIGNS,
                self::MANAGE_JOURNEYS,
                self::MANAGE_SENDERS,
            ],
            self::ROLE_OPERATOR => [
                self::VIEW_CONTACTS,
                self::MANAGE_CONTACTS,
                self::IMPORT_CONTACTS,
                self::VIEW_CONVERSATIONS,
                self::VIEW_CAMPAIGNS,
                self::MANAGE_CAMPAIGNS,
                self::MANAGE_JOURNEYS,
            ],
            self::ROLE_VIEWER => [
                self::VIEW_CONTACTS,
                self::VIEW_CAMPAIGNS,
            ],
        ];
    }

    /** @return array<string, string> papel => o que ele pode, para a tela de usuários */
    public static function roleDescriptions(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => 'Tudo, inclusive usuários.',
            self::ROLE_ADMIN => 'Contatos, campanhas e réguas (aprova), remetentes, dados sensíveis, anonimização e desbloqueio; sem usuários.',
            self::ROLE_OPERATOR => 'Contatos, importação de CSV, campanhas e réguas, conversas - sem aprovar, sem dados sensíveis, sem remetentes.',
            self::ROLE_VIEWER => 'Só leitura de contatos e campanhas.',
        ];
    }

    public static function allows(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
