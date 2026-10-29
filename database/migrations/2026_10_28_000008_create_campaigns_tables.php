<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Campanhas, destinatários (um por contato) e a trilha humana de eventos (KB 4.6). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->foreignId('sender_id')->constrained('senders')->restrictOnDelete();
            // recuperar | ativar | ofertar | avisar: regra da casa mais forte que a categoria (KB 2.4).
            $table->string('objetivo', 15)->default('ofertar');
            $table->string('template_nome', 512);
            $table->string('template_idioma', 10)->default('pt_BR');
            // Categoria congelada na aprovação: se a Meta reclassificar, a campanha volta para aprovação.
            $table->string('template_categoria', 20)->nullable();
            // O que foi aprovado (destinatários materializados + template + política do remetente): o
            // executor confere antes de cada rodada e devolve para aprovação se divergir.
            $table->string('aprovacao_hash', 64)->nullable();
            // 1 = primeiro na fila do remetente; réguas sempre antes das campanhas.
            $table->unsignedTinyInteger('prioridade')->default(5);
            // corpo (lista ou mapa), cabecalho_texto, cabecalho_midia_url, cabecalho_midia_tipo, botao_url_sufixo
            $table->json('variaveis')->nullable();
            // listas, segment_id, excluir_listas, excluir_tags
            $table->json('publico')->nullable();
            // ignorar_conversa_aberta, max_por_dia
            $table->json('regras')->nullable();
            $table->timestamp('agendada_para')->nullable();
            // rascunho | aguardando_aprovacao | aprovada | em_andamento | pausada | concluida | cancelada
            $table->string('status', 25)->default('rascunho')->index();
            // Última prévia: contagens e motivos de exclusão. O hash prova que ela é posterior à última edição.
            $table->json('simulacao')->nullable();
            $table->string('simulacao_hash', 64)->nullable();
            $table->decimal('custo_estimado', 10, 2)->nullable();
            // Tranches: a primeira leva é pequena; a campanha só avança depois de ler os webhooks.
            $table->unsignedSmallInteger('tranches_enviadas')->default(0);
            $table->timestamp('tranche_aguardando_ate')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('concluida_em')->nullable();
            $table->string('pausada_motivo', 500)->nullable();
            $table->unsignedInteger('total_destinatarios')->default(0);
            $table->unsignedInteger('enviados')->default(0);
            $table->unsignedInteger('entregues')->default(0);
            $table->unsignedInteger('lidos')->default(0);
            $table->unsignedInteger('respondidos')->default(0);
            $table->unsignedInteger('falhas')->default(0);
            $table->unsignedInteger('pulados')->default(0);
            $table->text('observacoes')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            // Congelados na montagem do público.
            $table->string('telefone', 20);
            $table->json('variaveis')->nullable();
            // pendente | enviando | enviado | entregue | lido | respondido | falhou | pulado
            $table->string('status', 15)->default('pendente');
            // Motivo do guard (optout, conversa_aberta, frequencia...) ou da falha.
            $table->string('motivo', 100)->nullable();
            $table->string('wamid', 120)->nullable()->index();
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('whatsapp_messages')->nullOnDelete();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamp('lido_em')->nullable();
            $table->timestamp('respondido_em')->nullable();
            // Converteu depois do envio (vendeu, abriu cadastro): preenchido pela sincronização de sellers/KYC.
            $table->timestamp('convertido_em')->nullable();
            $table->string('conversao_origem', 30)->nullable();
            $table->text('erro')->nullable();
            $table->string('codigo_erro', 40)->nullable();
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('campaign_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // criada | simulada | enviada_para_aprovacao | aprovada | reprovada | iniciada | pausada | retomada | cancelada | concluida | disjuntor | teste_enviado
            $table->string('evento', 40);
            $table->json('detalhes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
