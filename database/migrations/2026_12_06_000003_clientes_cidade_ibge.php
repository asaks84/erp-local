<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Código IBGE da cidade do cliente (a NFS-e pede o código, o cadastro guarda o nome). Descoberto pelo CEP
// (ViaCEP) na emissão e guardado junto com o CEP usado: se o CEP mudar, consulta de novo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->char('cidade_ibge', 7)->nullable()->after('uf');
            $t->char('cidade_ibge_cep', 8)->nullable()->after('cidade_ibge');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn(['cidade_ibge', 'cidade_ibge_cep']);
        });
    }
};
