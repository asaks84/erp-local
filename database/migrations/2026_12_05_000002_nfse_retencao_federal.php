<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NFS-e: retenção federal usada em cada nota (tipo de retenção do PIS/COFINS/CSLL, IRRF e contribuição
// previdenciária, da configuração da empresa) e o valor retido.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfses', function (Blueprint $t) {
            $t->char('ret_federal', 1)->default('0')->after('p_tot_trib');    // tpRetPisCofins
            $t->boolean('ret_irrf')->default(false)->after('ret_federal');
            $t->boolean('ret_cp')->default(false)->after('ret_irrf');
            $t->decimal('valor_ret_federal', 10, 2)->nullable()->after('ret_cp');
        });
    }

    public function down(): void
    {
        Schema::table('nfses', function (Blueprint $t) {
            $t->dropColumn(['ret_federal', 'ret_irrf', 'ret_cp', 'valor_ret_federal']);
        });
    }
};
