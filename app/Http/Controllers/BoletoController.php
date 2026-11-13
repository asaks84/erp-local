<?php

namespace App\Http\Controllers;

use App\Models\Boleto;
use App\Models\Cobranca;
use App\Servicos\Inter\Boletos;
use App\Servicos\Inter\InterCliente;
use App\Servicos\Inter\InterErro;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Boleto com Pix do Inter na tela da cobrança: gerar, atualizar, PDF e cancelar. */
class BoletoController extends Controller
{
    public function emitir(Cobranca $cobranca): RedirectResponse
    {
        $b = Boletos::emitir($cobranca);

        return back()->with('sucesso', $b->situacao === 'em_processamento'
            ? 'Boleto pedido ao Inter. A linha digitável e o Pix aparecem em instantes (clique em Atualizar).'
            : 'Boleto gerado.');
    }

    public function atualizar(Boleto $boleto): RedirectResponse
    {
        try {
            Boletos::atualizar($boleto);
        } catch (InterErro $e) {
            return back()->with('erro', 'O Inter respondeu: '.$e->getMessage());
        }

        return back()->with('sucesso', 'Boleto atualizado: '.Boleto::SITUACOES[$boleto->situacao].'.');
    }

    public function pdf(Boleto $boleto): Response
    {
        abort_unless($boleto->codigo_solicitacao && ! in_array($boleto->situacao, ['falha', 'em_processamento'], true), 404);
        try {
            $pdf = (new InterCliente(Boletos::integracao()))->pdf($boleto->codigo_solicitacao);
        } catch (InterErro $e) {
            return back()->with('erro', 'O Inter não devolveu o PDF: '.$e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="boleto-'.$boleto->seu_numero.'.pdf"',
        ]);
    }

    public function cancelar(Request $request, Boleto $boleto): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:50']], ['motivo.required' => 'Informe o motivo.']);
        Boletos::cancelar($boleto, $d['motivo']);

        return back()->with('sucesso', 'Boleto cancelado no Inter. A cobrança continua em aberto.');
    }
}
