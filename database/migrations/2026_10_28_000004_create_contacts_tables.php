<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contatos, tags, bases e segmentos (KB 4.2 e 4.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            // E.164 com "+" (+5521998765432). Um contato por telefone.
            $table->string('telefone', 20)->unique();
            // sha256 dos dígitos: casa com suppressions.telefone_hash e sobrevive à anonimização.
            $table->char('telefone_hash', 64)->index();
            $table->string('nome')->nullable();
            $table->string('primeiro_nome', 80)->nullable();
            $table->string('email')->nullable()->index();
            // CPF/CNPJ criptografado (cast encrypted); o hash serve à busca e ao dedupe.
            $table->text('documento')->nullable();
            $table->char('documento_hash', 64)->nullable()->index();
            $table->string('empresa')->nullable();
            // unicodrop | unicopag | csv | kapso | unicoapi | manual
            $table->string('origem', 20)->default('manual')->index();
            // seller_inativo | seller_ativo | lead | cliente | outro
            $table->string('tipo', 30)->default('outro')->index();
            // accounts.id no unico-api.
            $table->string('account_id', 64)->nullable()->index();
            // Colunas extras do CSV: viram {{atributos.coluna}}.
            $table->json('atributos')->nullable();
            // ativo | optout | invalido | anonimizado
            $table->string('status', 15)->default('ativo')->index();
            $table->timestamp('optout_em')->nullable();
            $table->string('optout_motivo', 100)->nullable();
            // resposta | manual | importacao | meta_preferencia | historico
            $table->string('optout_origem', 30)->nullable();
            // 131026: quarentena de 90 dias.
            $table->timestamp('sem_whatsapp_em')->nullable();
            // 131049 (a Meta limitou marketing a este usuário): carência de 7 dias, nunca supressão.
            $table->timestamp('marketing_carencia_ate')->nullable();
            // "Não"/"agora não" a template MARKETING: carência de 90 dias; duas recusas = supressão de marketing.
            $table->timestamp('recusou_em')->nullable();
            $table->unsignedSmallInteger('recusas_total')->default(0);
            // Template `sent` sem `delivered` por 48 h: provável bloqueio (fora de MARKETING por 180 dias).
            $table->timestamp('provavel_bloqueio_em')->nullable();
            // Consentimento estruturado (LGPD + opt-in da Meta): como, quando, por qual canal, com que prova.
            $table->string('consentimento_origem', 30)->nullable();
            $table->timestamp('consentimento_em')->nullable();
            $table->string('consentimento_canal', 30)->nullable();
            $table->string('consentimento_detalhes', 500)->nullable();
            $table->unsignedInteger('envios_total')->default(0);
            $table->unsignedInteger('entregues_total')->default(0);
            $table->unsignedInteger('lidos_total')->default(0);
            $table->unsignedInteger('respostas_total')->default(0);
            $table->unsignedInteger('falhas_total')->default(0);
            $table->timestamp('ultimo_envio_em')->nullable()->index();
            $table->timestamp('ultimo_template_em')->nullable();
            $table->timestamp('ultima_resposta_em')->nullable()->index();
            $table->string('kapso_contact_id', 64)->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamp('anonimizado_em')->nullable();
            $table->timestamps();

            $table->index(['status', 'origem']);
            $table->index(['status', 'tipo']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('nome', 60)->unique();
            $table->string('cor', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('contact_tag', function (Blueprint $table): void {
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['contact_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('contact_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->string('slug', 80)->unique();
            $table->text('descricao')->nullable();
            // unicopag | unicodrop | compartilhada
            $table->string('dono', 20)->default('unicopag');
            $table->boolean('exige_consentimento')->default(false);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('segments', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->json('regras');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
        Schema::dropIfExists('contact_lists');
        Schema::dropIfExists('contact_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('contacts');
    }
};
