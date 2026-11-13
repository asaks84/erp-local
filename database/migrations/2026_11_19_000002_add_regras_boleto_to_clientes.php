<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Regras particulares do boleto por cliente (multa, juros, desconto, baixa): só o que for diferente
// do padrão da empresa fica guardado; o resto segue Configurações › Banco Inter.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->json('regras_boleto')->nullable()->after('nao_recebe_avisos');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn('regras_boleto');
        });
    }
};
