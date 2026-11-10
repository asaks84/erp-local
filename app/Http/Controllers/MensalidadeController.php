<?php

namespace App\Http\Controllers;

use App\Models\CadastroPendente;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Servicos\Mensalidades;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Geração das mensalidades do mês e primeira cobrança de quem acabou de entrar. */
class MensalidadeController extends Controller
{
    private function competencia(Request $request): string
    {
        $mes = (string) $request->query('mes', '');
        if (! Mensalidades::competenciaValida($mes)) {
            // padrão: o mês que vem a partir do dia 20, senão o mês atual
            $mes = now()->day >= 20 ? now()->addMonthNoOverflow()->format('Y-m') : now()->format('Y-m');
        }

        return $mes;
    }

    public function gerar(Request $request): Response
    {
        $mes = $this->competencia($request);

        return Inertia::render('Mensalidades/Gerar', [
            'mes' => $mes,
            'linhas' => Mensalidades::previa($mes),
        ]);
    }

    public function confirmarGeracao(Request $request): RedirectResponse
    {
        $d = $request->validate(['mes' => ['required', 'date_format:Y-m'], 'contratos' => ['required', 'array', 'min:1'], 'contratos.*' => ['integer']],
            ['contratos.required' => 'Marque pelo menos um veículo.']);
        [$qtd, $total] = Mensalidades::gerar($d['mes'], $d['contratos']);
        $mes = Carbon::parse($d['mes'].'-01')->format('m/Y');

        return redirect('/mensalidades/gerar?mes='.$d['mes'])->with('sucesso', $qtd
            ? "{$qtd} mensalidade(s) de {$mes} gerada(s), total ".\App\Support\Formato::moeda($total).'.'
            : 'Nenhuma mensalidade nova: as marcadas já estavam geradas.');
    }

    public function primeira(Request $request, MensalidadeContrato $contrato): Response
    {
        $contrato->load(['cliente', 'veiculo']);
        $hoje = now()->startOfDay();
        [$valor, $dias, $de, $ate] = Mensalidades::proporcional((float) $contrato->valor, $hoje);
        $de_onde = in_array($request->query('de'), ['aprovacao', 'veiculo']) ? $request->query('de') : 'veiculo';

        return Inertia::render('Mensalidades/Primeira', [
            'contrato' => [
                'id' => $contrato->id,
                'valor' => (float) $contrato->valor,
                'periodo' => $contrato->periodo,
                'inicio' => $contrato->inicio->format('Y-m-d'),
            ],
            'cliente' => $contrato->cliente->only(['id', 'nome', 'dia_vencimento']),
            'veiculo' => $contrato->veiculo->rotulo(),
            'proporcional' => ['valor' => $valor, 'dias' => $dias, 'de' => $de, 'ate' => $ate],
            'proximo_vencimento' => Mensalidades::vencimentoNoMes((int) $contrato->cliente->dia_vencimento, $hoje->copy()->startOfMonth()->addMonth()->format('Y-m'))->toDateString(),
            'opcoes' => Mensalidades::OPCOES_PRIMEIRA,
            'livres' => Mensalidades::OPCOES_LIVRES,
            'ja_geradas' => OrdemServico::where('tipo', 'mensalidade')->where('veiculo_id', $contrato->veiculo_id)->where('situacao', '<>', 'cancelada')
                ->orderByDesc('competencia')->limit(3)->get(['numero', 'descricao', 'valor', 'competencia', 'vencimento', 'situacao'])
                ->map(fn ($o) => [...$o->only(['numero', 'descricao', 'competencia', 'situacao']), 'valor' => (float) $o->valor, 'vencimento' => $o->vencimento?->format('Y-m-d')]),
            'de' => $de_onde,
            'fila' => $de_onde === 'aprovacao' ? CadastroPendente::count() : 0,
        ]);
    }

    public function confirmarPrimeira(Request $request, MensalidadeContrato $contrato): RedirectResponse
    {
        $d = $request->validate(['opcao' => ['required', 'string'], 'valor' => ['nullable', 'numeric', 'gt:0', 'max:99999'], 'de' => ['nullable', 'string']]);
        $podeMudar = $request->user()->pode('mensalidade_valor');
        // sem a permissão: sem cortesia, sem "não gerar" e sem mudar o valor do proporcional
        if (! $podeMudar && ! in_array($d['opcao'], Mensalidades::OPCOES_LIVRES, true)) {
            throw ValidationException::withMessages(['opcao' => 'Seu nível de acesso não permite cortesia nem deixar de gerar.']);
        }
        $valor = $podeMudar && isset($d['valor']) ? (float) $d['valor'] : null;
        $geradas = Mensalidades::primeiraCobranca($contrato->load('cliente', 'veiculo'), $d['opcao'], $valor);

        $msg = match (true) {
            $d['opcao'] === 'nenhuma' => 'Nenhuma mensalidade gerada agora; ela entra na geração do mês.',
            count($geradas) > 0 => count($geradas).' mensalidade(s) lançada(s) na conta do cliente.',
            default => 'As mensalidades desses meses já existiam; nada foi lançado de novo.',
        };
        if (($d['de'] ?? '') === 'aprovacao' && CadastroPendente::exists()) {
            return redirect('/pendentes')->with('sucesso', $msg.' Próximo cadastro da fila:');
        }

        return redirect("/clientes/{$contrato->cliente_id}")->with('sucesso', $msg);
    }
}
