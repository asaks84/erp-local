<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NFS-e por serviço: cada ordem guarda a nota em que entrou (nula = sem nota). A nota pode sair antes do pagamento
// (clientes que fecham o mês) e um recebimento pode misturar serviços com e sem nota. Rejeitada ou cancelada, os
// serviços voltam a ficar sem nota.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->foreignId('nfse_id')->nullable()->after('cobranca_id')->constrained('nfses');
        });
    }

    public function down(): void
    {
        Schema::table('ordens_servico', function (Blueprint $t) {
            $t->dropConstrainedForeignId('nfse_id');
        });
    }
};
