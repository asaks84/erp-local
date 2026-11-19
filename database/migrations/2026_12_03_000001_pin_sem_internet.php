<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// PIN sem internet (8.7): segunda versão do PIN, própria para o celular conferir sem internet (PBKDF2 com um sal da
// empresa). Vai na cópia do sistema do aparelho. Nasce quando o PIN é cadastrado ou usado com internet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('pin_offline_hash', 64)->nullable()->after('pin_definido_em');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropColumn('pin_offline_hash');
        });
    }
};
