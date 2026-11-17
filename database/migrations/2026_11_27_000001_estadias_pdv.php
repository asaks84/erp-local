<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV 8.2 (estadia): modelo do avulso, diária marcada na entrada (paga na entrada ou na saída), código do
// ticket (QR), quem retirou com ticket perdido e o aparelho de onde veio a entrada e o pagamento.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estadias', function (Blueprint $t) {
            $t->string('codigo', 24)->nullable()->unique()->after('numero');          // vai no QR do ticket
            $t->string('modelo', 40)->nullable()->after('identificacao');
            $t->foreignId('pacote_id')->nullable()->after('tarifa_id')->constrained('tarifa_pacotes')->nullOnDelete(); // diária marcada
            $t->decimal('valor_pacote', 10, 2)->nullable()->after('pacote_id');
            $t->unsignedBigInteger('ordem_entrada_id')->nullable()->after('ordem_convenio_id'); // diária paga na entrada
            $t->decimal('pago_entrada', 10, 2)->default(0)->after('ordem_entrada_id');
            $t->string('retirada_nome', 80)->nullable()->after('motivo_cancelamento');  // ticket perdido
            $t->string('retirada_documento', 30)->nullable()->after('retirada_nome');
            $t->foreignId('aparelho_id')->nullable()->after('criado_por')->constrained('aparelhos');
        });
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->foreignId('aparelho_id')->nullable()->after('origem')->constrained('aparelhos');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('aparelho_id');
        });
        Schema::table('estadias', function (Blueprint $t) {
            $t->dropConstrainedForeignId('aparelho_id');
            $t->dropConstrainedForeignId('pacote_id');
            $t->dropUnique(['codigo']);
            $t->dropColumn(['codigo', 'modelo', 'valor_pacote', 'ordem_entrada_id', 'pago_entrada', 'retirada_nome', 'retirada_documento']);
        });
    }
};
