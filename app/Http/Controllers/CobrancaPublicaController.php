<?php

namespace App\Http\Controllers;

use App\Models\Cobranca;
use App\Models\Empresa;
use App\Servicos\Inter\Boletos;
use App\Support\EmpresaAtual;
use Inertia\Inertia;
use Inertia\Response;

/** Página pública da cobrança (/c/<código>): o que o cliente deve e como pagar (boleto e Pix). */
class CobrancaPublicaController extends Controller
{
    public function __invoke(string $token): Response
    {
        $c = Cobranca::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        app(EmpresaAtual::class)->definir($c->empresa_id);
        $empresa = Empresa::find($c->empresa_id);
        $c->load(['itens', 'cliente:id,nome']);
        $aberta = in_array($c->situacao, ['aberta', 'parcial'], true);
        $b = $aberta ? Boletos::ativo($c) : null;
        $valor = $aberta ? max(0, round(Boletos::valorEmAberto($c) - (float) $c->desconto, 2)) : 0;

        return Inertia::render('Cobrancas/Publica', [
            'empresa_publica' => ['nome' => $empresa->nome, 'telefone' => $empresa->telefone],
            'cobranca' => [
                'numero' => $c->numero, 'situacao' => $c->situacao, 'rotulo_situacao' => Cobranca::SITUACOES[$c->situacao] ?? $c->situacao,
                'vencimento' => ($b?->vencimento ?? $c->vencimento)->format('Y-m-d'),
                'total' => (float) $c->total, 'desconto' => (float) $c->desconto, 'a_pagar' => $b ? (float) $b->valor : $valor,
                'primeiro_nome' => mb_convert_case(strtok((string) $c->cliente?->nome, ' ') ?: '', MB_CASE_TITLE),
                'itens' => $c->itens->map(fn ($i) => ['descricao' => $i->descricao, 'placa' => $i->placa, 'valor' => (float) $i->valor])->values(),
                'instrucoes' => (string) $empresa->config('cobranca_instrucoes'),
            ],
            'boleto' => $b && $b->linha_digitavel ? ['linha_digitavel' => $b->linha_digitavel, 'pix_copia_cola' => $b->pix_copia_cola, 'valor' => (float) $b->valor] : null,
        ]);
    }
}
