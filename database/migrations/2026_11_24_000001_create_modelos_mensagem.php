<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mensagens do WhatsApp editáveis: cada mensagem (grupo) tem versões. Rascunho fica só no ERP; enviada para
// a Meta, a versão fica travada (situação da Meta: PENDING, APPROVED, REJECTED...). Editar cria a versão
// seguinte; a aprovada continua valendo pela API até a nova ser aprovada.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modelos_mensagem', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->unsignedBigInteger('grupo')->nullable();     // id da 1ª versão
            $t->unsignedSmallInteger('versao')->default(1);
            $t->string('rotulo', 80);
            $t->string('nome', 100);                          // nome na Meta (minúsculas e _)
            $t->string('finalidade', 12)->default('cobranca'); // cobranca | propaganda
            $t->string('categoria', 12)->default('UTILITY');  // UTILITY | MARKETING (a Meta pode mudar)
            $t->text('texto');                                // com {nome}, {valor}...
            $t->boolean('botao')->default(true);              // botão "Ver cobrança"
            $t->string('situacao', 12)->default('rascunho');  // rascunho, verificar, PENDING, APPROVED, REJECTED, PAUSED, DISABLED
            $t->string('motivo', 255)->nullable();            // motivo da recusa
            $t->string('meta_id', 40)->nullable();
            $t->dateTime('enviado_em')->nullable();
            $t->foreignId('enviado_por')->nullable()->constrained('usuarios');
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->unique(['empresa_id', 'nome']);
            $t->index(['empresa_id', 'grupo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modelos_mensagem');
    }
};
