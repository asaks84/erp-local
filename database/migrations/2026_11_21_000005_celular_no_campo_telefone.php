<?php

use App\Support\Telefone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Cadastros com o celular no campo telefone (e o celular vazio): o número passa para o celular.
// A importação faz o mesmo a cada hora; aqui vale também para os clientes criados no ERP.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clientes')->whereNotNull('telefone')->where('telefone', '<>', '')
            ->where(fn ($q) => $q->whereNull('celular')->orWhere('celular', '')->orWhere('celular', '0'))
            ->orderBy('id')->select(['id', 'telefone', 'celular', 'campos_locais'])->chunkById(500, function ($linhas) {
                foreach ($linhas as $c) {
                    $locais = json_decode((string) $c->campos_locais, true) ?: [];
                    if (in_array('telefone', $locais, true) || in_array('celular', $locais, true)) {
                        continue;
                    }
                    [$tel, $cel] = Telefone::arrumar($c->telefone, $c->celular);
                    if ($cel !== $c->celular) {
                        DB::table('clientes')->where('id', $c->id)->update(['telefone' => $tel, 'celular' => $cel]);
                    }
                }
            });
    }

    public function down(): void {}
};
