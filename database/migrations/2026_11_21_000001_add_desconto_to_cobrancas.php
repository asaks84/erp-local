<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Desconto negociado na cobrança unitária: o total das ordens não muda (relatórios); o boleto sai
// com o total menos o desconto e, pago, o desconto vira desconto na baixa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $t) {
            $t->decimal('desconto', 10, 2)->default(0)->after('total');
            $t->string('desconto_motivo', 120)->nullable()->after('desconto');
            $t->foreignId('desconto_por')->nullable()->after('desconto_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('cobrancas', function (Blueprint $t) {
            $t->dropColumn(['desconto', 'desconto_motivo', 'desconto_por']);
        });
    }
};
