<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mensagens do WhatsApp: cabeçalho (linha em negrito no topo, até 60 caracteres, até 1 informação) e
// rodapé (linha cinza no fim, até 60 caracteres, sem informação). Mídia no cabeçalho fica para o marketing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modelos_mensagem', function (Blueprint $t) {
            $t->string('cabecalho', 60)->nullable()->after('categoria');
            $t->string('rodape', 60)->nullable()->after('texto');
        });
    }

    public function down(): void
    {
        Schema::table('modelos_mensagem', fn (Blueprint $t) => $t->dropColumn(['cabecalho', 'rodape']));
    }
};
