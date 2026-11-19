<?php

namespace App\Http\Controllers;

use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\TipoVeiculo;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Patio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/** Operação › Pátio: estadias e lavagens numa lista só. Entrada e saída são do PDV; aqui, ver e corrigir. */
class PatioController extends Controller
{
    public function index(): Response
    {
        FormasPagamento::garantirPadrao();
        $linhas = Patio::agora();

        return Inertia::render('Patio/Index', [
            'linhas' => $linhas,
            'totais' => ['estadias' => $linhas->where('tipo', 'estadia')->count(), 'lavagens' => $linhas->where('tipo', 'lavagem')->count(),
                'estadias_valor' => round((float) $linhas->where('tipo', 'estadia')->sum('valor'), 2),
                'lavagens_valor' => round((float) $linhas->where('tipo', 'lavagem')->sum('valor') + (float) $linhas->where('tipo', 'lavagem')->sum('estadia'), 2)],
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    public function historico(Request $request): Response
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:40'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'],
            'tipo' => ['nullable', 'in:todos,estadia,lavagem'], 'situacao' => ['nullable', 'in:todas,finalizada,cancelada,patio'], 'page' => ['nullable', 'integer', 'min:1']]);
        $de = $f['de'] ?? now()->subDays(30)->toDateString();
        $ate = $f['ate'] ?? now()->toDateString();
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        $filtros = ['q' => trim((string) ($f['q'] ?? '')), 'de' => $de, 'ate' => $ate, 'tipo' => $f['tipo'] ?? 'todos', 'situacao' => $f['situacao'] ?? 'todas'];
        $linhas = Patio::historico($de, $ate, $filtros['tipo'], $filtros['situacao'], $filtros['q']);
        $pagina = (int) ($f['page'] ?? 1);
        $lista = new LengthAwarePaginator($linhas->forPage($pagina, 50)->values(), $linhas->count(), 50, $pagina,
            ['path' => $request->url(), 'query' => array_filter($filtros)]);

        return Inertia::render('Patio/Historico', ['linhas' => $lista, 'filtros' => $filtros, 'totais' => Patio::totais($linhas)]);
    }

    // ---------- correções (permissão patio_corrigir) ----------

    public function corrigirEstadia(Request $request, Estadia $estadia): RedirectResponse
    {
        $d = $request->validate(['placa' => ['nullable', 'string', 'max:10'], 'modelo' => ['nullable', 'string', 'max:40'], 'tipo_veiculo_id' => ['nullable', 'integer']]);
        Patio::corrigirEstadia($estadia, $d);

        return back()->with('sucesso', 'Estadia corrigida.');
    }

    public function cancelarEstadia(Request $request, Estadia $estadia): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Estadias::cancelar($estadia, $d['motivo']);

        return back()->with('sucesso', 'Estadia de '.$estadia->rotulo().' cancelada.');
    }

    public function encerrarEstadia(Request $request, Estadia $estadia): RedirectResponse
    {
        $d = $request->validate([
            'saida' => ['required', 'date_format:Y-m-d H:i'], 'motivo' => ['required', 'string', 'max:200'],
            'como' => ['required', 'in:sem_receber,conta'], 'conta_cliente_id' => ['nullable', 'integer', 'required_if:como,conta'],
        ], ['motivo.required' => 'Informe o motivo.', 'saida.required' => 'Informe a hora em que o carro saiu.', 'conta_cliente_id.required_if' => 'Escolha o cliente.']);
        $e = Patio::encerrarEstadia($estadia, $d);

        return back()->with('sucesso', 'Estadia de '.$e->rotulo().' encerrada · '.Estadia::DESTINOS[$e->destino].'.');
    }

    public function corrigirLavagem(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['placa' => ['nullable', 'string', 'max:10'], 'modelo' => ['nullable', 'string', 'max:40']]);
        Patio::corrigirLavagem($ordem, $d);

        return back()->with('sucesso', 'Lavagem corrigida.');
    }

    public function cancelarLavagem(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Patio::cancelarLavagem($ordem, $d['motivo']);

        return back()->with('sucesso', 'Lavagem cancelada.');
    }

    public function entregarLavagem(Request $request, OrdemServico $ordem): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']], ['motivo.required' => 'Informe o motivo.']);
        Patio::entregarLavagem($ordem, $d['motivo']);

        return back()->with('sucesso', 'Lavagem marcada como entregue. O que estava em aberto continua a receber.');
    }
}
