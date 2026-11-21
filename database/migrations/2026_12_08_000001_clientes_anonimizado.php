<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// LGPD: cliente anonimizado (a pedido ou por prazo de guarda vencido). Os dados que o identificam são apagados e
// esta data marca que não tem volta: o cadastro não é editado nem reativado, e a importação não o sobrescreve.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dateTime('anonimizado_em')->nullable()->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $t) {
            $t->dropColumn('anonimizado_em');
        });
    }
};
