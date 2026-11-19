<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV 8.7 (modo sem internet): cada ação feita no celular sem internet sobe depois com um código único (uuid).
// O código impede lançar duas vezes; o que não fechar na volta fica como pendência para o gerente resolver.
// O número impresso no ticket sem internet é provisório: se o definitivo for outro, o impresso fica guardado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdv_acoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('aparelho_id')->nullable()->constrained('aparelhos');
            $t->uuid('uuid')->unique();
            $t->string('tipo', 30);                                    // estadia_entrada, estadia_saida
            $t->json('dados')->nullable();
            $t->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $t->dateTime('feita_em')->nullable();                      // hora do celular
            $t->boolean('sem_internet')->default(false);               // veio pela fila (false: registrada na hora, só o código)
            $t->string('situacao', 12)->default('ok');                 // ok, pendente, resolvida
            $t->string('mensagem', 500)->nullable();
            $t->foreignId('estadia_id')->nullable()->constrained('estadias');
            $t->string('observacao', 300)->nullable();
            $t->foreignId('resolvida_por')->nullable()->constrained('usuarios');
            $t->dateTime('resolvida_em')->nullable();
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao']);
        });
        Schema::table('estadias', function (Blueprint $t) {
            $t->unsignedInteger('numero_impresso')->nullable()->after('numero');
        });
    }

    public function down(): void
    {
        Schema::table('estadias', function (Blueprint $t) {
            $t->dropColumn('numero_impresso');
        });
        Schema::dropIfExists('pdv_acoes');
    }
};
