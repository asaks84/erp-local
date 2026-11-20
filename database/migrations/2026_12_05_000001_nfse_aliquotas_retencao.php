<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NFS-e: alíquotas do mês usadas em cada nota (do e-mail da contabilidade) e as escolhas cliente a cliente
// (ISS retido pelo tomador, dados do cliente na nota), lembradas para a próxima nota do mesmo cliente.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfses', function (Blueprint $t) {
            $t->foreignId('cliente_id')->nullable()->after('pagamento_id')->constrained('clientes');
            $t->char('competencia', 7)->nullable()->after('numero_dps');      // AAAA-MM
            $t->boolean('tomador_identificado')->default(true)->after('valor');
            $t->boolean('iss_retido')->default(false)->after('tomador_identificado');
            $t->decimal('aliquota_iss', 5, 2)->nullable()->after('iss_retido');
            $t->decimal('valor_iss', 10, 2)->nullable()->after('aliquota_iss');
            $t->decimal('p_tot_trib', 5, 2)->nullable()->after('valor_iss');  // valor aproximado dos tributos (%)
        });
    }

    public function down(): void
    {
        Schema::table('nfses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cliente_id');
            $t->dropColumn(['tomador_identificado', 'competencia', 'iss_retido', 'aliquota_iss', 'valor_iss', 'p_tot_trib']);
        });
    }
};
