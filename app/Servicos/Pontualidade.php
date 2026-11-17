<?php

namespace App\Servicos;

use App\Models\Cobranca;
use App\Models\Empresa;
use App\Models\OrdemServico;
use App\Servicos\Inter\Boletos;
use Illuminate\Support\Carbon;

/**
 * Desconto de pontualidade fora do boleto (opção "vale para qualquer forma de pagamento", desligada de
 * início): o mesmo desconto das regras do boleto (só as mensalidades do mês, até o vencimento do cliente,
 * sem a primeira mensalidade, sem desconto negociado) aparece na cobrança impressa, na tela e no link,
 * e é aceito no balcão e no extrato sem precisar da permissão de desconto.
 */
class Pontualidade
{
    public const MOTIVO = 'Desconto de pontualidade';

    public static function ativa(?Empresa $e = null): bool
    {
        return (bool) (($e ?? Empresa::atual())->configuracoes['desconto_pontualidade_geral'] ?? false);
    }

    /**
     * Desconto da cobrança pago em $dia (hoje, se vazio): ['valor', 'ate' (Y-m-d), 'ordens' (ids das
     * mensalidades que dão o desconto)], ou null. Com boleto em aberto, vale o valor que foi para o boleto.
     */
    public static function daCobranca(Cobranca $c, Carbon|string|null $dia = null): ?array
    {
        if (! self::ativa() || ! in_array($c->situacao, ['aberta', 'parcial'], true) || (float) $c->desconto > 0) {
            return null;
        }
        $dia = Carbon::parse($dia ?? today())->startOfDay();
        $ate = $c->vencimento->copy()->startOfDay();
        if ($dia->gt($ate)) {
            return null;
        }
        $ordens = OrdemServico::abertas()->where('cobranca_id', $c->id)->where('tipo', 'mensalidade')
            ->where(fn ($q) => $q->whereNull('vencimento')->orWhere('vencimento', '>=', $dia->toDateString()))->get(['id', 'valor']);
        if ($ordens->isEmpty()) {
            return null;
        }
        $b = Boletos::ativo($c);
        if ($b) {
            $valor = ! empty($b->regras['sem_regras']) ? 0 : (float) ($b->regras['desconto_calculado'] ?? 0);
        } else {
            if (! Boletos::aplicarRegrasSugerido($c)) {
                return null; // primeira mensalidade ou proporcional: sem desconto
            }
            $valor = Boletos::descontoPontualidade($c, Boletos::regras(null, $c->cliente), $dia);
        }

        return $valor > 0 ? ['valor' => round($valor, 2), 'ate' => $ate->toDateString(), 'ordens' => $ordens->pluck('id')->all()] : null;
    }

    /** para as telas: ['valor', 'ate', 'a_pagar'] da cobrança hoje, ou null */
    public static function resumo(Cobranca $c): ?array
    {
        $p = self::daCobranca($c);

        return $p ? ['valor' => $p['valor'], 'ate' => $p['ate'], 'a_pagar' => round(Boletos::valorEmAberto($c) - $p['valor'], 2)] : null;
    }

    /**
     * Quanto de desconto de pontualidade cabe num recebimento: soma das cobranças cujas mensalidades
     * com desconto estão todas entre as contas marcadas, pagas até o vencimento.
     */
    public static function noRecebimento(iterable $ordens, Carbon|string $dia): float
    {
        if (! self::ativa()) {
            return 0.0;
        }
        $ids = collect($ordens)->pluck('id')->all();
        $total = 0.0;
        foreach (collect($ordens)->pluck('cobranca_id')->filter()->unique() as $cobId) {
            $c = Cobranca::with('cliente')->find($cobId);
            $p = $c ? self::daCobranca($c, $dia) : null;
            if ($p && ! array_diff($p['ordens'], $ids)) {
                $total += $p['valor'];
            }
        }

        return round($total, 2);
    }

    /** cobranças em aberto do cliente com desconto valendo hoje (para o balcão e o extrato) */
    public static function doCliente(int $clienteId): array
    {
        if (! self::ativa()) {
            return [];
        }

        return Cobranca::with('cliente')->where('cliente_id', $clienteId)->whereIn('situacao', ['aberta', 'parcial'])->get()
            ->map(fn ($c) => ($p = self::daCobranca($c)) ? ['cobranca' => $c->numero, 'valor' => $p['valor'], 'ate' => $p['ate'], 'ordens' => $p['ordens']] : null)
            ->filter()->values()->all();
    }
}
