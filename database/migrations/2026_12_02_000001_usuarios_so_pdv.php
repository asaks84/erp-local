<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Usuário só do PDV (não acessa a gestão): sem login e sem senha, só o PIN. O PIN ele mesmo escolhe no PDV com um
// código de cadastro de 6 dígitos que o gerente gera na gestão (vale 24 horas; guardado só como hash).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->string('login', 40)->nullable()->change();
            $t->string('senha', 255)->nullable()->change();
            $t->boolean('acessa_gestao')->default(true)->after('senha');
            $t->string('pin_codigo_hash', 64)->nullable()->after('pin_definido_em');
            $t->dateTime('pin_codigo_ate')->nullable()->after('pin_codigo_hash');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropColumn(['acessa_gestao', 'pin_codigo_hash', 'pin_codigo_ate']);
        });
    }
};
