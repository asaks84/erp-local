<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Convênio por serviço (docs/05-regras-de-negocio.md, Convênio): uma regra para a estadia e uma para cada
// serviço marcado (integral, parcial com teto em R$, ou limite de tempo na estadia) e o fechamento programado.
// Os campos antigos (modo, limite, desconto, vale_*) ficam: o sistema atual ainda manda neles durante o
// paralelo, e as regras de quem não foi editado no ERP são refeitas a partir deles na importação.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convenio_regras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('convenio_id')->constrained('convenios')->cascadeOnDelete();
            $t->foreignId('servico_id')->nullable()->constrained('servicos'); // vazio = estadia
            $t->string('modo', 10);                                            // integral, parcial, tempo
            $t->decimal('limite_valor', 10, 2)->nullable();                    // parcial: o convênio paga até
            $t->unsignedInteger('limite_min')->nullable();                     // tempo: o convênio paga até
            $t->dateTime('criado_em')->nullable();
            $t->dateTime('atualizado_em')->nullable();
            $t->index(['convenio_id', 'servico_id']);
        });
        Schema::table('convenios', function (Blueprint $t) {
            $t->boolean('regras_no_erp')->default(false)->after('ativo');      // editado no ERP: a importação não mexe nas regras
            $t->boolean('reconfigurar')->default(false)->after('regras_no_erp'); // estava em "desconto" (modo que saiu)
            $t->string('fechamento', 12)->default('mensalidade')->after('reconfigurar'); // mensalidade, dias, semana
            $t->unsignedSmallInteger('fechamento_dias')->nullable()->after('fechamento');
            $t->unsignedTinyInteger('fechamento_semana')->nullable()->after('fechamento_dias'); // 1 = segunda ... 7 = domingo
            $t->date('ultimo_fechamento')->nullable()->after('fechamento_semana');
        });

        // regras dos convênios que já existem, a partir do modo antigo
        $agora = now();
        foreach (DB::table('convenios')->get() as $c) {
            $servicos = DB::table('servicos')->where('empresa_id', $c->empresa_id)->where('ativo', true)->pluck('id');
            $regras = [];
            if ($c->modo === 'tudo') {
                if ($c->vale_estadia) {
                    $regras[] = [null, 'integral', null, null];
                }
                if ($c->vale_servicos) {
                    foreach ($servicos as $s) {
                        $regras[] = [$s, 'integral', null, null];
                    }
                }
            } elseif ($c->modo === 'tempo') {
                if ($c->vale_estadia && $c->limite_min) {
                    $regras[] = [null, 'tempo', null, (int) $c->limite_min];
                }
            } else {
                DB::table('convenios')->where('id', $c->id)->update(['reconfigurar' => true]);
            }
            foreach ($regras as [$servico, $modo, $valor, $min]) {
                DB::table('convenio_regras')->insert(['empresa_id' => $c->empresa_id, 'convenio_id' => $c->id, 'servico_id' => $servico, 'modo' => $modo,
                    'limite_valor' => $valor, 'limite_min' => $min, 'criado_em' => $agora, 'atualizado_em' => $agora]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('convenios', fn (Blueprint $t) => $t->dropColumn(['regras_no_erp', 'reconfigurar', 'fechamento', 'fechamento_dias', 'fechamento_semana', 'ultimo_fechamento']));
        Schema::dropIfExists('convenio_regras');
    }
};
