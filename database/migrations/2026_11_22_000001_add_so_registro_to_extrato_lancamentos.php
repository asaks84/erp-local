<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Entrada do extrato cuja conta já tinha sido baixada no sistema atual: fica só registrada (sem pagamento
// novo) e guarda a forma, que a importação reaplica ao pagamento vindo de lá.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extrato_lancamentos', function (Blueprint $t) {
            $t->boolean('so_registro')->default(false)->after('total_contas');
            $t->foreignId('forma_pagamento_id')->nullable()->after('so_registro')->constrained('formas_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('extrato_lancamentos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('forma_pagamento_id');
            $t->dropColumn('so_registro');
        });
    }
};
