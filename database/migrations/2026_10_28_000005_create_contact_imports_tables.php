<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Importações (CSV, histórico da Kapso, sellers do unico-api, opt-out) e a pertinência às bases (KB 4.4). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table): void {
            $table->id();
            // csv | kapso | unicoapi | optout
            $table->string('tipo', 20);
            $table->string('arquivo')->nullable();
            $table->string('nome_original')->nullable();
            // coluna do CSV => campo do contato (telefone, nome, email, documento, empresa, atributos.x, ignorar)
            $table->json('mapeamento')->nullable();
            $table->foreignId('contact_list_id')->nullable()->constrained('contact_lists')->nullOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('senders')->nullOnDelete();
            $table->string('origem', 20)->nullable();
            $table->string('tipo_contato', 30)->nullable();
            $table->json('tags')->nullable();
            $table->string('consentimento_origem')->nullable();
            // desde/ate do histórico, dias dos inativos...
            $table->json('parametros')->nullable();
            // pendente | processando | concluida | falhou
            $table->string('status', 15)->default('pendente')->index();
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('criados')->default(0);
            $table->unsignedInteger('atualizados')->default(0);
            $table->unsignedInteger('ignorados')->default(0);
            $table->unsignedInteger('invalidos')->default(0);
            $table->unsignedInteger('duplicados')->default(0);
            $table->unsignedInteger('optouts')->default(0);
            // Os primeiros 100 problemas: linha e motivo, sem o telefone completo.
            $table->json('erros')->nullable();
            $table->text('mensagem')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_list_contact', function (Blueprint $table): void {
            $table->foreignId('contact_list_id')->constrained('contact_lists')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('contact_import_id')->nullable()->constrained('contact_imports')->nullOnDelete();
            $table->timestamp('adicionado_em')->nullable();

            $table->primary(['contact_list_id', 'contact_id']);
            $table->index('contact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_list_contact');
        Schema::dropIfExists('contact_imports');
    }
};
