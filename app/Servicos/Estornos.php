<?php

namespace App\Servicos;

use App\Models\Cliente;
use App\Models\Estorno;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estorno ao cliente: devolve o crédito que ele tem (pagou a mais, pagou o boleto depois de a
 * cobrança ser cancelada...). O dinheiro sai pelo app do banco; aqui o crédito é baixado e a saída
 * entra no fluxo de caixa e no resultado pelo olhar de caixa ("(−) Estornos a clientes").
 */
class Estornos
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** crédito líquido que dá para estornar: o que sobra a favor do cliente depois das contas em aberto */
    public static function disponivel(Cliente $cliente): float
    {
        return round(max(0, -Baixa::totalAberto($cliente)), 2);
    }

    /** $d: valor, estornado_em (Y-m-d), forma_pagamento_id, motivo */
    public static function estornar(Cliente $cliente, array $d): Estorno
    {
        return DB::transaction(function () use ($cliente, $d) {
            Cliente::whereKey($cliente->id)->lockForUpdate()->first();
            $valor = round((float) ($d['valor'] ?? 0), 2);
            $livre = self::disponivel($cliente);
            if ($valor <= 0) {
                self::erro('valor', 'Informe o valor do estorno.');
            }
            if ($livre <= 0) {
                self::erro('valor', 'O cliente não tem crédito para estornar.');
            }
            if ($valor > $livre) {
                self::erro('valor', 'O estorno passa do crédito do cliente ('.Formato::moeda($livre).').');
            }
            $dia = Carbon::parse($d['estornado_em'] ?? today());
            if ($dia->isAfter(today())) {
                self::erro('estornado_em', 'A data do estorno não pode ser no futuro.');
            }
            $forma = ! empty($d['forma_pagamento_id']) ? FormaPagamento::find($d['forma_pagamento_id']) : null;
            $motivo = trim((string) ($d['motivo'] ?? ''));
            if ($motivo === '') {
                self::erro('motivo', 'Informe o motivo do estorno.');
            }

            // usa os créditos mais antigos primeiro; a sobra do último vira um crédito novo
            $creditos = OrdemServico::abertas()->where('cliente_id', $cliente->id)->where('tipo', 'credito')->orderBy('data')->orderBy('id')->lockForUpdate()->get();
            $falta = $valor;
            $usados = [];
            $sobra = 0.0;
            foreach ($creditos as $c) {
                if ($falta <= 0) {
                    break;
                }
                $usados[] = $c->id;
                $c->update(['situacao' => 'paga', 'pago_em' => $dia]);
                $sobra = round((float) $c->valor - $falta, 2);
                $falta = round($falta - (float) $c->valor, 2);
            }
            $restante = null;
            if ($sobra > 0) {
                $restante = Ordens::lancar(['tipo' => 'credito', 'cliente_id' => $cliente->id, 'valor' => $sobra, 'descricao' => 'Crédito (restante após estorno)',
                    'data' => $dia->toDateString()]);
            }
            $e = Estorno::create([
                'cliente_id' => $cliente->id, 'valor' => $valor, 'estornado_em' => $dia->toDateString(),
                'forma_pagamento_id' => $forma?->id, 'conta_financeira_id' => $forma?->conta_financeira_id,
                'motivo' => mb_substr($motivo, 0, 200), 'creditos' => $usados, 'credito_restante_id' => $restante?->id, 'criado_por' => Auth::id(),
            ]);
            Atividades::registrar('estorno_cliente', 'cliente '.$cliente->id.' ('.$cliente->nome.'): estorno de '.Formato::moeda($valor)
                .($forma ? ' por '.$forma->nome : '').', '.$dia->format('d/m/Y').' · '.$e->motivo);

            return $e;
        });
    }

    /** desfaz um estorno lançado errado: o crédito volta para o cliente */
    public static function desfazer(Estorno $e, string $motivo): void
    {
        DB::transaction(function () use ($e, $motivo) {
            $e = Estorno::whereKey($e->id)->lockForUpdate()->first();
            if ($e->situacao !== 'feito') {
                self::erro('motivo', 'Este estorno já foi desfeito.');
            }
            if ($e->credito_restante_id) {
                $r = OrdemServico::find($e->credito_restante_id);
                if ($r && $r->situacao !== 'aberta') {
                    self::erro('motivo', 'O crédito que sobrou deste estorno já foi usado. Desfaça aquele uso primeiro.');
                }
                $r?->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => 'Estorno desfeito']);
            }
            OrdemServico::whereIn('id', $e->creditos ?? [])->update(['situacao' => 'aberta', 'pago_em' => null]);
            $e->update(['situacao' => 'desfeito', 'desfeito_em' => now(), 'desfeito_por' => Auth::id(), 'motivo_desfeito' => mb_substr($motivo, 0, 200)]);
            Atividades::registrar('estorno_desfeito', 'cliente '.$e->cliente_id.': estorno de '.Formato::moeda((float) $e->valor).' desfeito · '.$motivo);
        });
    }
}
