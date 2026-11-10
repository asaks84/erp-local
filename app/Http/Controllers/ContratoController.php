<?php

namespace App\Http\Controllers;

use App\Models\MensalidadeContrato;
use App\Models\MensalidadePausa;
use App\Models\Veiculo;
use App\Servicos\Contratos;
use App\Support\EmpresaAtual;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ações na mensalidade de um veículo, feitas pela ficha do cliente. */
class ContratoController extends Controller
{
    public function store(Request $request, Veiculo $veiculo): RedirectResponse
    {
        $d = $request->validate([
            'periodo' => ['required', 'string', 'max:30'],
            'valor' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'inicio' => ['required', 'date'],
            'unidade_id' => ['required', Rule::exists('unidades', 'id')->where('empresa_id', app(EmpresaAtual::class)->id())->where('ativa', true)],
        ]);
        Contratos::criar($veiculo, $d);

        return back()->with('sucesso', 'Mensalidade cadastrada.');
    }

    public function reajustar(Request $request, MensalidadeContrato $contrato): RedirectResponse
    {
        $d = $request->validate([
            'valor' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'a_partir' => ['required', 'date'],
            'periodo' => ['nullable', 'string', 'max:30'],
        ]);
        Contratos::reajustar($contrato, (float) $d['valor'], $d['a_partir'], $d['periodo'] ?? null);

        return back()->with('sucesso', 'Reajuste registrado.');
    }

    public function encerrar(Request $request, MensalidadeContrato $contrato): RedirectResponse
    {
        $d = $request->validate(['data' => ['required', 'date'], 'motivo' => ['required', 'string', 'max:200']]);
        Contratos::encerrar($contrato, $d['data'], $d['motivo']);

        return back()->with('sucesso', 'Encerramento registrado.');
    }

    public function desfazerEncerramento(MensalidadeContrato $contrato): RedirectResponse
    {
        Contratos::desfazerEncerramento($contrato);

        return back()->with('sucesso', 'Encerramento desfeito.');
    }

    public function pausar(Request $request, MensalidadeContrato $contrato): RedirectResponse
    {
        $d = $request->validate([
            'de' => ['required', 'date_format:Y-m'],
            'ate' => ['required', 'date_format:Y-m'],
            'taxa_tipo' => ['required', 'in:valor,pct'],
            'taxa_valor' => ['required', 'numeric', 'min:0', 'max:99999'],
            'motivo' => ['nullable', 'string', 'max:200'],
        ]);
        Contratos::pausar($contrato, $d['de'], $d['ate'], $d['taxa_tipo'], (float) $d['taxa_valor'], $d['motivo'] ?? null);

        return back()->with('sucesso', 'Pausa registrada.');
    }

    public function removerPausa(MensalidadePausa $pausa): RedirectResponse
    {
        Contratos::removerPausa($pausa);

        return back()->with('sucesso', 'Pausa removida.');
    }
}
