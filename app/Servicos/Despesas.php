<?php

namespace App\Servicos;

use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\DespesaRecorrente;
use App\Models\FormaPagamento;
use App\Models\Fornecedor;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contas a pagar: lançar (com parcelas), alterar enquanto está a pagar, pagar (com o valor de fato,
 * a forma e a conta de onde saiu), desfazer pagamento e cancelar. Nada é apagado.
 * Contas fixas (recorrentes) geram uma conta por mês, até o mês seguinte ao atual.
 */
class Despesas
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    private static function validarRefs(array $d): void
    {
        if (! PlanoContas::lancavel((int) ($d['conta_id'] ?? 0))) {
            self::erro('conta_id', 'Escolha uma conta de despesa do plano de contas.');
        }
        if (! empty($d['fornecedor_id']) && ! Fornecedor::find($d['fornecedor_id'])) {
            self::erro('fornecedor_id', 'Fornecedor não encontrado.');
        }
    }

    private static function guardarComprovante(?UploadedFile $f): ?array
    {
        if (! $f) {
            return null;
        }
        $caminho = $f->storeAs('comprovantes/'.now()->format('Y/m'), Str::uuid().'.'.strtolower($f->getClientOriginalExtension() ?: 'pdf'), 'local');

        return ['comprovante' => $caminho, 'comprovante_nome' => mb_substr($f->getClientOriginalName(), 0, 150)];
    }

    /**
     * $d: descricao, fornecedor_id, conta_id, competencia (Y-m), vencimento, valor, parcelas,
     * observacao; pago (bool) + pago_em, forma_pagamento_id, conta_financeira_id. Devolve as contas criadas.
     *
     * @return Despesa[]
     */
    public static function lancar(array $d, ?UploadedFile $comprovante = null): array
    {
        self::validarRefs($d);
        $parcelas = max(1, min(60, (int) ($d['parcelas'] ?? 1)));
        $total = round((float) $d['valor'], 2);
        $base = floor($total / $parcelas * 100) / 100;
        $venc = Carbon::parse($d['vencimento']);
        $comp = Carbon::createFromFormat('Y-m', $d['competencia'] ?? $venc->format('Y-m'))->startOfMonth();
        $arquivo = self::guardarComprovante($comprovante);

        $criadas = DB::transaction(function () use ($d, $parcelas, $total, $base, $venc, $comp, $arquivo) {
            $grupo = $parcelas > 1 ? (string) Str::uuid() : null;
            $lista = [];
            for ($i = 0; $i < $parcelas; $i++) {
                $valor = $i === $parcelas - 1 ? round($total - $base * ($parcelas - 1), 2) : $base;
                $lista[] = Despesa::create([
                    'unidade_id' => Ordens::unidadePadrao(),
                    'fornecedor_id' => $d['fornecedor_id'] ?? null,
                    'conta_id' => $d['conta_id'],
                    'descricao' => mb_substr(trim($d['descricao']), 0, 120),
                    'competencia' => $comp->copy()->addMonthsNoOverflow($i),
                    'vencimento' => $venc->copy()->addMonthsNoOverflow($i),
                    'valor' => $valor,
                    'grupo' => $grupo, 'parcela' => $grupo ? $i + 1 : null, 'parcelas' => $grupo ? $parcelas : null,
                    'observacao' => $d['observacao'] ?? null,
                    'criado_por' => Auth::id(),
                ] + ($i === 0 && $arquivo ? $arquivo : []));
            }

            return $lista;
        });
        Atividades::registrar('despesa_lancada', $criadas[0]->descricao.' · '.Formato::moeda($total).($parcelas > 1 ? " em {$parcelas}x" : ''));
        if (! empty($d['pago'])) {
            self::pagar($criadas[0], $d);
        }

        return $criadas;
    }

    /** altera uma conta que ainda está a pagar */
    public static function alterar(Despesa $x, array $d, ?UploadedFile $comprovante = null): void
    {
        if ($x->situacao !== 'aberta') {
            self::erro('valor', 'Só dá para alterar conta a pagar. Para conta paga, desfaça o pagamento antes.');
        }
        self::validarRefs($d);
        $antes = Formato::moeda($x->valor).' venc. '.$x->vencimento->format('d/m/Y');
        $arquivo = self::guardarComprovante($comprovante);
        $x->update([
            'fornecedor_id' => $d['fornecedor_id'] ?? null, 'conta_id' => $d['conta_id'],
            'descricao' => mb_substr(trim($d['descricao']), 0, 120),
            'competencia' => Carbon::createFromFormat('Y-m', $d['competencia'])->startOfMonth(),
            'vencimento' => $d['vencimento'], 'valor' => round((float) $d['valor'], 2),
            'observacao' => $d['observacao'] ?? null,
        ] + ($arquivo ?? []));
        Atividades::registrar('despesa_alterada', $x->descricao.' · de '.$antes.' para '.Formato::moeda($x->valor).' venc. '.$x->vencimento->format('d/m/Y'));
    }

    /** $d: pago_em, valor_pago (opcional: igual ao valor), forma_pagamento_id, conta_financeira_id */
    public static function pagar(Despesa $x, array $d, ?UploadedFile $comprovante = null): void
    {
        if ($x->situacao !== 'aberta') {
            self::erro('pago_em', 'Esta conta já foi paga ou cancelada.');
        }
        $forma = ! empty($d['forma_pagamento_id']) ? FormaPagamento::find($d['forma_pagamento_id']) : null;
        if (! $forma) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }
        $conta = ! empty($d['conta_financeira_id']) ? ContaFinanceira::find($d['conta_financeira_id']) : $forma->conta;
        if (! $conta) {
            self::erro('conta_financeira_id', 'Escolha de onde saiu o dinheiro.');
        }
        $quando = Carbon::parse($d['pago_em'] ?? now());
        if ($quando->isAfter(now()->endOfDay())) {
            self::erro('pago_em', 'A data do pagamento não pode ser no futuro.');
        }
        $valor = isset($d['valor_pago']) && $d['valor_pago'] !== '' && $d['valor_pago'] !== null ? round((float) $d['valor_pago'], 2) : (float) $x->valor;
        if ($valor <= 0) {
            self::erro('valor_pago', 'Informe o valor pago.');
        }
        $arquivo = self::guardarComprovante($comprovante);
        $x->update(['situacao' => 'paga', 'pago_em' => $quando->toDateString(), 'valor_pago' => $valor,
            'forma_pagamento_id' => $forma->id, 'conta_financeira_id' => $conta->id, 'pago_por' => Auth::id()] + ($arquivo ?? []));
        Atividades::registrar('despesa_paga', $x->descricao.' · '.Formato::moeda($valor).' em '.$quando->format('d/m/Y').' ('.$forma->nome.')');
    }

    public static function desfazerPagamento(Despesa $x, string $motivo): void
    {
        if ($x->situacao !== 'paga') {
            self::erro('motivo', 'Esta conta não está paga.');
        }
        $antes = Formato::moeda($x->valor_pago).' em '.$x->pago_em->format('d/m/Y');
        $x->update(['situacao' => 'aberta', 'pago_em' => null, 'valor_pago' => null, 'forma_pagamento_id' => null,
            'conta_financeira_id' => null, 'pago_por' => null]);
        Atividades::registrar('despesa_pagamento_desfeito', $x->descricao.' · '.$antes.' ('.$motivo.')');
    }

    public static function cancelar(Despesa $x, string $motivo): void
    {
        if ($x->situacao !== 'aberta') {
            self::erro('motivo', $x->situacao === 'paga' ? 'Conta paga: desfaça o pagamento antes de cancelar.' : 'Esta conta já está cancelada.');
        }
        $x->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr($motivo, 0, 200)]);
        Atividades::registrar('despesa_cancelada', $x->descricao.' · '.Formato::moeda($x->valor).' ('.$motivo.')');
    }

    /**
     * Gera as contas das fixas até o mês seguinte ao de $hoje (uma por mês, sem repetir).
     * Não volta para meses antes da criação da fixa.
     */
    public static function gerarRecorrentes(?CarbonInterface $hoje = null): int
    {
        $ate = ($hoje ?? now())->copy()->startOfMonth()->addMonthNoOverflow();
        $n = 0;
        foreach (DespesaRecorrente::where('ativa', true)->get() as $r) {
            $mes = $r->inicio->copy()->startOfMonth();
            $criada = ($r->criado_em ?? now())->copy()->startOfMonth();
            if ($mes->lt($criada)) {
                $mes = $criada;
            }
            $existentes = Despesa::where('recorrente_id', $r->id)->pluck('competencia')->map(fn ($c) => $c->format('Y-m'))->all();
            for (; $mes->lte($ate); $mes->addMonthNoOverflow()) {
                if ($r->fim && $mes->gt($r->fim->copy()->startOfMonth())) {
                    break;
                }
                if (in_array($mes->format('Y-m'), $existentes, true)) {
                    continue;
                }
                $venc = $mes->copy()->day(min($r->dia_vencimento, $mes->daysInMonth));
                Despesa::create([
                    'unidade_id' => $r->unidade_id ?? Ordens::unidadePadrao(), 'fornecedor_id' => $r->fornecedor_id,
                    'conta_id' => $r->conta_id, 'recorrente_id' => $r->id, 'descricao' => $r->descricao,
                    'competencia' => $mes->copy(), 'vencimento' => $venc, 'valor' => $r->valor,
                ]);
                $n++;
            }
        }

        return $n;
    }

    /** resumo para o Início e a tela: atrasadas, vence hoje, próximos 7 dias, pago no mês */
    public static function resumo(): array
    {
        $hoje = now()->toDateString();
        $abertas = Despesa::where('situacao', 'aberta');
        $soma = fn ($q) => ['qtd' => (clone $q)->count(), 'valor' => round((float) (clone $q)->sum('valor'), 2)];

        return [
            'atrasadas' => $soma((clone $abertas)->where('vencimento', '<', $hoje)),
            'hoje' => $soma((clone $abertas)->where('vencimento', $hoje)),
            'semana' => $soma((clone $abertas)->where('vencimento', '>', $hoje)->where('vencimento', '<=', now()->addDays(7)->toDateString())),
            'pago_mes' => round((float) Despesa::where('situacao', 'paga')->whereBetween('pago_em', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('valor_pago'), 2),
        ];
    }
}
