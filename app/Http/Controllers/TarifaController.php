<?php

namespace App\Http\Controllers;

use App\Models\Tarifa;
use App\Models\TipoVeiculo;
use App\Models\Unidade;
use App\Servicos\Tarifador;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Tabelas de cobrança de estadia, com vigência (reajuste = nova versão) e simulador. */
class TarifaController extends Controller
{
    public function index(): Response
    {
        $hoje = now()->startOfDay();

        return Inertia::render('Configuracoes/Tarifas', [
            'tarifas' => Tarifa::with(['tipos:id,nome', 'unidade:id,nome', 'faixas', 'pacotes'])
                ->orderBy('nome')->orderByDesc('vigencia_inicio')->get()
                ->map(fn (Tarifa $t) => [
                    'id' => $t->id,
                    'nome' => $t->nome,
                    'unidade' => $t->unidade?->nome,
                    'tipos' => $t->tipos->pluck('nome'),
                    'vigencia_inicio' => $t->vigencia_inicio->format('Y-m-d'),
                    'vigencia_fim' => $t->vigencia_fim?->format('Y-m-d'),
                    'situacao' => $t->vigencia_inicio->gt($hoje) ? 'futura' : ($t->vigencia_fim && $t->vigencia_fim->lt($hoje) ? 'encerrada' : 'vigente'),
                    'resumo' => $this->resumo($t),
                ]),
        ]);
    }

    private function resumo(Tarifa $t): string
    {
        $partes = [];
        foreach ($t->faixas as $f) {
            $partes[] = $f->modo === 'fixo'
                ? ($f->duracao_min ? \App\Support\Formato::duracao($f->duracao_min) : 'Restante').' '.\App\Support\Formato::moeda($f->valor)
                : 'cada '.\App\Support\Formato::duracao($f->bloco_min).' '.\App\Support\Formato::moeda($f->valor);
        }
        foreach ($t->pacotes as $p) {
            $partes[] = $p->nome.' '.\App\Support\Formato::moeda($p->valor);
        }

        return implode(' · ', $partes) ?: 'Sem regras';
    }

