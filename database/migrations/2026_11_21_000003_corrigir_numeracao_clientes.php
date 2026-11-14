<?php

use App\Servicos\NumeracaoClientes;
use Illuminate\Database\Migrations\Migration;

// Corrige a colisão de códigos de cliente: exclui o cadastro duplicado (ficha do sistema atual aprovada
// também no ERP), tira do caminho o cliente criado no ERP que ocupa um código do sistema atual e devolve
// ao importado o código de lá. A numeração continua a sequência normal. Resumo no Registro de atividades.
return new class extends Migration
{
    public function up(): void
    {
        NumeracaoClientes::corrigir();
    }

    public function down(): void
    {
        // não desfaz: os códigos certos são os do sistema atual
    }
};
