<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV 8.3: o recebimento de conta de cliente no balcão fica marcado ("conta"), para o fechamento do caixa
// separar o que é conta de cliente (mensalidades e serviços lançados na conta) do que foi feito no dia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->string('pdv_tipo', 10)->nullable()->after('caixa_id');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $t) {
            $t->dropColumn('pdv_tipo');
        });
    }
};
