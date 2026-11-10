<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anotações do Início: lista de tarefas de cada usuário (ex.: cobrar um cliente, ligar para a contabilidade).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anotacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('usuario_id')->constrained('usuarios');
            $t->string('texto', 500);
            $t->date('para')->nullable();          // até quando (opcional)
            $t->dateTime('feita_em')->nullable();
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['empresa_id', 'usuario_id', 'feita_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anotacoes');
    }
};
