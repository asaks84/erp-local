<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 6a: plano de contas (receitas e despesas em árvore), fornecedores, contas a pagar
// (despesas, com parcelas, pagamento e comprovante) e contas fixas que se repetem todo mês.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_contas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('pai_id')->nullable()->constrained('plano_contas');
            $t->string('tipo', 10);                     // receita, despesa
            $t->string('codigo', 20);                   // 2.3.1 (montado pela posição na árvore)
            $t->string('nome', 60);
            $t->string('chave', 30)->nullable();        // contas do sistema (ex.: receita_mensalidade): não se apagam
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('ativa')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'chave']);
            $t->index(['empresa_id', 'tipo', 'codigo']);
        });

        Schema::create('fornecedores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 100);
            $t->string('documento', 14)->nullable();    // CPF ou CNPJ, só números
            $t->string('telefone', 20)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('pix', 120)->nullable();
            $t->foreignId('conta_padrao_id')->nullable()->constrained('plano_contas');
            $t->string('observacao', 255)->nullable();
            $t->boolean('ativo')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['empresa_id', 'nome']);
        });

        Schema::create('despesas_recorrentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->foreignId('fornecedor_id')->nullable()->constrained('fornecedores');
            $t->foreignId('conta_id')->constrained('plano_contas');
            $t->string('descricao', 120);
            $t->decimal('valor', 10, 2);                 // previsto (a conta do mês pode ser ajustada)
            $t->unsignedTinyInteger('dia_vencimento');
            $t->date('inicio');                          // primeiro mês
            $t->date('fim')->nullable();                 // último mês (vazio = sem fim)
            $t->boolean('ativa')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
        });

        Schema::create('despesas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->foreignId('fornecedor_id')->nullable()->constrained('fornecedores');
            $t->foreignId('conta_id')->constrained('plano_contas');
            $t->foreignId('recorrente_id')->nullable()->constrained('despesas_recorrentes');
            $t->string('descricao', 120);
            $t->date('competencia');                     // mês a que o gasto se refere (dia 1)
            $t->date('vencimento');
            $t->decimal('valor', 10, 2);
            $t->uuid('grupo')->nullable();               // parcelas da mesma compra
            $t->unsignedSmallInteger('parcela')->nullable();
            $t->unsignedSmallInteger('parcelas')->nullable();
            $t->string('situacao', 10)->default('aberta'); // aberta, paga, cancelada
            $t->date('pago_em')->nullable();
            $t->decimal('valor_pago', 10, 2)->nullable();
            $t->foreignId('forma_pagamento_id')->nullable()->constrained('formas_pagamento');
            $t->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras'); // de onde saiu
            $t->string('comprovante', 255)->nullable();  // caminho no disco privado
            $t->string('comprovante_nome', 150)->nullable();
            $t->string('observacao', 255)->nullable();
            $t->dateTime('cancelada_em')->nullable();
            $t->foreignId('cancelada_por')->nullable()->constrained('usuarios');
            $t->string('motivo_cancelamento', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->foreignId('pago_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao', 'vencimento']);
            $t->index(['empresa_id', 'competencia']);
            $t->unique(['recorrente_id', 'competencia']);   // a fixa gera uma conta por mês, nunca duas
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despesas');
        Schema::dropIfExists('despesas_recorrentes');
        Schema::dropIfExists('fornecedores');
        Schema::dropIfExists('plano_contas');
    }
};
