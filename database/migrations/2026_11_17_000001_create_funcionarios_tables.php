<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Etapa 6b: funcionários (registrados e avulsos), recibos de salário importados do PDF
// do contador e diárias dos avulsos. Cada recibo e cada pagamento de diárias vira uma conta a pagar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funcionarios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 100);
            $t->string('tipo', 12);                       // registrado, avulso
            $t->string('funcao', 60)->nullable();
            $t->string('matricula', 20)->nullable();      // Nº Reg. do recibo do contador
            $t->string('telefone', 20)->nullable();
            $t->string('pix', 120)->nullable();
            $t->decimal('valor_diaria', 10, 2)->nullable(); // avulso
            $t->string('observacao', 255)->nullable();
            $t->boolean('ativo')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['empresa_id', 'nome']);
            $t->unique(['empresa_id', 'matricula']);
        });

        Schema::create('holerites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('funcionario_id')->constrained('funcionarios');
            $t->date('competencia');                      // 1º dia do mês do recibo
            $t->decimal('salario_base', 10, 2)->nullable();
            $t->decimal('vencimentos', 10, 2);
            $t->decimal('descontos', 10, 2);
            $t->decimal('liquido', 10, 2);
            $t->decimal('fgts', 10, 2)->nullable();
            $t->json('itens')->nullable();                // [cod, descricao, referencia, vencimento, desconto]
            $t->string('arquivo', 200)->nullable();
            $t->foreignId('despesa_id')->nullable()->constrained('despesas');
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['funcionario_id', 'competencia']);
        });

        Schema::create('diarias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('funcionario_id')->constrained('funcionarios');
            $t->date('data');
            $t->decimal('valor', 10, 2);
            $t->string('observacao', 120)->nullable();
            $t->foreignId('despesa_id')->nullable()->constrained('despesas'); // preenchido quando é paga
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['funcionario_id', 'data']);
        });

        // a conta do DAS passa a ser do sistema (a leitura de guia sugere ela)
        DB::table('plano_contas')->where('nome', 'Simples Nacional (DAS)')->whereNull('chave')->update(['chave' => 'despesa_simples']);
    }

    public function down(): void
    {
        Schema::dropIfExists('diarias');
        Schema::dropIfExists('holerites');
        Schema::dropIfExists('funcionarios');
        DB::table('plano_contas')->where('chave', 'despesa_simples')->update(['chave' => null]);
    }
};
