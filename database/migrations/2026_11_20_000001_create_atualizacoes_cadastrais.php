<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Atualização cadastral: link individual (válido 30 dias) que o cliente abre confirmando a placa;
// o que ele envia passa por aprovação. Aprovado, os campos alterados passam a valer pelo ERP
// (clientes.campos_locais) e o espelho do sistema atual não os sobrescreve mais.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atualizacoes_cadastrais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('cliente_id')->constrained('clientes');
            $t->string('token', 48)->unique();
            // aberta, respondida, aprovada, recusada, cancelada (expirada = aberta com expira_em no passado)
            $t->string('situacao', 12)->default('aberta');
            $t->dateTime('expira_em');
            $t->dateTime('enviado_em')->nullable();
            $t->string('canal', 12)->nullable();                 // whatsapp, cartao, link, cobranca
            $t->unsignedSmallInteger('tentativas')->default(0);  // placa errada
            $t->dateTime('bloqueado_ate')->nullable();
            $t->json('dados')->nullable();                       // o que o cliente enviou
            $t->json('antes')->nullable();                       // como estava, para a revisão
            $t->dateTime('respondido_em')->nullable();
            $t->dateTime('consentimento_em')->nullable();
            $t->string('ip', 45)->nullable();
            $t->foreignId('revisado_por')->nullable()->constrained('usuarios');
            $t->dateTime('revisado_em')->nullable();
            $t->string('motivo_recusa', 200)->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao']);
            $t->index('cliente_id');
        });

        Schema::table('clientes', function (Blueprint $t) {
            $t->json('campos_locais')->nullable()->after('regras_boleto'); // campos que valem pelo ERP (o espelho não sobrescreve)
        });
    }

    public function down(): void
    {
        Schema::table('clientes', fn (Blueprint $t) => $t->dropColumn('campos_locais'));
        Schema::dropIfExists('atualizacoes_cadastrais');
    }
};
