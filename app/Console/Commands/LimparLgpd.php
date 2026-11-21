<?php

namespace App\Console\Commands;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Servicos\DadosPessoais;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LGPD: prazos de guarda, todo dia de madrugada (depois do backup). Só o que a lei obriga:
 * 1. fila de espera: cadastro que não virou cliente é apagado depois de erp.lgpd_fila_dias (120);
 * 2. ex-cliente: inativo e sem nenhum movimento há erp.lgpd_ex_cliente_anos (5) é anonimizado, com os mesmos
 *    bloqueios do botão (conta ou crédito em aberto, mensalidade etc.). Valores e notas fiscais ficam.
 * Durante o paralelo, o que veio do sistema atual fica de fora (a importação traria de volta).
 */
class LimparLgpd extends Command
{
    protected $signature = 'lgpd:limpar {--simular : só mostra o que faria}';

    protected $description = 'Aplica os prazos de guarda da LGPD (fila de espera e ex-clientes)';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        foreach (Empresa::where('ativa', true)->pluck('id') as $empresa) {
            app(EmpresaAtual::class)->como($empresa, function () use ($simular) {
                $fila = $this->fila($simular);
                [$anonimizados, $bloqueados] = $this->exClientes($simular);
                $resumo = "fila de espera: {$fila} apagado(s); ex-clientes: {$anonimizados} anonimizado(s)".($bloqueados ? ", {$bloqueados} com bloqueio" : '');
                $this->info(($simular ? '[simulação] ' : '').$resumo);
                if (! $simular && ($fila || $anonimizados)) {
                    Atividades::registrar('lgpd_limpeza', 'prazos de guarda (LGPD): '.$resumo);
                }
            });
        }

        return self::SUCCESS;
    }

    private function fila(bool $simular): int
    {
        $q = CadastroPendente::where('recebido_em', '<', now()->subDays((int) config('erp.lgpd_fila_dias')));
        if (config('erp.espelho_ativo')) {
            $q->whereNull('legado_id');
        }

        return $simular ? $q->count() : $q->delete();
    }

    /** inativos cujo último movimento (serviço, pagamento, estadia, mensalidade ou alteração do cadastro) passou do prazo */
    private function exClientes(bool $simular): array
    {
        $limite = now()->subYears((int) config('erp.lgpd_ex_cliente_anos'))->toDateTimeString();
        $nunca = "'1900-01-01'";
        $ultimo = "GREATEST(COALESCE(clientes.atualizado_em, {$nunca}), COALESCE(clientes.criado_em, {$nunca}),
            COALESCE((SELECT MAX(data) FROM ordens_servico o WHERE o.cliente_id = clientes.id), {$nunca}),
            COALESCE((SELECT MAX(recebido_em) FROM pagamentos p WHERE p.cliente_id = clientes.id), {$nunca}),
            COALESCE((SELECT MAX(COALESCE(saida, entrada)) FROM estadias e WHERE e.cliente_id = clientes.id), {$nunca}),
            COALESCE((SELECT MAX(COALESCE(fim, inicio)) FROM mensalidades_contratos m WHERE m.cliente_id = clientes.id), {$nunca}))";
        $candidatos = Cliente::where('ativo', false)->whereNull('anonimizado_em')->whereRaw("{$ultimo} < ?", [$limite])->orderBy('id')->get();
        $feitos = 0;
        $bloqueados = 0;
        foreach ($candidatos as $c) {
            if (DadosPessoais::bloqueios($c)) {
                $bloqueados++;

                continue;
            }
            if (! $simular) {
                DB::transaction(fn () => DadosPessoais::anonimizar($c, 'prazo'));
            }
            $feitos++;
        }

        return [$feitos, $bloqueados];
    }
}
