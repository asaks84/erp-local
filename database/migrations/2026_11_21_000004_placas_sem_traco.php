<?php

use App\Support\Placa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Placas sempre só com letras e números (sem traço nem espaço) em todas as tabelas. Na identificação de
// veículo sem placa, só o que for placa brasileira (ex.: placa repetida na importação) entra no padrão.
return new class extends Migration
{
    public function up(): void
    {
        $colunas = ['veiculos' => ['placa'], 'veiculo_placas' => ['placa_anterior', 'placa_nova'], 'cadastros_pendentes' => ['placa'],
            'ordens_servico' => ['placa'], 'cobranca_itens' => ['placa'], 'estadias' => ['placa']];
        foreach ($colunas as $tabela => $cols) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }
            foreach ($cols as $col) {
                if (! Schema::hasColumn($tabela, $col)) {
                    continue;
                }
                DB::table($tabela)->whereNotNull($col)->orderBy('id')
                    ->select(['id', $col])->chunkById(1000, function ($linhas) use ($tabela, $col) {
                        foreach ($linhas as $l) {
                            if (Placa::normalizar($l->{$col}) === $l->{$col}) {
                                continue;
                            }
                            try {
                                DB::table($tabela)->where('id', $l->id)->update([$col => Placa::normalizar($l->{$col})]);
                            } catch (\Illuminate\Database\QueryException $e) {
                                // placa ativa repetida depois de limpar: fica como estava (aparece na conferência)
                                \Illuminate\Support\Facades\Log::warning("Placa {$tabela}#{$l->id} não normalizada: ".$e->getMessage());
                            }
                        }
                    });
            }
        }
        if (Schema::hasColumn('veiculos', 'identificacao')) {
            DB::table('veiculos')->whereNotNull('identificacao')->orderBy('id')->select(['id', 'identificacao'])
                ->chunkById(500, function ($linhas) {
                    foreach ($linhas as $l) {
                        $novo = Placa::identificacao($l->identificacao);
                        if ($novo !== $l->identificacao) {
                            DB::table('veiculos')->where('id', $l->id)->update(['identificacao' => $novo]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        // não volta o traço
    }
};
