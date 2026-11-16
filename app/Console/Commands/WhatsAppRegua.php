<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Servicos\WhatsApp\Regua;
use App\Support\EmpresaAtual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Régua de cobrança do WhatsApp: cria os avisos do dia e, com a API ligada e o envio automático, envia. */
class WhatsAppRegua extends Command
{
    protected $signature = 'whatsapp:regua';

    protected $description = 'Cria os avisos de cobrança do dia e envia os automáticos pelo WhatsApp';

    public function handle(EmpresaAtual $empresaAtual): int
    {
        foreach (Empresa::where('ativa', true)->get() as $e) {
            try {
                [$criados, $enviados, $falhas] = $empresaAtual->como($e->id, fn () => Regua::rotina());
                if ($criados || $enviados || $falhas) {
                    $this->info("{$e->nome}: {$criados} aviso(s) novo(s), {$enviados} enviado(s), {$falhas} com falha.");
                }
            } catch (\Throwable $ex) {
                $this->warn($e->nome.': '.$ex->getMessage());
                Log::warning('whatsapp:regua · '.$e->nome.' · '.$ex->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
