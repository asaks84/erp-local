<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Núcleo do sistema: empresas, unidades (pátios), níveis de acesso, usuários e registro de atividades.
// Ver docs/03-modelo-de-dados.md.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80);
            $table->string('razao_social', 120)->nullable();
            $table->string('documento', 18)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('logo', 255)->nullable();
            $table->json('configuracoes')->nullable();
            $table->boolean('ativa')->default(true);
            $table->timestamp('criado_em')->nullable();
            $table->timestamp('atualizado_em')->nullable();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome', 60);
            $table->string('endereco', 160)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->boolean('ativa')->default(true);
            $table->timestamp('criado_em')->nullable();
            $table->timestamp('atualizado_em')->nullable();
            $table->unique(['empresa_id', 'nome']);
        });

        Schema::create('niveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nome', 40);
            $table->json('permissoes')->nullable();      // lista de chaves de config/permissoes.php
            $table->boolean('fixo')->default(false);     // acesso total; não pode ser limitado nem excluído
            $table->unsignedInteger('legado_id')->nullable();
            $table->timestamp('criado_em')->nullable();
            $table->timestamp('atualizado_em')->nullable();
            $table->unique(['empresa_id', 'nome']);
            $table->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('nivel_id')->constrained('niveis');
            $table->foreignId('unidade_id')->nullable()->constrained('unidades'); // vazio = todas
            $table->string('nome', 80);
            $table->string('login', 40)->unique();        // único no sistema todo (entra sem escolher empresa)
            $table->string('senha', 255);
            $table->boolean('operador_caixa')->default(false);
            $table->boolean('ativo')->default(true);
            $table->rememberToken();
            $table->timestamp('ultimo_acesso_em')->nullable();
            $table->unsignedInteger('legado_id')->nullable();
            $table->timestamp('criado_em')->nullable();
            $table->timestamp('atualizado_em')->nullable();
            $table->unique(['empresa_id', 'legado_id']);
        });

        Schema::create('log_atividades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios');
            $table->string('usuario_nome', 80)->nullable(); // guardado como era na hora (o cadastro pode mudar)
            $table->string('acao', 40);
            $table->string('detalhe', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('criado_em')->useCurrent();
            $table->unsignedInteger('legado_id')->nullable();
            $table->index(['empresa_id', 'criado_em']);
            $table->unique(['empresa_id', 'legado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_atividades');
        Schema::dropIfExists('usuarios');
        Schema::dropIfExists('niveis');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('empresas');
    }
};
