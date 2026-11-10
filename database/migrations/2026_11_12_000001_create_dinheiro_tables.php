<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 3: dinheiro. Todo valor nasce como ordem de serviço, é agrupado numa cobrança
// e baixado por um pagamento. Dinheiro nunca é apagado: ordem e pagamento são cancelados
// ou estornados com motivo. Ver docs/03-modelo-de-dados.md e docs/05-regras-de-negocio.md.
return new class extends Migration
{
    private function datas(Blueprint $t): void
    {
        $t->timestamp('criado_em')->nullable();
        $t->timestamp('atualizado_em')->nullable();
    }

    public function up(): void
    {
        // sequência própria por empresa e tipo de documento (ordem, cobranca), sem buracos
        Schema::create('numeracoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('tipo', 20);
            $t->unsignedInteger('ultimo')->default(0);
            $t->unique(['empresa_id', 'tipo']);
        });

        Schema::create('contas_financeiras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 60);
            $t->string('tipo', 15);                           // caixa, banco, maquininha
            $t->boolean('ativa')->default(true);
            $t->unsignedSmallInteger('ordem')->default(0);
            $this->datas($t);
            $t->unique(['empresa_id', 'nome']);
        });

        Schema::create('formas_pagamento', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 40);
            $t->string('tipo', 15);                           // dinheiro, pix, debito, credito, boleto, transferencia, nao_informada
            $t->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras');
            $t->decimal('taxa_pct', 5, 2)->default(0);
            $t->decimal('taxa_fixa', 10, 2)->default(0);
            $t->json('taxas_parcelas')->nullable();           // crédito parcelado: {"2": 4.5, "3": 5.2} (% por número de parcelas)
            $t->unsignedTinyInteger('parcelas_max')->default(1);
            $t->unsignedSmallInteger('prazo_dias')->default(0); // dias até o dinheiro cair na conta
            $t->boolean('ativa')->default(true);
            $t->unsignedSmallInteger('ordem')->default(0);
            $this->datas($t);
            $t->unique(['empresa_id', 'nome']);
        });

        Schema::create('ordens_servico', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->uuid('uuid')->unique();                       // para nascer no celular (PDV) sem conflito
            $t->unsignedInteger('numero');                    // número que o cliente vê, por empresa
            $t->string('tipo', 12);                           // mensalidade, servico, estadia, debito, credito
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('veiculo_id')->nullable()->constrained('veiculos');
            $t->foreignId('contrato_id')->nullable()->constrained('mensalidades_contratos');
            $t->foreignId('servico_id')->nullable()->constrained('servicos');
            $t->foreignId('principal_id')->nullable()->constrained('ordens_servico'); // adicional ligado à lavagem principal
            $t->string('descricao', 80);                      // fica como foi lançada, mesmo se o serviço mudar
            $t->string('placa', 10)->nullable();              // cópia, para o histórico
            $t->string('veiculo_descricao', 60)->nullable();
            $t->date('data');                                 // quando aconteceu (geração)
            $t->char('competencia', 7)->nullable();           // mensalidade: "2026-11"
            $t->date('vencimento')->nullable();
            $t->decimal('valor', 10, 2);                      // sempre positivo; crédito abate pelo tipo
            $t->string('situacao', 10)->default('aberta');    // aberta, paga, cancelada
            $t->dateTime('pago_em')->nullable();
            $t->dateTime('cancelada_em')->nullable();
            $t->foreignId('cancelada_por')->nullable()->constrained('usuarios');
            $t->string('motivo_cancelamento', 200)->nullable();
            $t->string('etapa', 12)->nullable();              // lavagem: aguardando, lavando, pronta, entregue
            $t->unsignedBigInteger('cobranca_id')->nullable(); // última cobrança em que entrou
            $t->unsignedBigInteger('pagamento_origem_id')->nullable(); // crédito/débito/serviço nascido de um pagamento
            $t->string('origem', 15)->default('gestao');      // gestao, extrato, importacao, pdv
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'numero']);
            $t->unique(['empresa_id', 'legado_id']);
            $t->index(['empresa_id', 'situacao', 'cliente_id']);
            $t->index(['veiculo_id', 'tipo', 'competencia']);
            $t->index(['empresa_id', 'data']);
        });

        Schema::create('cobrancas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->unsignedInteger('numero');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->char('competencia', 7);
            $t->dateTime('emitida_em');
            $t->date('vencimento');
            $t->decimal('total', 10, 2);
            $t->string('situacao', 10)->default('aberta');    // aberta, parcial, paga, cancelada
            $t->dateTime('cancelada_em')->nullable();
            $t->foreignId('cancelada_por')->nullable()->constrained('usuarios');
            $t->string('motivo_cancelamento', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $this->datas($t);
            $t->unique(['empresa_id', 'numero']);
            $t->index(['empresa_id', 'competencia']);
            $t->index(['cliente_id', 'competencia']);
        });

        // cópia das ordens como estavam na emissão: a cobrança emitida não muda
        Schema::create('cobranca_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cobranca_id')->constrained('cobrancas')->cascadeOnDelete();
            $t->foreignId('ordem_id')->nullable()->constrained('ordens_servico')->nullOnDelete();
            $t->string('grupo', 15)->default('atual');        // atual ou saldo_anterior
            $t->unsignedInteger('numero');
            $t->string('tipo', 12);
            $t->string('descricao', 80);
            $t->string('placa', 10)->nullable();
            $t->date('data');
            $t->date('vencimento')->nullable();
            $t->decimal('valor', 10, 2);                      // com sinal: crédito negativo
        });

        Schema::create('pagamentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->uuid('uuid')->unique();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('forma_pagamento_id')->nullable()->constrained('formas_pagamento');
            $t->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras');
            $t->unsignedTinyInteger('parcelas')->default(1);
            $t->decimal('valor', 10, 2);                      // valor bruto recebido
            $t->decimal('taxa', 10, 2)->default(0);
            $t->decimal('liquido', 10, 2);
            $t->date('previsto_para')->nullable();            // quando o dinheiro cai na conta
            $t->dateTime('recebido_em');
            $t->string('situacao', 12)->default('confirmado'); // confirmado, estornado (pendente/falhou com o Inter)
            $t->dateTime('estornado_em')->nullable();
            $t->foreignId('estornado_por')->nullable()->constrained('usuarios');
            $t->string('motivo_estorno', 200)->nullable();
            $t->string('observacao', 255)->nullable();
            $t->string('origem', 15)->default('gestao');      // gestao, extrato, importacao, pdv, inter
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->string('legado_chave', 40)->nullable();
            $this->datas($t);
            $t->index(['empresa_id', 'recebido_em']);
            $t->index(['cliente_id', 'recebido_em']);
        });

        // quanto de cada pagamento foi para cada ordem
        Schema::create('pagamento_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pagamento_id')->constrained('pagamentos')->cascadeOnDelete();
            $t->foreignId('ordem_id')->constrained('ordens_servico');
            $t->decimal('valor', 10, 2);                      // com sinal: crédito usado entra negativo
            $t->unique(['pagamento_id', 'ordem_id']);
        });

        // correções: desconto, crédito, débito e estorno, sempre com motivo
        Schema::create('ajustes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('tipo', 10);                           // desconto, credito, debito, estorno
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('pagamento_id')->nullable()->constrained('pagamentos');
            $t->foreignId('cobranca_id')->nullable()->constrained('cobrancas');
            $t->foreignId('ordem_id')->nullable()->constrained('ordens_servico');
            $t->decimal('valor', 10, 2);
            $t->string('motivo', 200);
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $this->datas($t);
            $t->index(['empresa_id', 'cliente_id']);
        });

        // PIX já importados do extrato (o mesmo lançamento não baixa duas vezes)
        Schema::create('extrato_lancamentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('identificador', 64);
            $t->date('data');
            $t->decimal('valor', 10, 2);
            $t->string('nome', 100)->nullable();
            $t->string('documento', 14)->nullable();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('pagamento_id')->nullable()->constrained('pagamentos')->nullOnDelete();
            $t->decimal('total_contas', 10, 2)->nullable();
            $t->dateTime('processado_em');
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->string('usuario_nome', 80)->nullable();
            $t->unsignedInteger('legado_id')->nullable();
            $t->unique(['empresa_id', 'identificador']);
            $t->unique(['empresa_id', 'legado_id']);
        });
    }

    public function down(): void
    {
        foreach (['extrato_lancamentos', 'ajustes', 'pagamento_itens', 'pagamentos', 'cobranca_itens', 'cobrancas',
            'ordens_servico', 'formas_pagamento', 'contas_financeiras', 'numeracoes'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
