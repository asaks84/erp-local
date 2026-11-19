<?php

namespace App\Http\Controllers\Pdv;

use App\Http\Controllers\Controller;
use App\Models\Caixa;
use App\Models\Estadia;
use App\Models\OrdemServico;
use App\Servicos\EtapasLavagem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API do PDV, visual novo (8.8): os números do Início e a saída que aceita qualquer ticket. */
class InicioPdvController extends Controller
{
    /** carros no pátio, lavagens em andamento e o caixa aberto (sem abrir um caixa só por olhar) */
    public function resumo(): JsonResponse
    {
        $caixa = Caixa::where('situacao', 'aberto')->orderBy('id')->first();

        return response()->json([
            'patio' => Estadia::where('situacao', 'patio')->count(),
            'lavagens' => OrdemServico::where('tipo', 'servico')->whereNull('principal_id')->whereNotNull('etapa')
                ->where('situacao', '<>', 'cancelada')->where('etapa', '<>', EtapasLavagem::ULTIMA)->count(),
            'caixa' => $caixa ? ['desde' => $caixa->aberto_em->format('H:i')] : null,
        ]);
    }

    /**
     * Saída do Início: QR, número ou placa de qualquer ticket. Procura estadia no pátio e lavagem em andamento;
     * achou só uma, abre; achou as duas (ex.: busca pela placa de um carro com estadia e lavagem), pergunta.
     */
    public function ticket(Request $r): JsonResponse
    {
        $e = app(EstadiaPdvController::class)->buscar($r);
        $l = app(LavagemPdvController::class)->buscar($r);
        $achados = [];
        if ($e->status() === 200) {
            $x = $e->getData(true)['estadia'];
            $achados[] = ['tipo' => 'estadia', 'id' => $x['id'], 'numero' => $x['numero'], 'placa' => $x['rotulo'], 'detalhe' => 'Estadia · entrou '.substr((string) $x['entrada'], 11, 5)];
        }
        if ($l->status() === 200) {
            $x = $l->getData(true)['lavagem'];
            $achados[] = ['tipo' => 'lavagem', 'id' => $x['id'], 'numero' => $x['numero'], 'placa' => $x['placa'], 'detalhe' => 'Lavagem · '.($x['etapa_nome'] ?? '')];
        }
        if (! $achados) {
            // estadia já finalizada/cancelada diz isso; senão, a mensagem geral
            $msg = $e->getData(true)['erro'] ?? '';
            if (! str_starts_with($msg, 'Este ticket')) {
                $msg = 'Nenhum ticket aberto com esse QR, número ou placa.';
            }

            return response()->json(['erro' => $msg], 404);
        }

        return response()->json(['achados' => $achados]);
    }
}