    private function opcoes(): array
    {
        return [
            'tipos' => TipoVeiculo::where('ativo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    private function dados(Tarifa $t): array
    {
        return [
            ...$t->only(['id', 'nome', 'unidade_id', 'tolerancia_min', 'fracao_min', 'restante']),
            'vigencia_inicio' => $t->vigencia_inicio->format('Y-m-d'),
            'vigencia_fim' => $t->vigencia_fim?->format('Y-m-d'),
            'tipos' => $t->tipos->pluck('id'),
            'faixas' => $t->faixas->map->only(['duracao_min', 'modo', 'bloco_min', 'valor'])->values(),
            'pacotes' => $t->pacotes->map->only(['nome', 'duracao_min', 'valor', 'hora_inicio', 'hora_fim'])->values(),
        ];
    }

    public function create(Request $request): Response
    {
        $base = $request->integer('copiar') ? Tarifa::with(['tipos', 'faixas', 'pacotes'])->findOrFail($request->integer('copiar')) : null;
        $tarifa = null;
        if ($base) {
            // nova versão (reajuste): mesmas regras, começando amanhã
            $tarifa = ['id' => null, 'anterior_id' => $base->id, 'vigencia_inicio' => now()->addDay()->toDateString()] + $this->dados($base);
            $tarifa['id'] = null;
            $tarifa['vigencia_inicio'] = now()->addDay()->toDateString();
        }

        return Inertia::render('Configuracoes/TarifaForm', ['tarifa' => $tarifa, 'versao_de' => $base?->only(['id', 'nome'])] + $this->opcoes());
    }

    public function edit(Tarifa $tarifa): Response
    {
        $tarifa->load(['tipos', 'faixas', 'pacotes']);

        return Inertia::render('Configuracoes/TarifaForm', ['tarifa' => $this->dados($tarifa), 'versao_de' => null] + $this->opcoes());
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        $anterior = $request->integer('anterior_id') ? Tarifa::findOrFail($request->integer('anterior_id')) : null;

        DB::transaction(function () use ($d, $anterior) {
            if ($anterior) {
                // nova versão: a anterior termina na véspera
                $inicio = Carbon::parse($d['vigencia_inicio']);
                if ($inicio->lte($anterior->vigencia_inicio)) {
                    throw ValidationException::withMessages(['vigencia_inicio' => 'A nova versão precisa começar depois de '.$anterior->vigencia_inicio->format('d/m/Y').'.']);
                }
                $anterior->update(['vigencia_fim' => $inicio->copy()->subDay()->toDateString()]);
            }
            $this->conferirConflito($d, $anterior?->id);
            $t = Tarifa::create(collect($d)->except(['tipos', 'faixas', 'pacotes'])->all());
            $this->gravarRegras($t, $d);
            Atividades::registrar($anterior ? 'tarifa_nova_versao' : 'tarifa_criada', $t->nome.' a partir de '.$t->vigencia_inicio->format('d/m/Y'));
        });

        return redirect('/configuracoes/tarifas')->with('sucesso', $anterior ? 'Nova versão salva. A atual vale até a véspera do início da nova.' : 'Tabela criada.');
    }

    public function update(Request $request, Tarifa $tarifa): RedirectResponse
    {
        $d = $this->validar($request);
        DB::transaction(function () use ($d, $tarifa) {
            $this->conferirConflito($d, $tarifa->id);
            $tarifa->update(collect($d)->except(['tipos', 'faixas', 'pacotes'])->all());
            $tarifa->faixas()->delete();
            $tarifa->pacotes()->delete();
            $this->gravarRegras($tarifa, $d);
            Atividades::registrar('tarifa_alterada', $tarifa->nome);
        });

        return redirect('/configuracoes/tarifas')->with('sucesso', 'Tabela salva.');
    }

    public function destroy(Tarifa $tarifa): RedirectResponse
    {
        // a partir da etapa 4, tabela usada por estadia não pode ser excluída
        Atividades::registrar('tarifa_excluida', $tarifa->nome);
        $tarifa->tipos()->detach();
        $tarifa->delete();

        return redirect('/configuracoes/tarifas')->with('sucesso', 'Tabela excluída.');
    }

    /** simulador: entrada e saída → valor e conta, com as regras que estão na tela (mesmo sem salvar) */
    public function simular(Request $request): JsonResponse
    {
        $d = $request->validate([
            'entrada' => ['required', 'date'],
            'saida' => ['required', 'date', 'after_or_equal:entrada'],
            'regras' => ['required', 'array'],
        ], ['saida.after_or_equal' => 'A saída precisa ser depois da entrada.']);
        $r = $d['regras'];
        $t = new Tarifador([
            'tolerancia' => $r['tolerancia_min'] ?? 0,
            'fracao' => $r['fracao_min'] ?? 0,
            'restante' => $r['restante'] ?? 'faixas',
            'faixas' => collect($r['faixas'] ?? [])->map(fn ($f) => ['duracao' => $f['duracao_min'] ?? null, 'modo' => $f['modo'] ?? 'bloco', 'bloco' => $f['bloco_min'] ?? 60, 'valor' => $f['valor'] ?? 0])->all(),
            'pacotes' => collect($r['pacotes'] ?? [])->map(fn ($p) => ['nome' => $p['nome'] ?? '', 'duracao' => $p['duracao_min'] ?? null, 'valor' => $p['valor'] ?? 0, 'hora_inicio' => $p['hora_inicio'] ?? null, 'hora_fim' => $p['hora_fim'] ?? null])->all(),
        ]);

        return response()->json($t->calcular($d['entrada'], $d['saida']));
    }

    private function validar(Request $request): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $d = $request->validate([
            'nome' => ['required', 'string', 'max:60'],
            'unidade_id' => ['nullable', Rule::exists('unidades', 'id')->where('empresa_id', $empresa)],
            'tolerancia_min' => ['required', 'integer', 'min:0', 'max:1440'],
            'fracao_min' => ['required', 'integer', 'min:0', 'max:1440'],
            'restante' => ['required', 'in:faixas,pacotes'],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'tipos' => ['array'],
            'tipos.*' => ['integer', Rule::exists('tipos_veiculo', 'id')->where('empresa_id', $empresa)],
            'faixas' => ['array'],
            'faixas.*.duracao_min' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'faixas.*.modo' => ['required', 'in:fixo,bloco'],
            'faixas.*.bloco_min' => ['required_if:faixas.*.modo,bloco', 'nullable', 'integer', 'min:1', 'max:1440'],
            'faixas.*.valor' => ['required', 'numeric', 'min:0', 'max:99999'],
            'pacotes' => ['array'],
            'pacotes.*.nome' => ['required', 'string', 'max:40'],
            'pacotes.*.duracao_min' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'pacotes.*.valor' => ['required', 'numeric', 'min:0', 'max:99999'],
            'pacotes.*.hora_inicio' => ['nullable', 'date_format:H:i'],
            'pacotes.*.hora_fim' => ['nullable', 'date_format:H:i', 'required_with:pacotes.*.hora_inicio'],
        ], [
            'faixas.*.valor.required' => 'Informe o valor de cada faixa.',
            'pacotes.*.nome.required' => 'Dê um nome a cada pacote (ex.: Diária 12h).',
        ]);
        foreach ($d['pacotes'] ?? [] as $i => $p) {
            if (empty($p['duracao_min']) && empty($p['hora_inicio'])) {
                throw ValidationException::withMessages(["pacotes.$i.duracao_min" => 'Informe a duração ou o horário (início e fim) do pacote.']);
            }
        }
        if (empty($d['faixas']) && empty($d['pacotes'])) {
            throw ValidationException::withMessages(['faixas' => 'Cadastre ao menos uma faixa ou um pacote.']);
        }

        return $d;
    }

    /** um tipo de veículo não pode estar em duas tabelas que valem ao mesmo tempo, no mesmo nível (empresa ou unidade) */
    private function conferirConflito(array $d, ?int $ignorar): void
    {
        if (empty($d['tipos'])) {
            return;
        }
        $fim = $d['vigencia_fim'] ?? '9999-12-31';
        $conflito = Tarifa::query()
            ->when($ignorar, fn ($q) => $q->where('id', '<>', $ignorar))
            ->where(fn ($q) => ($d['unidade_id'] ?? null) ? $q->where('unidade_id', $d['unidade_id']) : $q->whereNull('unidade_id'))
            ->where('vigencia_inicio', '<=', $fim)
            ->where(fn ($q) => $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $d['vigencia_inicio']))
            ->whereHas('tipos', fn ($q) => $q->whereIn('tipos_veiculo.id', $d['tipos']))
            ->with('tipos')->first();
        if ($conflito) {
            $tipos = $conflito->tipos->whereIn('id', $d['tipos'])->pluck('nome')->implode(', ');
            throw ValidationException::withMessages(['tipos' => "Os tipos {$tipos} já estão na tabela \"{$conflito->nome}\" nesse mesmo período."]);
        }
    }

    private function gravarRegras(Tarifa $t, array $d): void
    {
        foreach (array_values($d['faixas'] ?? []) as $i => $f) {
            $t->faixas()->create(['ordem' => $i, 'duracao_min' => $f['duracao_min'] ?? null, 'modo' => $f['modo'], 'bloco_min' => $f['bloco_min'] ?? 60, 'valor' => $f['valor']]);
        }
        foreach ($d['pacotes'] ?? [] as $p) {
            $t->pacotes()->create([
                'nome' => $p['nome'], 'duracao_min' => $p['duracao_min'] ?? null, 'valor' => $p['valor'],
                'hora_inicio' => $p['hora_inicio'] ?? null, 'hora_fim' => $p['hora_fim'] ?? null,
            ]);
        }
        $t->tipos()->sync($d['tipos'] ?? []);
    }
}
