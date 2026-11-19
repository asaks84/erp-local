<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pátio unificado na gestão: estadia encerrada sem receber (carro saiu e ninguém deu baixa) guarda o motivo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estadias', function (Blueprint $t) {
            $t->string('observacao', 200)->nullable()->after('motivo_cancelamento');
        });
    }

    public function down(): void
    {
        Schema::table('estadias', function (Blueprint $t) {
            $t->dropColumn('observacao');
        });
    }
};
