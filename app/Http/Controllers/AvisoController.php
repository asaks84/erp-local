<?php

namespace App\Http\Controllers;

use App\Models\Aviso;
use App\Servicos\WhatsApp\Modelos;
use App\Servicos\WhatsApp\Regua;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Cobranças › Avisos: os avisos de cobrança do dia (enviar pelo WhatsApp) e o que já saiu. */
class AvisoController extends Controller
{
    public function index(): Response
    {
        Regua::cancelarResolvidos();
        Regua::gerar();
        $linha = fn (Aviso $a) => [
            'id' => $a->id, 'dias' => $a->dias, 'rotulo' => Modelos::ROTULOS[Modelos::doPasso($a->dias)], 'previsto_para' => $a->previsto_para->format('Y-m-d'),
            'vencimento' => $a->vencimento->format('Y-m-d'), 'situacao' => $a->situacao, 'canal' => $a->canal, 'celular' => $a->celular, 'texto' => $a->texto,
            'erro' => $a->erro, 'enviado_em' => $a->enviado_em?->format('Y-m-d H:i'),
            'cliente' => $a->cliente?->only(['id', 'nome']), 'cobranca' => $a->cobranca ? ['id' => $a->cobranca->id, 'numero' => $a->cobranca->numero] : null,
            'impedimento' => in_array($a->situacao, ['pendente', 'falha'], true) ? Regua::impedimento($a) : null,
        ];
        $pendentes = Aviso::with(['cliente', 'cobranca'])->whereIn('situacao', ['pendente', 'falha'])->orderBy('previsto_para')->orderBy('id')->get();
        $enviados = Aviso::with(['cliente', 'cobranca'])->whereIn('situacao', ['enviado', 'entregue', 'lido'])->orderByDesc('enviado_em')->limit(100)->get();

        return Inertia::render('Avisos/Index', [
            'pendentes' => $pendentes->map($linha)->values(),
            'enviados' => $enviados->map($linha)->values(),
            'api' => Regua::ligado(),
            'automatico' => Regua::automatico(),
            'hora' => Regua::hora(),
            'passos' => collect(Regua::passos())->where('ativo', true)->pluck('dias')->values(),
        ]);
    }

    /** o operador abriu o WhatsApp (wa.me) com o texto pronto */
    public function manual(Aviso $aviso): RedirectResponse
    {
        Regua::marcarManual($aviso);

        return back();
    }

    /** envia um aviso agora pela API oficial */
    public function enviar(Aviso $aviso): RedirectResponse
    {
        if (! Regua::ligado()) {
            return back()->with('erro', 'O WhatsApp (API) não está ligado. Use o envio manual.');
        }
        $aviso->load(['cobranca', 'cliente']);

        return Regua::enviar($aviso) ? back()->with('sucesso', 'Aviso enviado para '.$aviso->cliente->nome.'.') : back()->with('erro', 'Não saiu: '.$aviso->refresh()->erro);
    }

    /** envia pela API todos os que podem sair */
    public function enviarTodos(): RedirectResponse
    {
        if (! Regua::ligado()) {
            return back()->with('erro', 'O WhatsApp (API) não está ligado.');
        }
        $ok = $falha = 0;
        foreach (Aviso::with(['cobranca', 'cliente'])->where('situacao', 'pendente')->orderBy('id')->limit(200)->get() as $a) {
            Regua::enviar($a) ? $ok++ : $falha++;
        }

        return back()->with($falha ? 'aviso' : 'sucesso', "{$ok} aviso(s) enviado(s)".($falha ? ", {$falha} não saíram (veja o motivo em cada um)." : '.'));
    }

    /** tira um aviso da lista (não vai ser enviado) */
    public function dispensar(Aviso $aviso): RedirectResponse
    {
        if (in_array($aviso->situacao, ['pendente', 'falha'], true)) {
            $aviso->update(['situacao' => 'cancelado', 'erro' => 'Dispensado por '.auth()->user()->nome]);
        }

        return back();
    }
}
