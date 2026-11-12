<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Conferências do paralelo: cada importação automática do sistema atual seguida da comparação dos números.
// É a prova do portão da etapa 5 ("semanas sem diferença"). Da instalação, não de uma empresa.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conferencias', function (Blueprint $t) {
            $t->id();
            $t->dateTime('executada_em');
            $t->string('situacao', 10);                 // ok, diferente, erro
            $t->unsignedSmallInteger('diferencas')->default(0);
            $t->json('linhas')->nullable();              // [item, sistema atual, ERP, situação]
            $t->text('mensagem')->nullable();            // erro da importação, se houver
            $t->unsignedInteger('duracao_seg')->nullable();
            $t->index('executada_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conferencias');
    }
};
