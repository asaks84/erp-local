<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Etapa 2: clientes, veículos, contratos de mensalidade, preços (tipos, tarifas, serviços),
// convênios, fila do cadastro público e pagadores vinculados. Ver docs/03-modelo-de-dados.md.
return new class extends Migration
{
    private function datas(Blueprint $t): void
    {
        $t->timestamp('criado_em')->nullable();
        $t->timestamp('atualizado_em')->nullable();
    }

    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            // código do link público de cadastro (/cadastro/<codigo>), para o QR Code do balcão
            $t->string('codigo_publico', 20)->nullable()->unique()->after('logo');
        });

        Schema::create('tipos_veiculo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 30);
            $t->string('detalhe', 80)->nullable();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->boolean('ativo')->default(true);
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'nome']);
            $t->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nome', 100);
            $t->string('documento', 14)->nullable();          // CPF (11) ou CNPJ (14), só números
            $t->string('telefone', 15)->nullable();
            $t->string('celular', 15)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('cep', 8)->nullable();
            $t->string('logradouro', 100)->nullable();
            $t->string('numero', 10)->nullable();
            $t->string('complemento', 80)->nullable();
            $t->string('bairro', 60)->nullable();
            $t->string('cidade', 60)->nullable();
            $t->char('uf', 2)->nullable();
            $t->unsignedTinyInteger('dia_vencimento')->default(5);
            $t->date('cliente_desde')->nullable();
            $t->boolean('nao_recebe_avisos')->default(false);
            $t->string('observacoes', 500)->nullable();
            $t->boolean('ativo')->default(true);
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->string('origem', 20)->default('gestao');      // gestao, cadastro_publico, importacao
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'documento']);
            $t->unique(['empresa_id', 'legado_id']);
            $t->index(['empresa_id', 'nome']);
        });

        Schema::create('veiculos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('tipo_veiculo_id')->constrained('tipos_veiculo');
            $t->string('placa', 10)->nullable();              // maiúsculas, sem traço nem espaço
            $t->string('identificacao', 60)->nullable();      // sem placa: número do quadro, série, apelido
            $t->string('marca', 30)->nullable();
            $t->string('modelo', 40)->nullable();
            $t->string('cor', 20)->nullable();
            $t->boolean('ativo')->default(true);
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'legado_id']);
            $t->index(['empresa_id', 'placa']);
        });
        // placa não se repete entre veículos ATIVOS da mesma empresa
        DB::statement('ALTER TABLE veiculos ADD placa_ativa VARCHAR(10) AS (IF(ativo = 1, placa, NULL)) STORED');
        DB::statement('ALTER TABLE veiculos ADD UNIQUE veiculos_placa_ativa_unique (empresa_id, placa_ativa)');

        Schema::create('veiculo_placas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('veiculo_id')->constrained('veiculos');
            $t->string('placa_anterior', 10)->nullable();
            $t->string('placa_nova', 10)->nullable();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->timestamp('criado_em')->useCurrent();
        });

        Schema::create('mensalidades_contratos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->constrained('unidades');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->foreignId('veiculo_id')->constrained('veiculos');
            $t->string('periodo', 30);
            $t->decimal('valor', 10, 2);
            $t->date('inicio');
            $t->date('fim')->nullable();                      // encerramento (agendado ou feito)
            $t->string('motivo_fim', 200)->nullable();
            $t->foreignId('anterior_id')->nullable()->constrained('mensalidades_contratos'); // versão anterior (reajuste)
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->index(['empresa_id', 'veiculo_id', 'inicio']);
            $t->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('mensalidade_pausas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('contrato_id')->constrained('mensalidades_contratos');
            $t->date('inicio');                               // primeiro mês pausado (dia 1)
            $t->date('fim');                                  // último mês pausado (último dia)
            $t->string('taxa_tipo', 5)->default('valor');     // valor ou pct
            $t->decimal('taxa_valor', 10, 2)->default(0);     // 0 = pausa grátis
            $t->string('motivo', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $this->datas($t);
        });

        Schema::create('tarifas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades'); // vazio = vale para a empresa
            $t->string('nome', 60);
            $t->unsignedSmallInteger('tolerancia_min')->default(0);
            $t->unsignedSmallInteger('fracao_min')->default(0);
            $t->string('restante', 10)->default('faixas');   // faixas ou pacotes
            $t->date('vigencia_inicio');
            $t->date('vigencia_fim')->nullable();
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('tarifa_faixas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tarifa_id')->constrained('tarifas')->cascadeOnDelete();
            $t->unsignedSmallInteger('ordem')->default(0);
            $t->unsignedSmallInteger('duracao_min')->nullable(); // vazio = até o fim
            $t->string('modo', 5)->default('bloco');            // fixo ou bloco
            $t->unsignedSmallInteger('bloco_min')->default(60);
            $t->decimal('valor', 10, 2)->default(0);
        });

        Schema::create('tarifa_pacotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tarifa_id')->constrained('tarifas')->cascadeOnDelete();
            $t->string('nome', 40);
            $t->unsignedInteger('duracao_min')->nullable();
            $t->decimal('valor', 10, 2)->default(0);
            $t->char('hora_inicio', 5)->nullable();
            $t->char('hora_fim', 5)->nullable();
        });

        Schema::create('tarifa_tipos', function (Blueprint $t) {
            $t->foreignId('tarifa_id')->constrained('tarifas')->cascadeOnDelete();
            $t->foreignId('tipo_veiculo_id')->constrained('tipos_veiculo');
            $t->primary(['tarifa_id', 'tipo_veiculo_id']);
        });

        Schema::create('servicos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('codigo', 10);
            $t->string('nome', 60);
            $t->string('papel', 10)->default('principal');      // principal ou adicional
            $t->string('preco_modo', 5)->default('fixo');       // fixo ou ate (valor máximo, definido na venda)
            $t->string('detalhe', 160)->nullable();
            $t->string('codigo_servico_municipal', 20)->nullable();
            $t->decimal('aliquota_iss', 5, 2)->nullable();
            $t->boolean('ativo')->default(true);
            $t->string('legado_codigo', 10)->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'codigo']);
        });

        Schema::create('servico_adicionais', function (Blueprint $t) {
            $t->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();
            $t->foreignId('adicional_id')->constrained('servicos')->cascadeOnDelete();
            $t->primary(['servico_id', 'adicional_id']);
        });

        Schema::create('servico_precos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();
            $t->foreignId('tipo_veiculo_id')->constrained('tipos_veiculo');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades'); // vazio = preço da empresa
            $t->decimal('valor', 10, 2);
            $t->date('vigencia_inicio');
            $t->date('vigencia_fim')->nullable();
            $this->datas($t);
            $t->index(['servico_id', 'tipo_veiculo_id', 'vigencia_inicio']);
        });

        Schema::create('convenios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->string('modo', 10)->default('tudo');            // tudo, tempo, desconto
            $t->unsignedInteger('limite_min')->nullable();
            $t->string('desconto_tipo', 5)->nullable();         // pct ou valor
            $t->decimal('desconto_valor', 10, 2)->nullable();
            $t->boolean('vale_estadia')->default(true);
            $t->boolean('vale_servicos')->default(false);
            $t->string('observacoes', 255)->nullable();
            $t->boolean('ativo')->default(true);
            $t->foreignId('atualizado_por')->nullable()->constrained('usuarios');
            $this->datas($t);
            $t->unique(['empresa_id', 'cliente_id']);
        });

        Schema::create('cadastros_pendentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->dateTime('recebido_em');
            $t->string('ip', 45)->nullable();
            $t->dateTime('consentimento_em');
            $t->string('nome', 100);
            $t->string('documento', 14)->nullable();
            $t->string('telefone', 15)->nullable();
            $t->string('celular', 15)->nullable();
            $t->string('email', 120)->nullable();
            $t->string('cep', 8)->nullable();
            $t->string('logradouro', 100)->nullable();
            $t->string('numero', 10)->nullable();
            $t->string('complemento', 80)->nullable();
            $t->string('bairro', 60)->nullable();
            $t->string('cidade', 60)->nullable();
            $t->char('uf', 2)->nullable();
            $t->string('placa', 10)->nullable();
            $t->string('marca', 30)->nullable();
            $t->string('modelo', 40)->nullable();
            $t->string('cor', 20)->nullable();
            $t->foreignId('tipo_veiculo_id')->nullable()->constrained('tipos_veiculo');
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('pagadores_vinculados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->string('nome', 100);
            $t->string('documento', 14)->nullable();
            $t->string('conta', 60)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->unsignedInteger('legado_id')->nullable();
            $this->datas($t);
            $t->unique(['empresa_id', 'legado_id']);
        });
    }

    public function down(): void
    {
        foreach (['pagadores_vinculados', 'cadastros_pendentes', 'convenios', 'servico_precos', 'servico_adicionais', 'servicos',
            'tarifa_tipos', 'tarifa_pacotes', 'tarifa_faixas', 'tarifas', 'mensalidade_pausas', 'mensalidades_contratos',
            'veiculo_placas', 'veiculos', 'clientes', 'tipos_veiculo'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('codigo_publico'));
    }
};
