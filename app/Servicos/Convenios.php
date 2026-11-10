<?php

namespace App\Servicos;

use App\Models\Convenio;
use App\Support\Formato;

/**
 * Quem paga quanto com convênio (docs/05-regras-de-negocio.md, Convênio):
 * - tudo: o convênio paga tudo;
 * - tempo: o convênio paga a estadia até o tempo combinado e quem usou paga o excedente
 *   (em lavagens e serviços, quem usou paga o serviço inteiro);
 * - desconto: quem usou paga com desconto (% ou R$); o convênio não paga nada.
 */
class Convenios
{
    /**
     * @param  string  $uso  estadia ou servico
     * @param  callable|null  $valorAte  estadia no modo tempo: fn(int $minutos): float (valor do tarifador até esse tempo)
     * @return array{convenio: float, desconto: float, cliente: float, regra: string}
     */
    public static function dividir(?Convenio $c, string $uso, float $valor, int $minutos = 0, ?callable $valorAte = null): array
    {
        $valor = round($valor, 2);
        $nada = ['convenio' => 0.0, 'desconto' => 0.0, 'cliente' => $valor, 'regra' => ''];
        if (! $c || ! $c->ativo || ! ($uso === 'estadia' ? $c->vale_estadia : $c->vale_servicos) || $valor <= 0) {
            return $nada;
        }
        $nome = $c->cliente?->nome ?? 'Convênio';

        if ($c->modo === 'tudo') {
            return ['convenio' => $valor, 'desconto' => 0.0, 'cliente' => 0.0, 'regra' => "{$nome} paga tudo"];
        }
        if ($c->modo === 'tempo') {
            if ($uso !== 'estadia') {
                return ['regra' => "{$nome}: convênio por tempo não cobre serviços"] + $nada;
            }
            $limite = (int) $c->limite_min;
            $pago = $minutos <= $limite || ! $valorAte ? $valor : min($valor, round((float) $valorAte($limite), 2));

            return ['convenio' => $pago, 'desconto' => 0.0, 'cliente' => round($valor - $pago, 2),
                'regra' => "{$nome} paga até ".Formato::duracao($limite).($pago < $valor ? '; o excedente fica com quem usou' : '')];
        }
        // desconto
        $desc = $c->desconto_tipo === 'pct' ? round($valor * (float) $c->desconto_valor / 100, 2) : min($valor, round((float) $c->desconto_valor, 2));

        return ['convenio' => 0.0, 'desconto' => $desc, 'cliente' => round($valor - $desc, 2), 'regra' => "{$nome}: ".mb_strtolower($c->resumo())];
    }
}
