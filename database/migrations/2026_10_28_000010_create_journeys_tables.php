<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Réguas de nutrição: passos, esperas e inscrições (KB 4.9). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journeys', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->foreignId('sender_id')->constrained('senders')->restrictOnDelete();
            // recuperar | ativar | ofertar | avisar (mesma regra de objetivo das campanhas).
            $table->string('objetivo', 15)->default('recuperar');
            // manual | lista | tag | segmento
            $table->string('gatilho', 15)->default('manual');
            $table->unsignedBigInteger('gatilho_ref')->nullable();
            // Mesma máquina de estados da campanha: rascunho → aguardando_aprovacao → aprovada → ativa | pausada.
            // Qualquer edição de passo, variável ou gatilho volta a rascunho (KB 2.2).
            $table->string('status', 25)->default('rascunho')->index();
            $table->boolean('ativa')->default(false);
            $table->boolean('sair_ao_responder')->default(true);
            // Vendeu / abriu cadastro depois do envio: sai com motivo `converteu`.
            $table->boolean('sair_ao_converter')->default(true);
            // Gatilho por segmento: quem concluiu só volta depois de N dias (nulo = nunca).
            $table->unsignedSmallInteger('reinscrever_apos_dias')->nullable();
            // Régua é a mensagem esperada: não espera a conversa aberta de 7 dias, só o intervalo mínimo.
            $table->boolean('ignora_conversa_aberta')->default(true);
            $table->unsignedSmallInteger('intervalo_minimo_horas')->default(48);
            $table->unsignedInteger('max_por_dia')->default(50);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('aprovado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('journey_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('journeys')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem');
            // template | aguardar | condicao | tag | encerrar
            $table->string('tipo', 15);
            $table->string('template_nome', 512)->nullable();
            $table->string('template_idioma', 10)->nullable();
            $table->json('variaveis')->nullable();
            $table->unsignedInteger('aguardar_horas')->nullable();
            // sempre | se_nao_respondeu | se_respondeu | se_nao_entregue
            $table->string('condicao', 20)->nullable();
            $table->foreignId('tag_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->timestamps();

            $table->unique(['journey_id', 'ordem']);
        });

        Schema::create('journey_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journey_id')->constrained('journeys')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('passo_atual')->default(1);
            // ativa | concluida | saiu | pausada | falhou
            $table->string('status', 15)->default('ativa')->index();
            // "journey:contact" enquanto ativa, NULL ao encerrar: uma inscrição ativa por contato, mesmo com
            // dois workers (unique parcial não existe no MySQL - mesmo truque de suppressions.chave).
            $table->string('chave_ativa', 40)->nullable()->unique();
            $table->foreignId('inscrito_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proximo_em')->nullable()->index();
            $table->string('motivo_saida', 200)->nullable();
            $table->string('ultimo_wamid', 120)->nullable();
            $table->timestamp('convertido_em')->nullable();
            $table->string('conversao_origem', 30)->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('encerrada_em')->nullable();
            $table->timestamps();

            $table->index(['journey_id', 'contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_enrollments');
        Schema::dropIfExists('journey_steps');
        Schema::dropIfExists('journeys');
    }
};
