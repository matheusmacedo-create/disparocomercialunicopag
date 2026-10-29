<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cache dos templates da Meta por remetente (KB 4.5). O envio reconfere a definição antes de montar. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('senders')->cascadeOnDelete();
            $table->string('meta_id', 40)->nullable();
            $table->string('nome', 512);
            $table->string('idioma', 10);
            // MARKETING | UTILITY | AUTHENTICATION
            $table->string('categoria', 20);
            // APPROVED | PENDING | REJECTED | PAUSED | DISABLED
            $table->string('status', 20);
            // POSITIONAL | NAMED
            $table->string('parameter_format', 20)->nullable();
            $table->json('componentes');
            $table->json('qualidade')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();

            $table->unique(['sender_id', 'nome', 'idioma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
