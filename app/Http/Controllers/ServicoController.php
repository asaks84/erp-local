<?php

namespace App\Http\Controllers;

use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\TipoVeiculo;
use App\Models\Unidade;
use App\Support\Atividades;
use App\Support\EmpresaAtual;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Lavagens e serviços, adicionais e preço por tipo de veículo (com vigência e exceção por unidade). */
class ServicoController extends Controller
{
    private function tipos()
    {
        return TipoVeiculo::where('ativo', true)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']);
    }

    /** preços que valem na data, por tipo: [tipo_id => valor] */
    private function precosEm(Servico $s, ?int $unidadeId, string $dia): array
    {
        return ServicoPreco::where('servico_id', $s->id)
            ->where('unidade_id', $unidadeId)
            ->where('vigencia_inicio', '<=', $dia)
            ->where(fn ($q) => $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $dia))
            ->get()->mapWithKeys(fn ($p) => [$p->tipo_veiculo_id => (float) $p->valor])->all();
    }

    public function index(): Response
    {
        $hoje = now()->toDateString();
        $servicos = Servico::with('adicionais:id,nome')->orderByDesc('ativo')->orderBy('papel')->orderBy('nome')->get();

        return Inertia::render('Configuracoes/Servicos', [
            'tipos' => $this->tipos(),
            'servicos' => $servicos->map(fn (Servico $s) => [
                ...$s->only(['id', 'codigo', 'nome', 'papel', 'preco_modo', 'detalhe', 'ativo']),
                'adicionais' => $s->adicionais->pluck('nome'),
                'precos' => $this->precosEm($s, null, $hoje),
            ]),
        ]);
    }

    private function opcoes(?Servico $s = null): array
    {
        return [
            'tipos' => $this->tipos(),
            'unidades' => Unidade::where('ativa', true)->orderBy('nome')->get(['id', 'nome']),
            'adicionais_disponiveis' => Servico::where('papel', 'adicional')->where('ativo', true)
                ->when($s, fn ($q) => $q->where('id', '<>', $s->id))->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('Configuracoes/ServicoForm', ['servico' => null] + $this->opcoes());
    }

    public function edit(Request $request, Servico $servico): Response
    {
        $unidade = $request->integer('unidade') ?: null;
        $hoje = now()->toDateString();

        return Inertia::render('Configuracoes/ServicoForm', [
            'servico' => [
                ...$servico->only(['id', 'codigo', 'nome', 'papel', 'preco_modo', 'detalhe', 'ativo', 'codigo_servico_municipal', 'aliquota_iss']),
                'adicionais' => $servico->adicionais()->pluck('servicos.id'),
                'precos' => (object) $this->precosEm($servico, $unidade, $hoje),
                'unidade_id' => $unidade,
                'agendados' => ServicoPreco::where('servico_id', $servico->id)->where('vigencia_inicio', '>', $hoje)
                    ->orderBy('vigencia_inicio')->get()
                    ->map(fn ($p) => [
                        'tipo_veiculo_id' => $p->tipo_veiculo_id, 'valor' => (float) $p->valor,
                        'vigencia_inicio' => $p->vigencia_inicio->format('Y-m-d'), 'unidade_id' => $p->unidade_id,
                    ]),
            ],
        ] + $this->opcoes($servico));
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $this->validar($request);
        DB::transaction(function () use ($d, &$s) {
            $s = Servico::create(collect($d)->except(['adicionais', 'precos', 'unidade_id', 'vale_a_partir'])->all());
            $s->adicionais()->sync($d['papel'] === 'principal' ? ($d['adicionais'] ?? []) : []);
            $this->gravarPrecos($s, $d);
            Atividades::registrar('servico_criado', $s->codigo.' - '.$s->nome);
        });

        return redirect('/configuracoes/servicos')->with('sucesso', 'Serviço criado.');
    }

    public function update(Request $request, Servico $servico): RedirectResponse
    {
        $d = $this->validar($request, $servico);
        DB::transaction(function () use ($d, $servico) {
            $servico->update(collect($d)->except(['adicionais', 'precos', 'unidade_id', 'vale_a_partir'])->all());
            $servico->adicionais()->sync($d['papel'] === 'principal' ? ($d['adicionais'] ?? []) : []);
            $mudou = $this->gravarPrecos($servico, $d);
            Atividades::registrar('servico_alterado', $servico->codigo.' - '.$servico->nome.($mudou ? ' (preços: '.$mudou.')' : ''));
        });

        return redirect('/configuracoes/servicos')->with('sucesso', 'Serviço salvo.');
    }

    /**
     * Preço novo vale a partir da data escolhida: a versão anterior termina na véspera.
     * Preço apagado = deixa de valer na data. Devolve um resumo do que mudou.
     */
    private function gravarPrecos(Servico $s, array $d): string
    {
        $data = Carbon::parse($d['vale_a_partir'] ?? now()->toDateString())->startOfDay();
        $dia = $data->toDateString();
        $unidade = $d['unidade_id'] ?? null;
        $tipos = TipoVeiculo::pluck('nome', 'id');
        $mudancas = [];

        foreach ($d['precos'] ?? [] as $tipoId => $valor) {
            $valor = ($valor === null || $valor === '') ? null : round((float) $valor, 2);
            $base = ServicoPreco::where('servico_id', $s->id)->where('tipo_veiculo_id', $tipoId)->where('unidade_id', $unidade);
            // versões que começariam depois da data são substituídas por esta
            (clone $base)->where('vigencia_inicio', '>', $dia)->delete();
            $atual = (clone $base)->where('vigencia_inicio', '<=', $dia)
                ->where(fn ($q) => $q->whereNull('vigencia_fim')->orWhere('vigencia_fim', '>=', $dia))->first();

            if ($atual && $valor !== null && abs((float) $atual->valor - $valor) < 0.001) {
                continue;
            }
            if ($atual) {
                if ($atual->vigencia_inicio->gte($data)) {
                    $valor === null ? $atual->delete() : $atual->update(['valor' => $valor]);
                } else {
                    $atual->update(['vigencia_fim' => $data->copy()->subDay()->toDateString()]);
                    if ($valor !== null) {
                        ServicoPreco::create(['servico_id' => $s->id, 'tipo_veiculo_id' => $tipoId, 'unidade_id' => $unidade, 'valor' => $valor, 'vigencia_inicio' => $dia]);
                    }
                }
            } elseif ($valor !== null) {
                ServicoPreco::create(['servico_id' => $s->id, 'tipo_veiculo_id' => $tipoId, 'unidade_id' => $unidade, 'valor' => $valor, 'vigencia_inicio' => $dia]);
            } else {
                continue;
            }
            $mudancas[] = ($tipos[$tipoId] ?? $tipoId).' '.($valor === null ? 'sem preço' : Formato::moeda($valor));
        }

        return $mudancas ? implode(', ', $mudancas).' a partir de '.$data->format('d/m/Y') : '';
    }

    private function validar(Request $request, ?Servico $s = null): array
    {
        $empresa = app(EmpresaAtual::class)->id();
        $request->merge(['codigo' => strtoupper(trim((string) $request->input('codigo')))]);

        return $request->validate([
            'codigo' => ['required', 'alpha_num', 'max:10', Rule::unique('servicos')->where('empresa_id', $empresa)->ignore($s?->id)],
            'nome' => ['required', 'string', 'max:60'],
            'papel' => ['required', 'in:principal,adicional'],
            'preco_modo' => ['required', 'in:fixo,ate'],
            'detalhe' => ['nullable', 'string', 'max:160'],
            'ativo' => ['boolean'],
            'codigo_servico_municipal' => ['nullable', 'string', 'max:20'],
            'aliquota_iss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'adicionais' => ['array'],
            'adicionais.*' => ['integer', Rule::exists('servicos', 'id')->where('empresa_id', $empresa)->where('papel', 'adicional')],
            'unidade_id' => ['nullable', Rule::exists('unidades', 'id')->where('empresa_id', $empresa)],
            'vale_a_partir' => ['required', 'date'],
            'precos' => ['array'],
            'precos.*' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ], ['codigo.unique' => 'Já existe um serviço com este código.']);
    }
}
