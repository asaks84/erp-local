<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV 8.5 (Pix imediato): cada QR mostrado no balcão é uma cobrança imediata do Inter (cob). O PDV consulta
// até ela ficar paga; o pagamento registrado em seguida guarda o txid, e o extrato do Inter deixa esse Pix
// de fora da conferência (já baixado).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pix_cobrancas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('txid', 35)->unique();
            $t->decimal('valor', 10, 2);
            $t->string('descricao', 140)->nullable();
            $t->text('copia_cola')->nullable();
            $t->string('situacao', 10)->default('criando');            // criando, ativa, paga, cancelada, expirada, falha
            $t->dateTime('expira_em')->nullable();
            $t->dateTime('pago_em')->nullable();
            $t->decimal('valor_pago', 10, 2)->nullable();
            $t->string('e2e_id', 40)->nullable();
            $t->string('pagador', 120)->nullable();
            $t->string('erro', 255)->nullable();
            $t->dateTime('consultado_em')->nullable();
            $t->foreignId('aparelho_id')->nullable()->constrained('aparelhos');
            $t->foreignId('caixa_id')->nullable()->constrained('caixas');
            $t->foreignId('pagamento_id')->nullable()->constrained('pagamentos');
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao']);
        });
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->string('pix_txid', 35)->nullable()->after('pdv_tipo');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropColumn('pix_txid');
        });
        Schema::dropIfExists('pix_cobrancas');
    }
};
