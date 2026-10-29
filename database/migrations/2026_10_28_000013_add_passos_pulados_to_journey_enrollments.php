<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passo de régua barrado por guarda (frequência, bloqueio, sem WhatsApp...) não some em silêncio: fica
 * registrado na inscrição, com o motivo, para o operador ver na ficha do contato (achado da revisão de
 * 28/10/2026: "passo bloqueado por guarda fica pulado com motivo visível").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journey_enrollments', function (Blueprint $table): void {
            // [{passo, tipo, motivo, em}], os últimos 50.
            $table->json('passos_pulados')->nullable()->after('ultimo_wamid');
            // Último motivo de guarda ou de recusa visto nesta inscrição (também quando só adiou).
            $table->string('ultimo_motivo', 100)->nullable()->after('passos_pulados');
            // Por onde o contato entrou: manual, lista, tag, segmento, api... (não é motivo de saída).
            $table->string('origem', 20)->nullable()->after('inscrito_por');
        });
    }

    public function down(): void
    {
        Schema::table('journey_enrollments', function (Blueprint $table): void {
            $table->dropColumn(['passos_pulados', 'ultimo_motivo', 'origem']);
        });
    }
};
