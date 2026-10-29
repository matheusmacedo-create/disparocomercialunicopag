<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remetentes: um por número de WhatsApp, com provedor, credencial e a política do número (KB 4.1).
 *
 * Todo TIMESTAMP é nullable de propósito: no MariaDB 10.6 de produção o primeiro TIMESTAMP NOT NULL sem
 * default ganharia `ON UPDATE CURRENT_TIMESTAMP` (lição do KYC, account_activations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('senders', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            // kapso | meta
            $table->string('provider', 10)->default('kapso');
            // comercial | avisos | kyc | teste
            $table->string('finalidade', 20)->default('comercial');
            $table->string('phone_number_id', 40)->unique();
            $table->string('waba_id', 40)->nullable();
            $table->string('display_phone_number', 30)->nullable();
            $table->string('verified_name')->nullable();
            // Criptografados (cast encrypted). api_key vazia = usa a chave do .env do provedor; o segredo do
            // webhook é gerado pela plataforma (disparo:registrar-webhook), sem fallback.
            $table->text('api_key')->nullable();
            $table->text('webhook_secret')->nullable();
            // Vai na URL do webhook (/api/webhooks/kapso/{webhook_key}): aleatória, não enumerável.
            $table->string('webhook_key', 64)->nullable()->unique();
            // Pausa manual (quem e por quê) é diferente da pausa do disjuntor.
            $table->foreignId('pausado_por')->nullable()->constrained('users')->nullOnDelete();
            // Ex.: ["MARKETING","UTILITY"]. Null = só UTILITY.
            $table->json('categorias_permitidas')->nullable();
            $table->unsignedInteger('teto_diario')->default(200);
            $table->unsignedSmallInteger('msgs_por_minuto')->default(20);
            $table->string('janela_inicio', 5)->default('08:00');
            $table->string('janela_fim', 5)->default('20:00');
            $table->string('fuso', 40)->default('America/Sao_Paulo');
            $table->date('aquecimento_inicio')->nullable();
            $table->unsignedInteger('aquecimento_teto_inicial')->nullable();
            $table->decimal('aquecimento_fator', 4, 2)->nullable();
            $table->timestamp('pausado_em')->nullable();
            $table->string('pausado_motivo', 500)->nullable();
            // Disjuntor "conta impedida" (pagamento, token, saldo): quando a próxima sonda de 1 envio pode sair.
            $table->timestamp('sonda_em')->nullable();
            $table->unsignedSmallInteger('sonda_intervalo_minutos')->default(120);
            // Disjuntor "bloqueio por qualidade" (131048, 368, 131031, RED, engajamento): sem sonda; só
            // humano com `manage senders` religa.
            $table->timestamp('bloqueio_qualidade_em')->nullable();
            $table->string('bloqueio_qualidade_motivo', 500)->nullable();
            // GREEN | YELLOW | RED | UNKNOWN (última leitura).
            $table->string('qualidade', 10)->default('UNKNOWN');
            $table->string('limite_tier', 30)->nullable();
            $table->string('status_meta', 30)->nullable();
            $table->json('saude')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamp('templates_sincronizados_em')->nullable();
            // Cursor da importação do histórico da Kapso (KB 7).
            $table->timestamp('historico_importado_ate')->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senders');
    }
};
