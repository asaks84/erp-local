<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Estorno ao cliente: devolução do crédito que ele tem (o Pix/transferência é feito no app do banco;
// aqui fica o registro). Baixa o crédito e entra como saída no fluxo de caixa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estornos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->decimal('valor', 10, 2);
            $t->date('estornado_em');                              // dia em que o dinheiro saiu
            $t->foreignId('forma_pagamento_id')->nullable()->constrained('formas_pagamento');
            $t->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras');
            $t->string('motivo', 200);
            $t->json('creditos');                                  // ordens de crédito usadas
            $t->foreignId('credito_restante_id')->nullable()->constrained('ordens_servico'); // sobra do crédito, se o estorno foi parcial
            $t->string('situacao', 10)->default('feito');         // feito, desfeito
            $t->dateTime('desfeito_em')->nullable();
            $t->foreignId('desfeito_por')->nullable()->constrained('usuarios');
            $t->string('motivo_desfeito', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'estornado_em']);
            $t->index(['cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estornos');
    }
};
