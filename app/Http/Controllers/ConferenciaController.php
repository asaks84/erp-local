<?php

namespace App\Http\Controllers;

use App\Models\Conferencia;
use Inertia\Inertia;
use Inertia\Response;

/** Sistema › Conferência com o sistema atual: resultado de cada espelhamento do paralelo (portão da etapa 5). */
class ConferenciaController extends Controller
{
    public function __invoke(): Response
    {
        $ultima = Conferencia::latest('id')->first();
        $historico = Conferencia::latest('id')->limit(200)->get(['id', 'executada_em', 'situacao', 'diferencas', 'mensagem', 'duracao_seg']);
        // dias seguidos sem diferença (contando do mais recente para trás, só dias com conferência)
        $porDia = $historico->groupBy(fn ($c) => $c->executada_em->toDateString());
        $diasOk = 0;
        foreach ($porDia as $lista) {
            if ($lista->contains(fn ($c) => $c->situacao !== 'ok')) {
                break;
            }
            $diasOk++;
        }

        return Inertia::render('Sistema/Conferencia', [
            'ultima' => $ultima ? [
                'executada_em' => $ultima->executada_em->format('Y-m-d H:i'),
                'situacao' => $ultima->situacao,
                'linhas' => $ultima->linhas ?? [],
                'mensagem' => $ultima->mensagem,
            ] : null,
            'historico' => $historico->map(fn ($c) => [
                'id' => $c->id, 'executada_em' => $c->executada_em->format('Y-m-d H:i'), 'situacao' => $c->situacao,
                'diferencas' => $c->diferencas, 'mensagem' => $c->mensagem ? mb_substr($c->mensagem, 0, 160) : null, 'duracao_seg' => $c->duracao_seg,
            ]),
            'dias_ok' => $diasOk,
            'espelho_ativo' => (bool) config('erp.espelho_ativo'),
            'atrasada' => self::atrasada($ultima),
            'situacoes' => Conferencia::SITUACOES,
        ]);
    }

    /** espelho ligado, horário de funcionamento (9h às 23h59) e a última importação tem mais de 2 horas: o cron parou */
    public static function atrasada(?Conferencia $c): bool
    {
        $agora = now();

        return config('erp.espelho_ativo') && $agora->hour >= 9 && (! $c || $c->executada_em->lt($agora->copy()->subHours(2)));
    }

    /** resumo para o aviso do Início */
    public static function resumo(): ?array
    {
        $c = Conferencia::latest('id')->first();

        return $c ? ['quando' => $c->executada_em->format('Y-m-d H:i'), 'situacao' => $c->situacao, 'atrasada' => self::atrasada($c)] : null;
    }
}
