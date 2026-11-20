<?php

namespace App\Http\Controllers;

use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\PdvAcao;
use App\Models\TipoVeiculo;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Patio;
use App\Servicos\Pdv\SemInternet;
use App\Support\Formato;
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
            'tipos' => TipoVeiculo::where('ativo', true)->ordenados()->get(['id', 'nome']),
            'formas' => FormaPagamento::where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'tipo', 'parcelas_max']),
        ]);
    }

    /**
     * Pendências do PDV (8.7): o que foi feito no celular sem internet e não fechou na volta (ticket já finalizado na
     * gestão, diferença de cálculo, pessoa inativa...). O gerente confere, acerta na mão e marca como resolvida.
     */
    public function pendencias(): Response
    {
        $linha = fn (PdvAcao $a) => [
            'id' => $a->id, 'tipo' => PdvAcao::TIPOS[$a->tipo] ?? $a->tipo, 'situacao' => $a->situacao, 'mensagem' => $a->mensagem,
            'feita_em' => $a->feita_em?->format('d/m/Y H:i'), 'recebida_em' => $a->criado_em?->format('d/m/Y H:i'),
            'aparelho' => $a->aparelho?->nome, 'pessoa' => $a->usuario?->nome,
            'placa' => $a->estadia?->rotulo() ?? ($a->dados['rotulo'] ?? $a->dados['placa'] ?? null),
            'ticket' => $a->estadia?->numero, 'numero_impresso' => $a->dados['numero'] ?? null,
            'cobrado' => isset($a->dados['cobrado']) && (float) $a->dados['cobrado'] > 0 ? Formato::moeda($a->dados['cobrado']).(($a->dados['forma_nome'] ?? null) ? ' · '.$a->dados['forma_nome'] : '') : null,
            'observacao' => $a->observacao, 'resolvida_por' => $a->resolvidaPor?->nome, 'resolvida_em' => $a->resolvida_em?->format('d/m/Y H:i'),
        ];
        $com = ['aparelho:id,nome', 'usuario:id,nome', 'estadia', 'resolvidaPor:id,nome'];

        return Inertia::render('Patio/Pendencias', [
            'pendentes' => PdvAcao::with($com)->where('situacao', 'pendente')->orderBy('feita_em')->get()->map($linha),
            'resolvidas' => PdvAcao::with($com)->where('situacao', 'resolvida')->where('resolvida_em', '>=', now()->subDays(30))->orderByDesc('resolvida_em')->limit(50)->get()->map($linha),
        ]);
    }

    public function resolverPendencia(Request $request, PdvAcao $acao): RedirectResponse
    {
        $d = $request->validate(['observacao' => ['required', 'string', 'max:300']], ['observacao.required' => 'Conte o que foi feito para resolver.']);
        if ($acao->situacao !== 'pendente') {
            return back()->with('erro', 'Esta pendência já foi resolvida.');
        }
        SemInternet::resolver($acao, $d['observacao']);

        return back()->with('sucesso', 'Pendência resolvida.');
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
