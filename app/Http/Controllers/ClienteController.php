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
            ],
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
            'periodos' => Empresa::atual()->config('periodos'),
            'unidades' => \App\Models\Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
            'pausa_padrao' => ['tipo' => Empresa::atual()->config('pausa_taxa_tipo'), 'valor' => Empresa::atual()->config('pausa_taxa_valor')],
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

    /** "Remover" desativa: o histórico (e, a partir da etapa 3, o dinheiro) continua guardado. */
    public function desativar(Cliente $cliente): RedirectResponse
    {
        $cliente->veiculos()->with('contratos')->get()->each(function (Veiculo $v) {
            if ($c = Contratos::atual($v)) {
                Contratos::encerrar($c, max(now()->toDateString(), $c->inicio->toDateString()), 'Cliente removido');
            }
            $v->update(['ativo' => false]);
        });
        $cliente->update(['ativo' => false]);
        Atividades::registrar('cliente_removido', 'cliente '.$cliente->id.' - '.$cliente->nome);

        return redirect('/clientes')->with('sucesso', 'Cliente removido. Ele continua na lista "Inativos" com o histórico.');
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
