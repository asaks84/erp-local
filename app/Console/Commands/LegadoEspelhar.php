<?php

namespace App\Console\Commands;

use App\Models\Conferencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Paralelo (etapa 5): importa do sistema atual e compara os números. Roda sozinho pelo agendador
 * (routes/console.php); cada execução fica em Sistema › Conferência com o sistema atual.
 */
class LegadoEspelhar extends Command
{
    protected $signature = 'legado:espelhar';

    protected $description = 'Importa do sistema atual e compara os números (paralelo)';

    public function handle(): int
    {
        $inicio = microtime(true);
        try {
            $r = Artisan::call('legado:importar', ['--empresa' => config('erp.empresa_nome'), '--no-interaction' => true]);
            if ($r !== self::SUCCESS) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'A importação terminou com erro.');
            }
            Artisan::call('legado:comparar', ['--no-interaction' => true]);
        } catch (Throwable $e) {
            Conferencia::create(['executada_em' => now(), 'situacao' => 'erro', 'mensagem' => mb_substr($e->getMessage(), 0, 2000),
                'duracao_seg' => (int) round(microtime(true) - $inicio)]);
            report($e);
            $this->error('Erro: '.$e->getMessage());

            return self::FAILURE;
        }
        $c = Conferencia::latest('id')->first();
        $c?->update(['duracao_seg' => (int) round(microtime(true) - $inicio)]);
        $this->info('Conferência: '.($c ? Conferencia::SITUACOES[$c->situacao] : '?'));

        return $c?->situacao === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
