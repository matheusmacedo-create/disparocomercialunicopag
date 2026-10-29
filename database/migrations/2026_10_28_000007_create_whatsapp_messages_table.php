<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trilha de mensagens: tudo o que saiu ou chegou por um remetente, inclusive o histórico importado da
 * Kapso (KB 4.7). Append-only; os status de entrega só avançam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('senders')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('telefone', 20);
            // inbound | outbound
            $table->string('direcao', 10);
            // wamid da Meta. Único quando presente (NULL não colide).
            $table->string('wamid', 120)->nullable()->unique();
            // Idempotência do que não tem wamid (destinatário de broadcast da Kapso: "broadcast:{id}:{rid}").
            $table->string('external_key', 120)->nullable()->unique();
            $table->string('tipo', 20)->nullable();
            $table->string('template_nome', 512)->nullable();
            // Texto recebido (só com `view conversations`), ou o resumo do template enviado com os valores
            // (só com `view pii`). Histórico importado da Kapso não guarda texto (KB 2.13).
            $table->text('conteudo')->nullable();
            // Autor do texto livre mandado pelo painel.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // pending | sent | delivered | read | failed | received
            $table->string('status', 15)->index();
            $table->string('codigo_erro', 40)->nullable();
            $table->string('erro', 1000)->nullable();
            // campanha | regua | manual | historico_kapso | broadcast_kapso | webhook
            $table->string('origem', 20)->index();
            // Sem FK: campaign_recipients aponta para cá (whatsapp_message_id) e cá para lá.
            $table->unsignedBigInteger('campaign_recipient_id')->nullable()->index();
            $table->unsignedBigInteger('journey_enrollment_id')->nullable()->index();
            // A mensagem que esta responde (message.context.id).
            $table->string('context_wamid', 120)->nullable()->index();
            $table->string('kapso_conversation_id', 64)->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamp('lido_em')->nullable();
            // Payload cru, enxuto (sem mídia).
            $table->json('payload')->nullable();
            // Resposta do cliente tratada por alguém (tela Conversas): quem e quando.
            $table->timestamp('tratada_em')->nullable();
            $table->foreignId('tratada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sender_id', 'telefone', 'direcao', 'enviado_em'], 'wm_sender_telefone_direcao_enviado');
            $table->index(['sender_id', 'enviado_em']);
            $table->index(['contact_id', 'enviado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
