<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Veiculo;
use App\Servicos\Contratos;
use App\Support\Atividades;
use App\Support\Documento;
use App\Support\EmpresaAtual;
use App\Support\Placa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'situacao' => ['nullable', 'in:ativos,inativos,todos']]);
        $situacao = $f['situacao'] ?? 'ativos';

        $clientes = self::busca($f['q'] ?? null)
            ->when($situacao !== 'todos', fn ($q) => $q->where('clientes.ativo', $situacao === 'ativos'))
            ->with(['veiculos' => fn ($q) => $q->where('ativo', true)->select('id', 'cliente_id', 'placa', 'identificacao')])
            ->withCount(['contratos as mensalidades' => fn ($q) => $q->ativos()])
            ->orderBy('nome')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Cliente $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'documento' => Documento::formatar($c->documento),
                'celular' => $c->celular ?: $c->telefone,
                'veiculos' => $c->veiculos->map->rotulo()->values(),
                'mensalista' => $c->mensalidades > 0,
                'ativo' => $c->ativo,
            ]);

        return Inertia::render('Clientes/Index', ['clientes' => $clientes, 'filtros' => ['q' => $f['q'] ?? '', 'situacao' => $situacao]]);
    }

    /** nome, código, CPF/CNPJ ou placa */
    public static function busca(?string $texto)
    {
        $q = Cliente::query()->select('clientes.*');
        $texto = trim((string) $texto);
        if ($texto === '') {
            return $q;
        }
        $placa = Placa::normalizar($texto);
        $doc = Documento::limpar($texto);

        return $q->where(function ($w) use ($texto, $placa, $doc) {
            $w->where('clientes.nome', 'like', '%'.$texto.'%');
            if (ctype_digit($texto)) {
                $w->orWhere('clientes.id', (int) $texto);
            }
            if ($doc && strlen($doc) >= 5) {
                $w->orWhere('clientes.documento', 'like', $doc.'%');
            }
            if ($placa && strlen($placa) >= 3) {
                $w->orWhereHas('veiculos', fn ($v) => $v->where('placa', 'like', '%'.$placa.'%')->orWhere('identificacao', 'like', '%'.$texto.'%'));
            }
        });
    }

    /** busca do topo e dos campos de cliente (até 8 resultados) */
    public function buscar(Request $request): JsonResponse
    {
        $texto = mb_substr(trim((string) $request->query('q')), 0, 80);
        if (mb_strlen($texto) < 2 && ! ctype_digit($texto)) {
            return response()->json([]);
        }
        $lista = self::busca($texto)->where('clientes.ativo', true)
            ->with(['veiculos' => fn ($q) => $q->where('ativo', true)])
            ->orderBy('nome')->limit(8)->get()
            ->map(fn (Cliente $c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'detalhe' => trim('Cód. '.$c->id.' · '.$c->veiculos->map->rotulo()->implode(', '), ' ·'),
            ]);

        return response()->json($lista);
    }

    public function show(Cliente $cliente): Response
    {
        $cliente->load([
            'veiculos' => fn ($q) => $q->orderByDesc('ativo')->orderBy('id'),
            'veiculos.tipo:id,nome',
            'veiculos.contratos' => fn ($q) => $q->orderByDesc('inicio'),
            'veiculos.contratos.pausas' => fn ($q) => $q->orderBy('inicio'),
            'convenio',
            'pagadores',
        ]);
        $hoje = now()->startOfDay();

        return Inertia::render('Clientes/Show', [
            'cliente' => [
                ...$cliente->only(['id', 'nome', 'email', 'telefone', 'celular', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'dia_vencimento', 'observacoes', 'ativo', 'nao_recebe_avisos']),
                'documento' => Documento::formatar($cliente->documento),
                'cliente_desde' => $cliente->cliente_desde?->format('Y-m-d'),
                'convenio' => $cliente->convenio?->ativo ? $cliente->convenio->resumo() : null,
                'regras_boleto' => $cliente->regras_boleto,
            ],
            'regras_boleto_empresa' => \App\Servicos\Inter\Boletos::regras(),
            // emitir cobrança com boleto: a primeira mensalidade (ou proporcional) sai sem as regras
            'boleto_regras_sugerido' => ! \App\Servicos\Inter\Boletos::temPrimeiraMensalidade(\App\Servicos\Baixa::abertas($cliente)),
            'veiculos' => $cliente->veiculos->map(fn (Veiculo $v) => [
                'id' => $v->id,
                'rotulo' => $v->rotulo(),
                'tem_placa' => (bool) $v->placa,
                'descricao' => trim(implode(' ', array_filter([$v->marca, $v->modelo, $v->cor]))),
                'tipo' => $v->tipo?->nome,
                'ativo' => $v->ativo,
                'contratos' => $v->contratos->map(fn ($c) => [
                    'id' => $c->id,
                    'periodo' => $c->periodo,
                    'valor' => (float) $c->valor,
                    'inicio' => $c->inicio->format('Y-m-d'),
                    'fim' => $c->fim?->format('Y-m-d'),
                    'motivo_fim' => $c->motivo_fim,
                    'situacao' => $c->inicio->gt($hoje) ? 'futuro' : ($c->fim && $c->fim->lt($hoje) ? 'encerrado' : 'vigente'),
                    'pausas' => $c->pausas->map(fn ($p) => [
                        'id' => $p->id, 'inicio' => $p->inicio->format('Y-m-d'), 'fim' => $p->fim->format('Y-m-d'),
                        'taxa_tipo' => $p->taxa_tipo, 'taxa_valor' => (float) $p->taxa_valor, 'motivo' => $p->motivo,
                        'pode_remover' => $p->inicio->gte(now()->startOfMonth()),
                    ]),
                ]),
            ]),
            'pagadores' => $cliente->pagadores->map->only(['id', 'nome', 'documento', 'conta']),
            // dinheiro (etapa 3)
            'abertas' => \App\Servicos\Baixa::abertas($cliente)->map(fn ($o) => OrdemController::linha($o)),
            'total_aberto' => \App\Servicos\Baixa::totalAberto($cliente),
            'cobrancas' => \App\Models\Cobranca::where('cliente_id', $cliente->id)->orderByDesc('numero')->limit(6)->get()->map(fn ($c) => CobrancaController::resumo($c)),
            'pagamentos' => \App\Models\Pagamento::with('forma:id,nome')->where('cliente_id', $cliente->id)->orderByDesc('recebido_em')->limit(8)->get()->map(fn ($p) => [
                'id' => $p->id, 'recebido_em' => $p->recebido_em->format('Y-m-d H:i'), 'valor' => (float) $p->valor, 'forma' => $p->forma?->nome,
                'situacao' => $p->situacao, 'origem' => $p->origem, 'motivo_estorno' => $p->motivo_estorno,
            ]),
            'mes_atual' => now()->format('Y-m'),
            'periodos' => Empresa::atual()->config('periodos'),
            'unidades' => \App\Models\Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
            'pausa_padrao' => ['tipo' => Empresa::atual()->config('pausa_taxa_tipo'), 'valor' => Empresa::atual()->config('pausa_taxa_valor')],
        ]);
    }

    /**
     * Extrato da conta: ordens, pagamentos, cancelamentos e estornos em ordem de data, com o saldo
     * (quanto o cliente deve) linha a linha. Montado das tabelas existentes.
     */
    public function extrato(Request $request, Cliente $cliente): Response
    {
        $f = $request->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $de = $f['de'] ?? now()->subMonths(6)->startOfMonth()->toDateString();
        $ate = $f['ate'] ?? now()->toDateString();

        $mov = collect();
        $ordens = \App\Models\OrdemServico::where('cliente_id', $cliente->id)->get();
        $ids = $ordens->pluck('id')->all();
        // ordens que um pagamento confirmado baixou (as demais pagas foram baixadas sem pagamento registrado)
        $cobertas = \App\Models\PagamentoItem::whereIn('ordem_id', $ids ?: [0])
            ->whereHas('pagamento', fn ($q) => $q->where('situacao', 'confirmado'))->pluck('ordem_id')->flip();
        foreach ($ordens as $o) {
            $mov->push(['quando' => $o->data->format('Y-m-d').' 00:00:00', 'ordem' => 1, 'descricao' => '#'.$o->numero.' '.$o->descricao.($o->competencia ? ' '.\Carbon\Carbon::parse($o->competencia.'-01')->format('m/Y') : '').($o->placa ? ' · '.\App\Support\Placa::formatar($o->placa) : ''),
                'tipo' => $o->tipo, 'valor' => $o->valorComSinal()]);
            if ($o->situacao === 'cancelada' && $o->cancelada_em) {
                $mov->push(['quando' => $o->cancelada_em->format('Y-m-d H:i:s'), 'ordem' => 2, 'descricao' => 'Cancelada #'.$o->numero.' ('.$o->motivo_cancelamento.')', 'tipo' => 'cancelamento', 'valor' => -$o->valorComSinal()]);
            }
            if ($o->situacao === 'paga' && ! isset($cobertas[$o->id])) {
                // ex.: data de pagamento "0000-00-00" no sistema atual: paga, mas sem data nem pagamento
                $quando = $o->pago_em?->format('Y-m-d H:i:s') ?? ($o->vencimento ?? $o->data)->format('Y-m-d').' 00:00:01';
                $mov->push(['quando' => $quando, 'ordem' => 3, 'descricao' => 'Baixada no sistema atual sem data de pagamento #'.$o->numero, 'tipo' => 'pagamento', 'valor' => -$o->valorComSinal()]);
            }
        }
        $pagamentos = \App\Models\Pagamento::with(['forma:id,nome', 'itens.ordem:id,numero'])
            ->where(fn ($q) => $q->where('cliente_id', $cliente->id)->orWhereHas('itens', fn ($i) => $i->whereIn('ordem_id', $ids ?: [0])))->get();
        foreach ($pagamentos as $p) {
            $itens = $p->itens->filter(fn ($i) => in_array($i->ordem_id, $ids));
            $baixado = round((float) $itens->sum('valor'), 2);
            $dif = round((float) $p->valor - round((float) $p->itens->sum('valor'), 2), 2);
            $obs = $dif < 0 ? ' · desconto/débito de '.\App\Support\Formato::moeda(-$dif) : ($dif > 0 ? ' · '.\App\Support\Formato::moeda($dif).' a mais' : '');
            $mov->push(['quando' => $p->recebido_em->format('Y-m-d H:i:s'), 'ordem' => 3, 'descricao' => 'Pagamento '.\App\Support\Formato::moeda($p->valor).' ('.($p->forma?->nome ?? 'forma não informada').') · ordens '.$itens->map(fn ($i) => '#'.$i->ordem->numero)->implode(', ').$obs,
                'tipo' => 'pagamento', 'valor' => -$baixado]);
            if ($p->situacao === 'estornado' && $p->estornado_em) {
                $mov->push(['quando' => $p->estornado_em->format('Y-m-d H:i:s'), 'ordem' => 4, 'descricao' => 'Estorno do pagamento ('.$p->motivo_estorno.')', 'tipo' => 'estorno', 'valor' => $baixado]);
            }
        }
        $mov = $mov->sortBy([['quando', 'asc'], ['ordem', 'asc']])->values();
        $saldo = 0.0;
        $anterior = 0.0;
        $linhas = [];
        foreach ($mov as $m) {
            $saldo = round($saldo + $m['valor'], 2);
            $dia = substr($m['quando'], 0, 10);
            if ($dia < $de) {
                $anterior = $saldo;

                continue;
            }
            if ($dia > $ate) {
                break;
            }
            $linhas[] = ['data' => $dia] + $m + ['saldo' => $saldo];
        }

        return Inertia::render('Clientes/Extrato', [
            'cliente' => $cliente->only(['id', 'nome']) + ['documento' => Documento::formatar($cliente->documento)],
            'filtros' => ['de' => $de, 'ate' => $ate],
            'anterior' => $anterior,
            'linhas' => $linhas,
            'saldo_final' => $linhas ? end($linhas)['saldo'] : $anterior,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clientes/Form', [
            'cliente' => null,
            'dia_padrao' => Empresa::atual()->config('dia_vencimento_padrao'),
        ]);
    }

    public function edit(Cliente $cliente): Response
    {
        return Inertia::render('Clientes/Form', [
            'cliente' => [
                ...$cliente->only(['id', 'nome', 'email', 'telefone', 'celular', 'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf', 'dia_vencimento', 'observacoes', 'nao_recebe_avisos']),
                'documento' => Documento::formatar($cliente->documento),
                'cliente_desde' => $cliente->cliente_desde?->format('Y-m-d'),
            ],
            'dia_padrao' => Empresa::atual()->config('dia_vencimento_padrao'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cliente = Cliente::create(self::validar($request) + ['criado_por' => $request->user()->id, 'origem' => 'gestao']);
        Atividades::registrar('cliente_criado', 'cliente '.$cliente->id.' - '.$cliente->nome);

        return redirect("/clientes/{$cliente->id}")->with('sucesso', 'Cliente cadastrado. Agora adicione o veículo.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $cliente->update(self::validar($request, $cliente));
        Atividades::registrar('cliente_alterado', 'cliente '.$cliente->id.' - '.$cliente->nome);

        return redirect("/clientes/{$cliente->id}")->with('sucesso', 'Dados do cliente salvos.');
    }

    /**
     * "Remover" desativa: o histórico (e, a partir da etapa 3, o dinheiro) continua guardado.
     * Com conta em aberto, a tela pergunta o que fazer: "cancelar" (as contas a receber em aberto
     * viram canceladas, com o motivo "Cliente removido"; créditos ficam) ou "manter" (continuam
     * em aberto e na inadimplência). Receber não passa por aqui: a tela vai para Receber.
     */
    public function desativar(Request $request, Cliente $cliente): RedirectResponse
    {
        $abertas = \App\Servicos\Baixa::abertas($cliente)->where('tipo', '<>', 'credito');
        $d = $request->validate(['contas' => [$abertas->isNotEmpty() ? 'required' : 'nullable', 'in:cancelar,manter']],
            ['contas.required' => 'O cliente tem conta em aberto: escolha se as contas são excluídas, mantidas ou recebidas.']);
        if (($d['contas'] ?? null) === 'cancelar') {
            if (! $request->user()->pode('contas_editar')) {
                abort(403, 'Excluir as contas pede a permissão de alterar contas.');
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($cliente, $abertas, $d) {
            if (($d['contas'] ?? null) === 'cancelar') {
                $abertas->each(fn ($o) => \App\Servicos\Ordens::cancelar($o, 'Cliente removido'));
            }
            $this->encerrarCadastro($cliente);
        });
        $extra = match ($d['contas'] ?? null) {
            'cancelar' => ' '.$abertas->count().' conta(s) em aberto cancelada(s).',
            'manter' => ' As contas em aberto continuam a receber.',
            default => '',
        };

        return redirect('/clientes')->with('sucesso', 'Cliente removido. Ele continua na lista "Inativos" com o histórico.'.$extra);
    }

    private function encerrarCadastro(Cliente $cliente): void
    {
        $cliente->veiculos()->with('contratos')->get()->each(function (Veiculo $v) {
            if ($c = Contratos::atual($v)) {
                Contratos::encerrar($c, max(now()->toDateString(), $c->inicio->toDateString()), 'Cliente removido');
            }
            $v->update(['ativo' => false]);
        });
        $cliente->update(['ativo' => false]);
        Atividades::registrar('cliente_removido', 'cliente '.$cliente->id.' - '.$cliente->nome);
    }

    public function reativar(Cliente $cliente): RedirectResponse
    {
        $cliente->update(['ativo' => true]);
        Atividades::registrar('cliente_reativado', 'cliente '.$cliente->id.' - '.$cliente->nome);

        return back()->with('sucesso', 'Cliente reativado. Reative os veículos que voltarem.');
    }

    public static function validar(Request $request, ?Cliente $cliente = null, string $prefixo = ''): array
    {
        $request->merge([
            $prefixo.'documento' => Documento::limpar($request->input($prefixo.'documento')),
            $prefixo.'cep' => preg_replace('/\D/', '', (string) $request->input($prefixo.'cep')) ?: null,
            $prefixo.'telefone' => preg_replace('/\D/', '', (string) $request->input($prefixo.'telefone')) ?: null,
            $prefixo.'celular' => preg_replace('/\D/', '', (string) $request->input($prefixo.'celular')) ?: null,
            $prefixo.'uf' => strtoupper((string) $request->input($prefixo.'uf')) ?: null,
        ]);
        $empresa = app(EmpresaAtual::class)->id();
        $dados = $request->validate([
            $prefixo.'nome' => ['required', 'string', 'max:100'],
            $prefixo.'documento' => ['nullable', 'string', function ($attr, $valor, $falha) {
                if (! Documento::valido($valor)) {
                    $falha('CPF ou CNPJ inválido. Confira os números.');
                }
            }, Rule::unique('clientes', 'documento')->where('empresa_id', $empresa)->ignore($cliente?->id)],
            $prefixo.'telefone' => ['nullable', 'string', 'min:10', 'max:11'],
            $prefixo.'celular' => ['nullable', 'string', 'min:10', 'max:11'],
            $prefixo.'email' => ['nullable', 'email', 'max:120'],
            $prefixo.'cep' => ['nullable', 'digits:8'],
            $prefixo.'logradouro' => ['nullable', 'string', 'max:100'],
            $prefixo.'numero' => ['nullable', 'string', 'max:10'],
            $prefixo.'complemento' => ['nullable', 'string', 'max:80'],
            $prefixo.'bairro' => ['nullable', 'string', 'max:60'],
            $prefixo.'cidade' => ['nullable', 'string', 'max:60'],
            $prefixo.'uf' => ['nullable', 'alpha', 'size:2'],
            $prefixo.'dia_vencimento' => ['required', 'integer', 'between:1,31'],
            $prefixo.'cliente_desde' => ['nullable', 'date', 'before_or_equal:today'],
            $prefixo.'observacoes' => ['nullable', 'string', 'max:500'],
            $prefixo.'nao_recebe_avisos' => ['boolean'],
        ], [
            $prefixo.'documento.unique' => 'Este CPF/CNPJ já está cadastrado em outro cliente.',
        ]);
        $sem = [];
        foreach ($dados as $k => $v) {
            $sem[substr($k, strlen($prefixo))] = $v;
        }
        // nomes e endereço em maiúsculas, como no sistema atual
        foreach (['nome', 'logradouro', 'complemento', 'bairro', 'cidade'] as $campo) {
            if (isset($sem[$campo])) {
                $sem[$campo] = mb_strtoupper(trim($sem[$campo]));
            }
        }
        $sem['nao_recebe_avisos'] = (bool) ($sem['nao_recebe_avisos'] ?? false);

        return $sem;
    }
}
