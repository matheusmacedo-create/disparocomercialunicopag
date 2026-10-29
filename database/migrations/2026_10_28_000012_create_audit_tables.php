<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilhas imutáveis fora da campanha (achados de LGPD/segurança de 28/10/2026): régua, remetente e
 * contato (por hash, para sobreviver à anonimização). O ator fica gravado como texto: apagar um usuário
 * não apaga quem aprovou. Usuário é desativado, nunca apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('ativo')->default(true)->after('password');
            // Telefone verificado do próprio usuário, único destino do "Enviar teste".
            $table->string('telefone_teste', 20)->nullable()->after('ativo');
        });

        Schema::table('campaign_events', function (Blueprint $table): void {
            // Nome e e-mail de quem fez, no momento; `sistema` nas rotinas agendadas.
            $table->string('ator', 160)->nullable()->after('user_id');
        });

        Schema::create('journey_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('journeys')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ator', 160)->nullable();
            // criada | editada | enviada_para_aprovacao | aprovada | ativada | pausada | inscricao_manual | ...
            $table->string('evento', 40);
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['journey_id', 'created_at']);
        });

        Schema::create('sender_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('senders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ator', 160)->nullable();
            // politica_alterada | pausado | religado | webhook_registrado | segredo_rotacionado | qualidade | ...
            $table->string('evento', 40);
            // de => para, por campo, sem segredos.
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sender_id', 'created_at']);
        });

        Schema::create('contact_events', function (Blueprint $table): void {
            $table->id();
            // Chaveado pelo hash: sobrevive à anonimização e ao apagamento do contato.
            $table->char('telefone_hash', 64)->index();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ator', 160)->nullable();
            // anonimizado | pedido_lgpd | bloqueado | bloqueio_liberado | conversa_lida | consentimento | texto_enviado
            $table->string('evento', 40);
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['telefone_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_events');
        Schema::dropIfExists('sender_events');
        Schema::dropIfExists('journey_events');
        Schema::table('campaign_events', fn (Blueprint $table) => $table->dropColumn('ator'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['ativo', 'telefone_teste']));
    }
};
