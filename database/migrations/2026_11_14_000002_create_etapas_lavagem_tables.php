<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapas da lavagem configuráveis por empresa (o Local usa aguardando, pronta e entregue; um detailer
// pode registrar cada etapa do serviço) e o histórico de cada venda: etapa, quem registrou e quando.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etapas_lavagem', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('chave', 30);                      // gravada na ordem (ordens_servico.etapa)
            $t->string('nome', 40);
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('ativa')->default(true);
            $t->boolean('fixa')->default(false);          // aguardando (primeira) e entregue (última)
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'chave']);
        });
        Schema::create('ordem_etapas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('ordem_id')->constrained('ordens_servico');   // a ordem principal da venda
            $t->string('etapa', 30);
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->dateTime('quando');
            $t->index(['ordem_id', 'quando']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordem_etapas');
        Schema::dropIfExists('etapas_lavagem');
    }
};
