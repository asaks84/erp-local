<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 6d: fatura de cartão de crédito importada do arquivo do banco (OFX ou CSV do Nubank).
// A fatura vira uma conta a pagar só (pelo total); cada compra é classificada numa conta do plano,
// e é a compra que conta no resultado do mês. Regras por estabelecimento ajudam a classificar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 60);
            $t->string('tipo', 10)->default('empresa');       // empresa | pessoal (do sócio: só entram as compras marcadas)
            $t->string('identificador', 80)->nullable();       // conta do cartão no arquivo (ACCTID do OFX)
            $t->unsignedTinyInteger('dia_vencimento')->nullable();
            $t->foreignId('fornecedor_id')->nullable()->constrained('fornecedores');
            $t->boolean('ativo')->default(true);
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'identificador']);
        });

        Schema::create('faturas_cartao', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cartao_id')->constrained('cartoes');
            $t->date('inicio')->nullable();
            $t->date('fechamento');
            $t->date('vencimento');
            $t->decimal('total', 10, 2);                       // soma das compras (menos estornos)
            $t->decimal('saldo_arquivo', 10, 2)->nullable();   // total que o arquivo informa (pode diferir em centavos)
            $t->string('arquivo', 200)->nullable();
            $t->string('arquivo_nome', 150)->nullable();
            $t->foreignId('despesa_id')->nullable()->constrained('despesas');
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['cartao_id', 'fechamento']);
        });

        Schema::create('fatura_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fatura_id')->constrained('faturas_cartao')->cascadeOnDelete();
            $t->string('fitid', 80)->nullable();
            $t->date('data');
            $t->string('original', 150);                       // como veio no arquivo
            $t->string('estabelecimento', 100);                // chave para as regras (minúsculas, sem a parcela)
            $t->unsignedTinyInteger('parcela')->nullable();
            $t->unsignedTinyInteger('parcelas')->nullable();
            $t->decimal('valor', 10, 2);                       // compra positiva; estorno negativo
            $t->foreignId('conta_id')->nullable()->constrained('plano_contas');
            $t->string('descricao', 120)->nullable();          // o que foi (ex.: "Telhas da cobertura")
            $t->boolean('da_empresa')->default(true);          // falso = não é da empresa (fica fora do resultado)
            $t->index(['estabelecimento']);
        });

        // como classificar as compras de cada estabelecimento
        Schema::create('cartao_regras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('estabelecimento', 100);
            $t->string('modo', 10)->default('fixo');           // fixo (sempre a mesma conta) | perguntar
            $t->foreignId('conta_id')->nullable()->constrained('plano_contas');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'estabelecimento']);
        });

        Schema::table('despesas', function (Blueprint $t) {
            $t->foreignId('fatura_id')->nullable()->after('recorrente_id')->constrained('faturas_cartao');
        });
    }

    public function down(): void
    {
        Schema::table('despesas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fatura_id');
        });
        Schema::dropIfExists('cartao_regras');
        Schema::dropIfExists('fatura_itens');
        Schema::dropIfExists('faturas_cartao');
        Schema::dropIfExists('cartoes');
    }
};
