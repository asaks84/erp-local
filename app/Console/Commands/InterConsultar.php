<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Servicos\Inter\Boletos;
use App\Support\EmpresaAtual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Consulta periódica dos boletos no Banco Inter (a hospedagem não aceita webhook com certificado):
 * boleto pago dá baixa; boleto de cobrança já resolvida por outro meio é cancelado.
 */
class InterConsultar extends Command
{
    protected $signature = 'inter:consultar {--todos : consulta também os que foram consultados há pouco}';

    protected $description = 'Consulta os boletos em aberto no Banco Inter e dá baixa nos pagos';

    public function handle(EmpresaAtual $empresaAtual): int
    {
        foreach (Empresa::where('ativa', true)->get() as $e) {
            [$consultados, $pagos, $cancelados, $erros] = $empresaAtual->como($e->id, fn () => Boletos::rotina(null, (bool) $this->option('todos')));
            if ($consultados || $cancelados || $erros) {
                $this->info("{$e->nome}: {$consultados} consultado(s), {$pagos} pago(s), {$cancelados} cancelado(s).");
            }
            foreach ($erros as $msg) {
                $this->warn($msg);
                Log::warning('inter:consultar · '.$e->nome.' · '.$msg);
            }
        }

        return self::SUCCESS;
    }
}
