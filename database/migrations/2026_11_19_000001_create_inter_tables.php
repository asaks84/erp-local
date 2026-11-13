<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 7a: Banco Inter. Credenciais por empresa (criptografadas com a APP_KEY) e boletos com Pix
// emitidos a partir das cobranças. A baixa vem da consulta periódica (sem webhook na hospedagem).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integracoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('tipo', 20);                              // inter (whatsapp depois)
            $t->string('ambiente', 10)->default('producao');     // producao | sandbox
            $t->text('credenciais')->nullable();                 // json criptografado: client_id, client_secret, certificado, chave
            $t->string('conta_corrente', 20)->nullable();
            $t->date('certificado_validade')->nullable();
            $t->boolean('ativo')->default(false);
            $t->dateTime('testado_em')->nullable();
            $t->string('ultimo_erro', 255)->nullable();
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'tipo']);
        });

        Schema::create('boletos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cobranca_id')->constrained('cobrancas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->string('seu_numero', 15);
            $t->string('codigo_solicitacao', 40)->nullable();    // id do Inter
            // em_processamento, a_receber, atrasado, recebido, cancelado, expirado, falha
            $t->string('situacao', 16)->default('em_processamento');
            $t->decimal('valor', 10, 2);
            $t->date('vencimento');
            $t->json('regras')->nullable();                      // multa, juros, desconto e baixa usados na emissão
            $t->string('nosso_numero', 15)->nullable();
            $t->string('linha_digitavel', 60)->nullable();
            $t->string('codigo_barras', 60)->nullable();
            $t->text('pix_copia_cola')->nullable();
            $t->string('txid', 40)->nullable();
            $t->decimal('valor_recebido', 10, 2)->nullable();
            $t->date('recebido_em')->nullable();
            $t->string('recebido_por', 10)->nullable();          // BOLETO | PIX
            $t->foreignId('pagamento_id')->nullable()->constrained('pagamentos');
            $t->string('motivo_cancelamento', 50)->nullable();
            $t->dateTime('cancelado_em')->nullable();
            $t->string('erro', 255)->nullable();
            $t->dateTime('consultado_em')->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'seu_numero']);
            $t->index(['empresa_id', 'situacao']);
            $t->index('cobranca_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boletos');
        Schema::dropIfExists('integracoes');
    }
};
