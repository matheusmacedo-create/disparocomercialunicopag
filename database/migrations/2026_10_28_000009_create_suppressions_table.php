<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supressões: quem nunca mais recebe (KB 4.8). Chave pelo hash do telefone para sobreviver à
 * anonimização; `sender_id` nulo = todos os remetentes. `chave` = hash:remetente, porque unique com NULL
 * não protege no MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table): void {
            $table->id();
            $table->char('telefone_hash', 64)->index();
            // E.164; nulo depois da anonimização.
            $table->string('telefone', 20)->nullable();
            $table->foreignId('sender_id')->nullable()->constrained('senders')->cascadeOnDelete();
            // hash + ':' + (sender_id | 'todos')
            $table->string('chave', 120)->unique();
            // tudo | marketing
            $table->string('escopo', 15)->default('tudo');
            // pediu_para_parar | denunciou | meta_marketing_stopped | manual | importacao | historico | anonimizado
            $table->string('motivo', 50);
            // resposta | webhook | painel | historico_kapso | csv
            $table->string('origem', 30)->nullable();
            $table->string('wamid', 120)->nullable();
            $table->string('detalhes', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Falso positivo desfeito por Admin, com justificativa (nunca para `denunciou` nem para a
            // preferência da Meta). Liberada = inativa. O momento do pedido é o created_at.
            $table->timestamp('liberada_em')->nullable();
            $table->foreignId('liberada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('liberacao_motivo', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
