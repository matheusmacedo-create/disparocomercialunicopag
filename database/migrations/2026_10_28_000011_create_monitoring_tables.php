<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Qualidade do número ao longo do tempo e a trilha dos webhooks (KB 4.10). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_quality_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->constrained('senders')->cascadeOnDelete();
            $table->string('qualidade', 10);
            $table->string('limite_tier', 30)->nullable();
            $table->string('status_meta', 30)->nullable();
            $table->timestamp('medido_em')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['sender_id', 'medido_em']);
        });

        Schema::create('webhook_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('senders')->nullOnDelete();
            // kapso | meta
            $table->string('provider', 10);
            $table->string('evento', 60)->nullable();
            // X-Idempotency-Key da Kapso: a entrega repetida não é processada duas vezes.
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('payload')->nullable();
            $table->string('resultado', 200)->nullable();
            $table->timestamp('processado_em')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_calls');
        Schema::dropIfExists('sender_quality_snapshots');
    }
};
