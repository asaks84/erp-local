<?php

namespace App\Servicos;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\PagamentoItem;
use App\Models\Servico;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Baixa unificada: recebe um pagamento e dá baixa nas ordens escolhidas. Usada por receber contas,
 * extrato e, depois, PDV e Inter. Créditos em aberto do cliente sempre abatem.
 * Diferença: sobra vira crédito (ou um serviço feito na hora, já pago); falta vira débito ou desconto.
 * Ver docs/05-regras-de-negocio.md (Baixa e extrato).
 */
class Baixa
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** ordens em aberto do cliente, mais antigas primeiro */
    public static function abertas(Cliente $cliente)
    {
        return OrdemServico::abertas()->where('cliente_id', $cliente->id)
            ->orderByRaw('COALESCE(vencimento, data)')->orderBy('numero')->get();
    }

    /** total em aberto do cliente = itens em aberto − créditos */
    public static function totalAberto(Cliente $cliente): float
    {
        return round((float) OrdemServico::abertas()->where('cliente_id', $cliente->id)->sum(DB::raw(OrdemServico::SQL_VALOR)), 2);
    }

    /**
     * $d: ordens (ids), valor, forma_pagamento_id, recebido_em (data ou data e hora), parcelas,
     *     sobra: credito | servico | nada | encargos (+ encargos: valor da multa/juros; o resto vira crédito), falta: debito | desconto,
     *     sistema (baixa automática do Inter: desconto sem conferir o nível de acesso), servico_id (ou "estadia") + veiculo_id (sobra = serviço),
     *     origem, observacao, motivo (do desconto ou do débito), sem_ordens (extrato: aceita pagamento sem conta marcada).
     * Devolve [Pagamento, mensagem].
     */
    public static function receber(Cliente $cliente, array $d): array
    {
        $valor = round((float) ($d['valor'] ?? 0), 2);
        if ($valor < 0) {
            self::erro('valor', 'O valor recebido não pode ser negativo.');
        }
        $forma = FormaPagamento::find($d['forma_pagamento_id'] ?? 0);
        if (! $forma) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }
        $parcelas = max(1, min((int) ($d['parcelas'] ?? 1), max(1, $forma->parcelas_max)));
        $recebido = Carbon::parse($d['recebido_em'] ?? now());
        if ($recebido->isAfter(now()->endOfDay())) {
            self::erro('recebido_em', 'A data do pagamento não pode ser no futuro.');
        }
        if (strlen((string) ($d['recebido_em'] ?? '')) === 10) {
            // só a data: guarda a hora de agora se for hoje, senão meio-dia (sem hora conhecida)
            $recebido = $recebido->isToday() ? now() : $recebido->setTime(12, 0);
        }

        return DB::transaction(function () use ($cliente, $d, $valor, $forma, $parcelas, $recebido) {
            $escolhidas = array_map('intval', (array) ($d['ordens'] ?? []));
            $abertas = OrdemServico::abertas()->where('cliente_id', $cliente->id)->lockForUpdate()->get();
            $ordens = $abertas->filter(fn ($o) => $o->tipo === 'credito' || in_array($o->id, $escolhidas, true));
            // no extrato, um PIX de quem não tem contas em aberto vira crédito ou serviço feito na hora
            if (! $ordens->contains(fn ($o) => $o->tipo !== 'credito') && empty($d['sem_ordens'])) {
                self::erro('ordens', 'Marque as contas que foram pagas.');
            }
            if ($ordens->where('tipo', '<>', 'credito')->count() < count(array_unique($escolhidas))) {
                self::erro('ordens', 'Alguma conta marcada já foi paga ou cancelada. Abra a tela de novo.');
            }
            $total = round($ordens->sum(fn ($o) => $o->valorComSinal()), 2);
            $dif = round($valor - $total, 2);

            $sobra = $d['sobra'] ?? null;
            $falta = $d['falta'] ?? null;
            if ($dif > 0 && ! in_array($sobra, ['credito', 'servico', 'nada', 'encargos'], true)) {
                self::erro('sobra', 'Escolha o que fazer com os '.Formato::moeda($dif).' a mais.');
            }
            if ($dif < 0 && ! in_array($falta, ['debito', 'desconto'], true)) {
                self::erro('falta', 'Escolha o que fazer com os '.Formato::moeda(-$dif).' que faltaram.');
            }
            if ($dif < 0 && $falta === 'desconto' && empty($d['sistema']) && ! Auth::user()?->pode('desconto')) {
                self::erro('falta', 'Seu nível de acesso não permite dar desconto.');
            }

            $taxa = $forma->taxaSobre($valor, $parcelas);
            $pagamento = Pagamento::create([
                'unidade_id' => Ordens::unidadePadrao(),
                'uuid' => (string) Str::uuid(),
                'cliente_id' => $cliente->id,
                'forma_pagamento_id' => $forma->id,
                'conta_financeira_id' => $forma->conta_financeira_id,
                'parcelas' => $parcelas,
                'valor' => $valor,
                'taxa' => $taxa,
                'liquido' => round($valor - $taxa, 2),
                'previsto_para' => $forma->previsaoPara($recebido),
                'recebido_em' => $recebido,
                'origem' => $d['origem'] ?? 'gestao',
                'observacao' => isset($d['observacao']) ? mb_substr($d['observacao'], 0, 255) : null,
                'criado_por' => Auth::id(),
            ]);

            foreach ($ordens as $o) {
                PagamentoItem::create(['pagamento_id' => $pagamento->id, 'ordem_id' => $o->id, 'valor' => $o->valorComSinal()]);
                $o->update(['situacao' => 'paga', 'pago_em' => $recebido]);
            }

            $extra = '';
            $motivo = trim((string) ($d['motivo'] ?? ''));
            if ($dif > 0 && $sobra === 'encargos') {
                // multa e juros do boleto: receita lançada e já paga; o que passar disso vira crédito
                $enc = round(min($dif, max(0, (float) ($d['encargos'] ?? $dif))), 2);
                if ($enc > 0) {
                    $m = Ordens::lancar(['tipo' => 'debito', 'cliente_id' => $cliente->id, 'valor' => $enc, 'descricao' => 'Multa e juros do boleto',
                        'data' => $recebido->toDateString(), 'vencimento' => $recebido->toDateString(), 'situacao' => 'paga', 'pago_em' => $recebido,
                        'pagamento_origem_id' => $pagamento->id, 'origem' => $pagamento->origem]);
                    PagamentoItem::create(['pagamento_id' => $pagamento->id, 'ordem_id' => $m->id, 'valor' => $enc]);
                    $extra = 'multa e juros '.Formato::moeda($enc);
                }
                $dif = round($dif - $enc, 2);
                $sobra = $dif > 0 ? 'credito' : null;
                $motivo = $motivo ?: 'Pagou a mais';
            }
            if ($dif > 0 && $sobra === 'credito') {
                $c = Ordens::lancar(['tipo' => 'credito', 'cliente_id' => $cliente->id, 'valor' => $dif, 'descricao' => 'Crédito (pagou a mais)',
                    'data' => $recebido->toDateString(), 'pagamento_origem_id' => $pagamento->id, 'origem' => $pagamento->origem]);
                Ajuste::create(['tipo' => 'credito', 'cliente_id' => $cliente->id, 'pagamento_id' => $pagamento->id, 'ordem_id' => $c->id,
                    'valor' => $dif, 'motivo' => $motivo ?: 'Pagou a mais', 'criado_por' => Auth::id()]);
                $extra = ltrim($extra.' · crédito de '.Formato::moeda($dif), ' ·');
            } elseif ($dif > 0 && $sobra === 'servico') {
                // "serviço feito na hora": uma lavagem/serviço cadastrado ou uma estadia avulsa, já paga
                $estadia = ($d['servico_id'] ?? null) === 'estadia';
                $servico = $estadia ? null : Servico::find((int) ($d['servico_id'] ?? 0));
                $veiculo = ! empty($d['veiculo_id']) ? Veiculo::where('cliente_id', $cliente->id)->find($d['veiculo_id']) : null;
                if (! $estadia && ! $servico) {
                    self::erro('servico_id', 'Escolha o serviço feito na hora.');
                }
                if (! empty($d['veiculo_id']) && ! $veiculo) {
                    self::erro('veiculo_id', 'Escolha um veículo do cliente.');
                }
                $s = Ordens::lancar(['tipo' => $estadia ? 'estadia' : 'servico', 'cliente_id' => $cliente->id, 'veiculo_id' => $veiculo?->id, 'servico_id' => $servico?->id,
                    'valor' => $dif, 'descricao' => $estadia ? 'Estadia avulsa' : $servico->nome, 'data' => $recebido->toDateString(), 'vencimento' => $recebido->toDateString(),
                    'situacao' => 'paga', 'pago_em' => $recebido, 'pagamento_origem_id' => $pagamento->id, 'origem' => $pagamento->origem]);
                PagamentoItem::create(['pagamento_id' => $pagamento->id, 'ordem_id' => $s->id, 'valor' => $dif]);
                $extra = 'lançado e pago na hora: '.$s->descricao.' '.Formato::moeda($dif);
            } elseif ($dif > 0) {
                $extra = Formato::moeda($dif).' a mais sem lançamento';
            } elseif ($dif < 0 && $falta === 'debito') {
                $db = Ordens::lancar(['tipo' => 'debito', 'cliente_id' => $cliente->id, 'valor' => -$dif, 'descricao' => 'Débito (pagou a menos)',
                    'data' => $recebido->toDateString(), 'vencimento' => $recebido->toDateString(), 'pagamento_origem_id' => $pagamento->id, 'origem' => $pagamento->origem]);
                Ajuste::create(['tipo' => 'debito', 'cliente_id' => $cliente->id, 'pagamento_id' => $pagamento->id, 'ordem_id' => $db->id,
                    'valor' => -$dif, 'motivo' => $motivo ?: 'Pagou a menos', 'criado_por' => Auth::id()]);
                $extra = 'débito de '.Formato::moeda(-$dif);
            } elseif ($dif < 0) {
                Ajuste::create(['tipo' => 'desconto', 'cliente_id' => $cliente->id, 'pagamento_id' => $pagamento->id,
                    'valor' => -$dif, 'motivo' => $motivo ?: 'Desconto no recebimento', 'criado_por' => Auth::id()]);
                $extra = 'desconto de '.Formato::moeda(-$dif);
            }

            Cobrancas::atualizarSituacao($ordens->pluck('cobranca_id')->all());

            $n = $ordens->where('tipo', '<>', 'credito')->count();
            $msg = $n ? $n.' conta(s) baixada(s)' : 'sem contas em aberto';
            $resta = OrdemServico::abertas()->where('cliente_id', $cliente->id)->where('tipo', '<>', 'credito')->count();
            if ($resta) {
                $msg .= ' · '.$resta.' continua(m) em aberto';
            }
            $msg .= $extra ? ' · '.$extra : '';
            Atividades::registrar('pagamento_recebido', 'cliente '.$cliente->id.' ('.$cliente->nome.'): '.Formato::moeda($valor).' em '.$forma->nome
                .', '.$recebido->format('d/m/Y').' · ordens '.$ordens->pluck('numero')->map(fn ($n) => '#'.$n)->implode(', ').($extra ? ' · '.$extra : ''));

            return [$pagamento, $msg];
        });
    }

    /**
     * Pagamento na hora, no balcão (saída do pátio, lavagem avulsa): recebe exatamente o total das
     * ordens informadas, sem crédito, débito nem desconto. Serve para quem não tem cadastro.
     * O troco em dinheiro é coisa do caixa; aqui fica o valor das ordens.
     */
    public static function receberNaHora(array $ordens, int $formaId, int $parcelas = 1, string $origem = 'gestao'): Pagamento
    {
        $forma = FormaPagamento::find($formaId) ?? self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        $parcelas = max(1, min($parcelas, max(1, $forma->parcelas_max)));

        return DB::transaction(function () use ($ordens, $forma, $parcelas, $origem) {
            $lista = OrdemServico::whereIn('id', collect($ordens)->map(fn ($o) => $o instanceof OrdemServico ? $o->id : (int) $o))->lockForUpdate()->get();
            if ($lista->isEmpty() || $lista->contains(fn ($o) => $o->situacao !== 'aberta' || $o->tipo === 'credito')) {
                self::erro('ordens', 'Alguma conta já foi paga ou cancelada. Abra a tela de novo.');
            }
            $valor = round($lista->sum(fn ($o) => (float) $o->valor), 2);
            $agora = now();
            $taxa = $forma->taxaSobre($valor, $parcelas);
            $p = Pagamento::create([
                'unidade_id' => Ordens::unidadePadrao(),
                'uuid' => (string) Str::uuid(),
                'cliente_id' => $lista->pluck('cliente_id')->filter()->unique()->count() === 1 ? $lista->first(fn ($o) => $o->cliente_id)->cliente_id : null,
                'forma_pagamento_id' => $forma->id,
                'conta_financeira_id' => $forma->conta_financeira_id,
                'parcelas' => $parcelas,
                'valor' => $valor,
                'taxa' => $taxa,
                'liquido' => round($valor - $taxa, 2),
                'previsto_para' => $forma->previsaoPara($agora),
                'recebido_em' => $agora,
                'origem' => $origem,
                'criado_por' => Auth::id(),
            ]);
            foreach ($lista as $o) {
                PagamentoItem::create(['pagamento_id' => $p->id, 'ordem_id' => $o->id, 'valor' => (float) $o->valor]);
                $o->update(['situacao' => 'paga', 'pago_em' => $agora]);
            }
            Atividades::registrar('pagamento_recebido', 'na hora: '.Formato::moeda($valor).' em '.$forma->nome.' · ordens '.$lista->pluck('numero')->map(fn ($n) => '#'.$n)->implode(', '));

            return $p;
        });
    }

    /**
     * Estorno: o pagamento fica registrado como estornado e as ordens voltam a ficar em aberto.
     * Crédito, débito ou serviço nascidos dele são cancelados; não dá se o crédito já foi usado.
     */
    public static function estornar(Pagamento $p, string $motivo): void
    {
        if ($p->situacao !== 'confirmado') {
            self::erro('motivo', 'Este pagamento já foi estornado.');
        }
        if ($p->origem === 'importacao') {
            // até a virada, o pagamento importado é refeito a cada importação: o estorno é feito no sistema atual
            self::erro('motivo', 'Este pagamento veio do sistema atual. Até a virada, estorne por lá e importe de novo.');
        }
        DB::transaction(function () use ($p, $motivo) {
            $nascidas = OrdemServico::where('pagamento_origem_id', $p->id)->where('situacao', '<>', 'cancelada')->lockForUpdate()->get();
            $itens = $p->itens()->pluck('ordem_id')->all();
            foreach ($nascidas as $o) {
                if ($o->situacao === 'paga' && ! in_array($o->id, $itens)) {
                    self::erro('motivo', 'O '.mb_strtolower(OrdemServico::TIPOS[$o->tipo]).' #'.$o->numero.' gerado por este pagamento já foi usado em outro pagamento. Estorne aquele primeiro.');
                }
            }
            foreach ($nascidas as $o) {
                $o->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => 'Estorno do pagamento: '.mb_substr($motivo, 0, 170)]);
            }
            $voltam = OrdemServico::whereIn('id', $itens)->whereNotIn('id', $nascidas->pluck('id'))->get();
            foreach ($voltam as $o) {
                $o->update(['situacao' => 'aberta', 'pago_em' => null]);
            }
            $p->update(['situacao' => 'estornado', 'estornado_em' => now(), 'estornado_por' => Auth::id(), 'motivo_estorno' => mb_substr($motivo, 0, 200)]);
            Ajuste::create(['tipo' => 'estorno', 'cliente_id' => $p->cliente_id, 'pagamento_id' => $p->id, 'valor' => $p->valor, 'motivo' => $motivo, 'criado_por' => Auth::id()]);
            Cobrancas::atualizarSituacao($voltam->pluck('cobranca_id')->all());
        });
        Atividades::registrar('pagamento_estornado', 'pagamento '.$p->id.' de '.Formato::moeda($p->valor).' (cliente '.$p->cliente_id.'): '.$motivo);
    }
}
