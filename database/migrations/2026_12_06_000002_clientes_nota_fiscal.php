<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cadastro do cliente: como sai a NFS-e dele (padrão da emissão, dá para mudar na hora): com os dados do tomador,
// com ISS retido, com retenção de tributos federais. Tudo desligado por padrão.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->boolean('nf_tomador')->default(false)->after('nao_recebe_avisos');
            $t->boolean('nf_iss_retido')->default(false)->after('nf_tomador');
            $t->boolean('nf_retem_federal')->default(false)->after('nf_iss_retido');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn(['nf_tomador', 'nf_iss_retido', 'nf_retem_federal']);
        });
    }
};
