<?php

namespace App\Http\Controllers;

use App\Models\Anotacao;
use App\Servicos\Despesas;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Início: números do dia a dia, cada um só para quem tem a permissão. */
class InicioController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $u = $request->user();
        $aberto = null;
        if ($u->pode('contas_aberto')) {
            $linhas = ContaController::abertasPorCliente();
            $aberto = [
                'clientes' => $linhas->count(),
                'total' => round($linhas->sum('total'), 2),
                'vencido' => round($linhas->sum('vencido'), 2),
                'inadimplentes' => $linhas->where('vencido', '>', 0)->count(),
            ];
        }
        $recebido = null;
        if ($u->pode('faturamento')) {
            // mesma conta do Início do sistema atual: soma das contas baixadas no período
            // (crédito usado abate). O dinheiro por forma de pagamento fica em Recebimentos.
            $ini = now()->startOfMonth();
            $mes = self::baixado($ini->toDateString(), now()->toDateString());
            $recebido = [
                'mes' => now()->format('Y-m'),
                'total' => $mes['total'],
                'qtd' => $mes['contas'],
                'hoje' => self::baixado(now()->toDateString(), now()->toDateString())['total'],
                'por_categoria' => RelatorioController::porCategoria($ini->toDateString(), now()->toDateString()),
            ];
        }

        return Inertia::render('Inicio', ['aberto' => $aberto, 'recebido' => $recebido, 'anotacoes' => Anotacao::doUsuario((int) $request->user()->id),
            'conferencia' => ConferenciaController::resumo(),
            'pagar' => $u->pode('contas_pagar') ? Despesas::resumo() : null,
            // certificado da integração do Inter vencendo (boleto, baixa automática e extrato param juntos)
            'inter_vence' => $u->pode('configuracoes') ? self::interVence() : null]);
    }

    private static function interVence(): ?array
    {
        $i = \App\Models\Integracao::inter();
        if (! $i || ! $i->ativo || ! $i->certificado_validade) {
            return null;
        }
        $dias = (int) today()->diffInDays($i->certificado_validade, false);

        return $dias <= 30 ? ['data' => $i->certificado_validade->format('Y-m-d'), 'dias' => $dias] : null;
    }

    /** total baixado (soma dos itens dos pagamentos confirmados) e quantas contas, sem contar créditos */
    public static function baixado(string $de, string $ate): array
    {
        $l = \Illuminate\Support\Facades\DB::table('pagamento_itens as i')
            ->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')
            ->join('ordens_servico as o', 'o.id', '=', 'i.ordem_id')
            ->where('p.empresa_id', app(\App\Support\EmpresaAtual::class)->id())
            ->where('p.situacao', 'confirmado')
            ->whereBetween('p.recebido_em', [$de.' 00:00:00', $ate.' 23:59:59'])
            ->selectRaw("COALESCE(SUM(i.valor), 0) AS total, SUM(o.tipo <> 'credito') AS contas")->first();

        return ['total' => round((float) $l->total, 2), 'contas' => (int) $l->contas];
    }
}
