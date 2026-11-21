<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LGPD: registro de incidentes de segurança com dados pessoais (Sistema › Incidentes). Todo incidente fica registrado,
// comunicado ou não (Res. CD/ANPD 15/2024); não há exclusão.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->unsignedInteger('numero');
            $t->string('titulo', 120);
            $t->dateTime('descoberto_em');                  // quando a empresa soube: o prazo de comunicação conta daqui
            $t->dateTime('ocorrido_em')->nullable();
            $t->text('descricao');
            $t->text('dados_afetados')->nullable();
            $t->unsignedInteger('titulares')->nullable();    // quantas pessoas (estimativa)
            $t->text('contencao')->nullable();               // o que foi feito para conter
            $t->string('risco', 10)->nullable();             // baixo | relevante
            $t->text('justificativa')->nullable();           // por que comunicar ou não
            $t->date('comunicado_anpd_em')->nullable();
            $t->string('protocolo_anpd', 60)->nullable();
            $t->date('comunicado_titulares_em')->nullable();
            $t->dateTime('encerrado_em')->nullable();
            $t->foreignId('registrado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidentes');
    }
};
