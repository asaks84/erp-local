<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 4: operação na gestão. Estadia = ticket (entrada e saída de um veículo); o valor vira
// ordem de serviço na saída (paga na hora, na conta do cliente ou na do convênio).
// Venda de lavagens e serviços usa as próprias ordens, com a etapa da lavagem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estadias', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('numero');                         // número do ticket, por empresa
            $t->string('placa', 10)->nullable();
            $t->string('identificacao', 40)->nullable();           // sem placa: descrição do veículo
            $t->foreignId('veiculo_id')->nullable()->constrained('veiculos');
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('tipo_veiculo_id')->constrained('tipos_veiculo');
            $t->string('celular', 11)->nullable();                 // avulso: para histórico e aviso
            $t->dateTime('entrada');
            $t->dateTime('saida')->nullable();
            $t->unsignedInteger('minutos')->nullable();
            $t->foreignId('tarifa_id')->nullable()->constrained('tarifas');
            $t->decimal('valor_calculado', 10, 2)->nullable();     // pelo tarifador
            $t->foreignId('convenio_id')->nullable()->constrained('convenios');
            $t->decimal('valor_convenio', 10, 2)->default(0);      // parte que o convênio paga
            $t->decimal('desconto', 10, 2)->default(0);            // desconto do convênio para quem usou
            $t->decimal('valor_cliente', 10, 2)->nullable();       // o que quem usou paga
            $t->boolean('mensalista')->default(false);
            $t->string('situacao', 12)->default('patio');          // patio, finalizada, cancelada
            $t->string('destino', 12)->nullable();                 // pago, conta, convenio, mensalista, gratis
            $t->json('detalhes')->nullable();                      // memória de cálculo do tarifador
            $t->unsignedBigInteger('ordem_id')->nullable();        // ordem de quem usou
            $t->unsignedBigInteger('ordem_convenio_id')->nullable();
            $t->dateTime('cancelada_em')->nullable();
            $t->foreignId('cancelada_por')->nullable()->constrained('usuarios');
            $t->string('motivo_cancelamento', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->foreignId('finalizado_por')->nullable()->constrained('usuarios');
            $t->string('origem', 15)->default('gestao');           // gestao, pdv
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'numero']);
            $t->index(['empresa_id', 'situacao']);
            $t->index(['empresa_id', 'placa']);
        });

        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->foreignId('estadia_id')->nullable()->after('principal_id')->constrained('estadias');
            $t->foreignId('tipo_veiculo_id')->nullable()->after('veiculo_descricao')->constrained('tipos_veiculo');
            $t->string('celular', 11)->nullable()->after('tipo_veiculo_id');      // venda avulsa
            $t->foreignId('convenio_id')->nullable()->after('celular')->constrained('convenios');
            $t->index(['empresa_id', 'etapa']);
            $t->index(['empresa_id', 'placa']);
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->dropConstrainedForeignId('estadia_id');
            $t->dropConstrainedForeignId('tipo_veiculo_id');
            $t->dropConstrainedForeignId('convenio_id');
            $t->dropIndex(['empresa_id', 'etapa']);
            $t->dropIndex(['empresa_id', 'placa']);
            $t->dropColumn('celular');
        });
        Schema::dropIfExists('estadias');
    }
};
