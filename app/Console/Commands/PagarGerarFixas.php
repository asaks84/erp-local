<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Servicos\Despesas;
use App\Support\EmpresaAtual;
use Illuminate\Console\Command;

/** Gera as contas a pagar das contas fixas (até o mês seguinte), em todas as empresas. */
class PagarGerarFixas extends Command
{
    protected $signature = 'pagar:gerar-fixas';

    protected $description = 'Gera as contas a pagar das contas fixas';

    public function handle(EmpresaAtual $empresaAtual): int
    {
        $total = 0;
        foreach (Empresa::all() as $e) {
            $total += $empresaAtual->como($e->id, fn () => Despesas::gerarRecorrentes());
        }
        $this->info("{$total} conta(s) gerada(s).");

        return self::SUCCESS;
    }
}
