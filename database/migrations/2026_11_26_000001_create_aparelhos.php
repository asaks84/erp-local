<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PDV (etapa 8.1): aparelhos pareados por código de uso único (cada um com o próprio token) e o PIN de
// 4 dígitos de cada pessoa. Ver docs/15-plano-pdv.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aparelhos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('unidade_id')->nullable()->constrained('unidades');
            $t->string('nome', 60);
            $t->string('token_hash', 64)->nullable()->unique();        // sha256 do token; o token só aparece uma vez
            $t->string('codigo_hash', 64)->nullable()->unique();       // código de pareamento (6 dígitos), de uso único
            $t->dateTime('codigo_expira_em')->nullable();
            $t->dateTime('pareado_em')->nullable();
            $t->dateTime('ultimo_acesso_em')->nullable();
            $t->string('versao_app', 20)->nullable();
            $t->unsignedTinyInteger('pin_erros')->default(0);           // PINs errados seguidos neste aparelho
            $t->dateTime('pin_travado_ate')->nullable();
            $t->boolean('ativo')->default(true);
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
        });
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('pin_hash', 64)->nullable()->after('senha');      // HMAC do PIN (empresa + PIN), único na empresa
            $t->dateTime('pin_definido_em')->nullable()->after('pin_hash');
            $t->unique(['empresa_id', 'pin_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropUnique(['empresa_id', 'pin_hash']);
            $t->dropColumn(['pin_hash', 'pin_definido_em']);
        });
        Schema::dropIfExists('aparelhos');
    }
};
