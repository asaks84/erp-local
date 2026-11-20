<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 1. Dados de veículo já gravados passam para maiúsculas (daqui em diante, os modelos gravam assim).
// 2. Tipos de veículo ganham ordem 1, 2, 3… por empresa, na ordem em que já apareciam (ordem, nome),
//    para a tela de tipos poder subir e descer cada um.
return new class extends Migration
{
    public function up(): void
    {
        $colunas = [
            'veiculos' => ['marca', 'modelo', 'cor', 'identificacao'],
            'estadias' => ['modelo', 'identificacao'],
            'ordens_servico' => ['veiculo_descricao'],
            'cadastros_pendentes' => ['marca', 'modelo', 'cor'],
        ];
        foreach ($colunas as $tabela => $cols) {
            foreach ($cols as $c) {
                DB::table($tabela)->whereNotNull($c)->update([$c => DB::raw("UPPER(TRIM($c))")]);
            }
        }

        foreach (DB::table('tipos_veiculo')->distinct()->pluck('empresa_id') as $empresa) {
            $ids = DB::table('tipos_veiculo')->where('empresa_id', $empresa)->orderBy('ordem')->orderBy('nome')->pluck('id');
            foreach ($ids as $i => $id) {
                DB::table('tipos_veiculo')->where('id', $id)->update(['ordem' => $i + 1]);
            }
        }
    }

    public function down(): void
    {
        // maiúsculas não voltam (não há como saber como estava); a ordem continua valendo
    }
};
