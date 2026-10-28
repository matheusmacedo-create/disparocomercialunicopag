<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importação, histórico da Kapso e anonimização (frente B):
 *  - `contact_imports.resultado`: contagens que não cabem nas colunas fixas (histórico da Kapso: mensagens,
 *    contatos, supressões por motivo; CSV: sem consentimento, rejeitadas);
 *  - `contact_imports.sem_consentimento`: contatos importados numa base que exige consentimento (ou de
 *    origem externa) sem consentimento informado - entram, mas o envio MARKETING barra;
 *  - `contacts.nome_origem`: de onde veio o nome atual, para a precedência unicoapi > csv > kapso > manual
 *    (a origem do contato não diz quem preencheu o nome);
 *  - `contacts.kapso_conversation_id`: a conversa do número na Kapso ("abrir na Kapso").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_imports', function (Blueprint $table): void {
            $table->unsignedInteger('sem_consentimento')->default(0)->after('optouts');
            $table->json('resultado')->nullable()->after('erros');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('nome_origem', 20)->nullable()->after('primeiro_nome');
            $table->string('kapso_conversation_id', 64)->nullable()->after('kapso_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['nome_origem', 'kapso_conversation_id']));
        Schema::table('contact_imports', fn (Blueprint $table) => $table->dropColumn(['sem_consentimento', 'resultado']));
    }
};
