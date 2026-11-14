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
    public function emitir(Request $request, Cobranca $cobranca): RedirectResponse
    {
        $d = $request->validate(['regras' => ['nullable', 'boolean'], 'vencimento' => ['nullable', 'date_format:Y-m-d']],
            ['vencimento.date_format' => 'Data de vencimento inválida.']);
        $b = Boletos::emitir($cobranca, null, isset($d['regras']) ? (bool) $d['regras'] : null,
            ! empty($d['vencimento']) ? \Illuminate\Support\Carbon::parse($d['vencimento']) : null);

        return back()->with('sucesso', $b->situacao === 'em_processamento'
            ? 'Boleto pedido ao Inter. A linha digitável e o Pix aparecem em instantes (clique em Atualizar).'
            : 'Boleto gerado'.(! empty($b->regras['sem_regras']) ? ' sem multa, juros e desconto.' : '.'));
    }

    public function atualizar(Request $request, Boleto $boleto): RedirectResponse
    {
        // "silencioso": a tela consultando sozinha enquanto o boleto está em emissão (sem aviso a cada vez)
        $silencioso = $request->boolean('silencioso');
        try {
            Boletos::atualizar($boleto);
        } catch (InterErro $e) {
            return $silencioso ? back() : back()->with('erro', 'O Inter respondeu: '.$e->getMessage());
        }
        if ($silencioso) {
            return $boleto->situacao === 'em_processamento' ? back() : back()->with('sucesso', 'Boleto pronto.');
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
