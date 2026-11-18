<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV 8.3 (caixa): o caixa do balcão (aberto com o troco deixado no fechamento anterior, fechado uma vez
// por dia pelo gerente) e os movimentos de dinheiro da gaveta: suprimento, sangria (cofre ou banco),
// pagamento de despesa e retirada do sócio. Retirada do sócio feita pelo banco é registrada na gestão
// (movimento sem caixa).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caixas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->string('situacao', 10)->default('aberto');              // aberto, fechado
            $t->dateTime('aberto_em');
            $t->foreignId('aberto_por')->nullable()->constrained('usuarios');
            $t->decimal('troco_inicial', 10, 2)->default(0);
            $t->dateTime('fechado_em')->nullable();
            $t->foreignId('fechado_por')->nullable()->constrained('usuarios');
            $t->foreignId('fechado_aparelho_id')->nullable()->constrained('aparelhos');
            $t->decimal('dinheiro_esperado', 10, 2)->nullable();
            $t->decimal('dinheiro_contado', 10, 2)->nullable();
            $t->decimal('diferenca', 10, 2)->nullable();
            $t->decimal('troco_deixado', 10, 2)->nullable();
            $t->string('observacao', 255)->nullable();
            $t->json('resumo')->nullable();                             // retrato do fechamento
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao']);
        });

        Schema::create('caixa_movimentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('caixa_id')->nullable()->constrained('caixas');  // sem caixa: retirada do sócio registrada na gestão
            $t->string('tipo', 12);                                     // suprimento, sangria, pagamento, retirada
            $t->date('data');
            $t->decimal('valor', 10, 2);
            $t->string('destino', 10)->nullable();                      // sangria: cofre, banco
            $t->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras'); // sangria no banco; retirada pelo banco
            $t->foreignId('plano_conta_id')->nullable()->constrained('plano_contas');            // pagamento: categoria da despesa
            $t->foreignId('despesa_id')->nullable()->constrained('despesas');                    // pagamento: a despesa criada
            $t->string('descricao', 120)->nullable();
            $t->string('motivo', 200)->nullable();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->foreignId('aparelho_id')->nullable()->constrained('aparelhos');
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'tipo', 'data']);
        });

        Schema::table('pagamentos', function (Blueprint $t) {
            $t->foreignId('caixa_id')->nullable()->after('aparelho_id')->constrained('caixas');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('caixa_id');
        });
        Schema::dropIfExists('caixa_movimentos');
        Schema::dropIfExists('caixas');
    }
};
