<?php

namespace App\Http\Controllers;

use App\Models\MensalidadeContrato;
use App\Models\TipoVeiculo;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clientes › Mensalistas (era Relatórios › Controle e Lista de mensalistas no sistema atual):
 * resumo por grupo (carros, motos, caminhões) e período, e a lista filtrada por tipo de veículo.
 * A receita só aparece para quem tem a permissão de faturamento.
 */
class MensalistasController extends Controller
{
    public const GRUPOS = ['carro' => 'Carros', 'moto' => 'Motos', 'caminhao' => 'Caminhões'];

    public const PERIODOS = ['Diurno' => 'Diurno', 'Noturno' => 'Noturno', '24 horas' => '24 horas'];

    /** grupo do tipo: pelo código do sistema atual (7 moto, 6 caminhão) ou pelo nome */
    public static function grupo(?TipoVeiculo $t): string
    {
        $nome = mb_strtolower((string) $t?->nome);

        return match (true) {
            $t?->legado_id === 7, str_contains($nome, 'moto') => 'moto',
            $t?->legado_id === 6, str_contains($nome, 'caminh') && ! str_contains($nome, 'caminhonete') => 'caminhao',
            default => 'carro',
        };
    }

    private static function periodo(?string $p): string
    {
        $p = mb_strtolower((string) $p);

        return str_starts_with($p, 'not') ? 'Noturno' : (str_starts_with($p, '24') ? '24 horas' : 'Diurno');
    }

    /** mensalistas de hoje: contrato sem fim (ou terminando depois de hoje), valor maior que zero, cliente e veículo ativos */
    private static function vigentes(): Collection
    {
        return MensalidadeContrato::with(['cliente:id,nome', 'veiculo:id,cliente_id,placa,identificacao,marca,modelo,tipo_veiculo_id', 'veiculo.tipo:id,nome,legado_id'])
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>', now()->toDateString()))
            ->where('inicio', '<=', now()->toDateString())
            ->where('valor', '>', 0)
            ->whereHas('veiculo', fn ($q) => $q->where('ativo', true))->whereHas('cliente', fn ($q) => $q->where('ativo', true))
            ->get();
    }

    public function index(Request $request): Response|StreamedResponse
    {
        $f = $request->validate(['tipos' => ['nullable', 'array'], 'tipos.*' => ['integer']]);
        $receita = $request->user()->pode('faturamento');
        $todos = self::vigentes();

        // resumo (controle): grupo × período
        $controle = [];
        foreach (self::GRUPOS as $g => $_) {
            foreach (self::PERIODOS as $p => $__) {
                $controle[$g][$p] = ['qtd' => 0, 'valor' => 0.0];
            }
        }
        foreach ($todos as $c) {
            $x = &$controle[self::grupo($c->veiculo->tipo)][self::periodo($c->periodo)];
            $x['qtd']++;
            $x['valor'] += (float) $c->valor;
            unset($x);
        }
        if (! $receita) {
            array_walk_recursive($controle, function (&$v, $k) {
                if ($k === 'valor') {
                    $v = 0;
                }
            });
        }

        // tipos com quantos mensalistas cada um tem (filtro)
        $porTipo = $todos->countBy(fn ($c) => $c->veiculo->tipo_veiculo_id);
        $tipos = TipoVeiculo::orderBy('id')->get(['id', 'nome'])->map(fn ($t) => ['id' => $t->id, 'nome' => $t->nome, 'qtd' => $porTipo[$t->id] ?? 0]);
        $escolhidos = array_values(array_intersect(array_map('intval', $f['tipos'] ?? []), $tipos->pluck('id')->all()));
        if (count($escolhidos) === $tipos->count()) {
            $escolhidos = [];   // todos marcados = todos
        }
        $lista = $todos->when($escolhidos, fn ($l) => $l->filter(fn ($c) => in_array($c->veiculo->tipo_veiculo_id, $escolhidos, true)))
            ->sortBy([fn ($a, $b) => strcmp($a->cliente->nome, $b->cliente->nome), fn ($a, $b) => strcmp((string) $a->veiculo->placa, (string) $b->veiculo->placa)])
            ->values()->map(fn ($c) => [
                'contrato_id' => $c->id, 'cliente_id' => $c->cliente->id, 'cliente' => $c->cliente->nome,
                'veiculo' => $c->veiculo->rotulo(), 'descricao' => trim($c->veiculo->marca.' '.$c->veiculo->modelo),
                'tipo' => $c->veiculo->tipo?->nome, 'periodo' => self::periodo($c->periodo), 'valor' => $receita ? (float) $c->valor : null,
                // contrato criado no ERP (não veio do sistema atual): o relatório do sistema atual não conta
                'so_erp' => $c->legado_id === null,
            ]);
        $soErp = $todos->whereNull('legado_id');

        if ($request->boolean('csv')) {
            return Csv::baixar('mensalistas-'.now()->format('Y-m-d').'.csv',
                array_merge(['Código', 'Cliente', 'Veículo', 'Marca/modelo', 'Tipo', 'Período'], $receita ? ['Mensalidade'] : []),
                $lista->map(fn ($l) => array_merge([$l['cliente_id'], $l['cliente'], $l['veiculo'], $l['descricao'], $l['tipo'], $l['periodo']], $receita ? [Csv::numero($l['valor'])] : [])));
        }

        return Inertia::render('Clientes/Mensalistas', [
            'controle' => $controle, 'grupos' => self::GRUPOS, 'periodos' => self::PERIODOS, 'receita' => $receita,
            'tipos' => $tipos, 'escolhidos' => $escolhidos, 'lista' => $lista,
            'so_erp' => ['qtd' => $soErp->count(), 'valor' => $receita ? round((float) $soErp->sum('valor'), 2) : null],
        ]);
    }
}
