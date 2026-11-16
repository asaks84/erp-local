<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Etapa 7b: WhatsApp. Régua de cobrança (avisos por cobrança), e link público da cobrança
// (o cliente já tem "não recebe avisos"). As credenciais ficam em integracoes (tipo whatsapp), criptografadas.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $t) {
            $t->string('token', 40)->nullable()->unique()->after('numero');
        });
        Schema::create('avisos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cobranca_id')->constrained('cobrancas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->smallInteger('dias');                       // em relação ao vencimento: -3, 0, 3, 10...
            $t->date('previsto_para');
            $t->date('vencimento');                         // vencimento usado no texto
            $t->string('situacao', 10)->default('pendente'); // pendente, enviado, entregue, lido, falha, cancelado
            $t->string('canal', 8)->nullable();             // api | manual
            $t->string('celular', 15)->nullable();
            $t->text('texto')->nullable();
            $t->string('mensagem_id', 120)->nullable();     // id da mensagem na Meta
            $t->string('erro', 255)->nullable();
            $t->dateTime('enviado_em')->nullable();
            $t->foreignId('enviado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['cobranca_id', 'dias']);
            $t->index(['empresa_id', 'previsto_para']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos');
        Schema::table('cobrancas', fn (Blueprint $t) => $t->dropColumn('token'));
    }
};
