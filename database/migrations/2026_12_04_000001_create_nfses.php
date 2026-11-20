<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NFS-e pelo Sistema Nacional (Emissor Nacional / API Sefin Nacional): uma nota por recebimento (pagamento).
// O certificado A1 fica em integracoes (tipo nfse), criptografado; a configuração (regime, códigos) em
// empresas.configuracoes.nfse.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('pagamento_id')->nullable()->constrained('pagamentos');
            $t->string('ambiente', 10);                       // producao | restrita (testes, sem valor fiscal)
            $t->string('serie', 5);
            $t->unsignedBigInteger('numero_dps');
            $t->string('id_dps', 45)->unique();
            $t->string('situacao', 12)->default('enviando');  // enviando, emitida, rejeitada, cancelada
            $t->decimal('valor', 10, 2);
            $t->string('tomador_documento', 14)->nullable();
            $t->string('tomador_nome', 150)->nullable();
            $t->string('descricao', 2000);
            $t->string('chave_acesso', 50)->nullable()->unique();
            $t->string('numero_nfse', 15)->nullable();
            $t->text('xml_dps')->nullable();                  // como foi enviada (assinada)
            $t->mediumText('xml_nfse')->nullable();           // a nota devolvida pelo Sistema Nacional
            $t->string('mensagem', 1000)->nullable();         // motivo da rejeição / do cancelamento
            $t->dateTime('emitida_em')->nullable();
            $t->dateTime('cancelada_em')->nullable();
            $t->foreignId('criado_por')->nullable()->constrained('usuarios');
            $t->timestamp('criado_em')->nullable();
            $t->timestamp('atualizado_em')->nullable();
            $t->index(['empresa_id', 'situacao']);
            $t->index(['empresa_id', 'pagamento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfses');
    }
};
