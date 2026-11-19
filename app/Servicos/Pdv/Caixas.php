<?php

namespace App\Servicos\Pdv;

use App\Models\Caixa;
use App\Models\CaixaMovimento;
use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\PlanoConta;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\PlanoContas;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Caixa do balcão (PDV, parte 8.3). Só o que passa pelo PDV entra aqui: recebimentos feitos no celular e os
 * movimentos da gaveta. Um caixa aberto por vez; o fechamento (do gerente) abre o próximo com o troco deixado.
 * Ver docs/15-plano-pdv.md (Caixa).
 */
class Caixas
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** o caixa aberto; se não houver, abre com o troco que ficou no último fechamento */
    public static function atual(): Caixa
    {
        $c = Caixa::where('situacao', 'aberto')->orderBy('id')->first();
        if ($c) {
            return $c;
        }
        $ultimo = Caixa::where('situacao', 'fechado')->orderByDesc('id')->first();

        return Caixa::create(['unidade_id' => Ordens::unidadePadrao(), 'aberto_em' => now(), 'aberto_por' => Auth::id(),
            'troco_inicial' => (float) ($ultimo?->troco_deixado ?? 0)]);
    }

    /**
     * Resumo do caixa: recebido por serviço e por forma, movimentos da gaveta e o dinheiro que deveria ter.
     * Caixa fechado: o retrato guardado no fechamento.
     */
    public static function resumo(Caixa $c): array
    {
        if ($c->situacao === 'fechado' && $c->resumo) {
            return $c->resumo;
        }
        $pags = DB::table('pagamentos as p')->leftJoin('formas_pagamento as f', 'f.id', '=', 'p.forma_pagamento_id')
            ->where('p.caixa_id', $c->id)->where('p.situacao', 'confirmado')
            ->get(['p.id', 'p.valor', 'p.pdv_tipo', 'f.nome as forma', 'f.tipo as forma_tipo', 'f.ordem']);
        $porForma = $pags->groupBy('forma')->map(fn ($g, $nome) => ['forma' => $nome ?: 'Não informada', 'tipo' => $g->first()->forma_tipo,
            'qtd' => $g->count(), 'total' => round($g->sum('valor'), 2), 'ordem' => (int) $g->first()->ordem])->sortBy('ordem')->values()
            ->map(fn ($l) => collect($l)->except('ordem')->all())->all();

        // conta de cliente recebida no balcão: uma linha só (mensalidades e serviços que estavam na conta, de outros dias);
        // o resto, pelo serviço feito no dia
        $contas = $pags->where('pdv_tipo', 'conta');
        $itens = DB::table('pagamento_itens as i')->join('ordens_servico as o', 'o.id', '=', 'i.ordem_id')
            ->whereIn('i.pagamento_id', $pags->where('pdv_tipo', '<>', 'conta')->pluck('id'))->get(['o.id', 'o.tipo', 'i.valor']);
        $nomes = ['estadia' => 'Estadias', 'servico' => 'Lavagens e serviços'];
        $porServico = [['servico' => 'Contas de clientes', 'qtd' => $contas->count(), 'total' => round($contas->sum('valor'), 2)]];
        foreach (['estadia', 'servico'] as $t) {
            $g = $itens->where('tipo', $t);
            $porServico[] = ['servico' => $nomes[$t], 'qtd' => $g->pluck('id')->unique()->count(), 'total' => round($g->sum('valor'), 2)];
        }
        $outros = $itens->whereNotIn('tipo', ['estadia', 'servico', 'credito']);
        if ($outros->isNotEmpty()) {
            $porServico[] = ['servico' => 'Outros', 'qtd' => $outros->pluck('id')->unique()->count(), 'total' => round($outros->sum('valor'), 2)];
        }
        $creditos = $itens->where('tipo', 'credito');
        if ($creditos->isNotEmpty()) {
            $porServico[] = ['servico' => 'Crédito do cliente usado', 'qtd' => $creditos->pluck('id')->unique()->count(), 'total' => round($creditos->sum('valor'), 2)];
        }

        $movs = $c->movimentos()->with(['usuario:id,nome', 'contaFinanceira:id,nome', 'planoConta:id,nome'])->get();
        $dinheiro = round($pags->where('forma_tipo', 'dinheiro')->sum('valor'), 2);
        $soma = fn (string $tipo) => round($movs->where('tipo', $tipo)->sum(fn ($m) => (float) $m->valor), 2);
        $esperado = round((float) $c->troco_inicial + $dinheiro + $soma('suprimento') - $soma('sangria') - $soma('pagamento') - $soma('retirada'), 2);

        return [
            'id' => $c->id,
            'situacao' => $c->situacao,
            'aberto_em' => $c->aberto_em->format('Y-m-d H:i'),
            'aberto_por' => $c->abertoPor?->nome,
            'troco_inicial' => (float) $c->troco_inicial,
            'por_servico' => $porServico,
            'total_recebido' => round($pags->sum('valor'), 2),
            'qtd_recebimentos' => $pags->count(),
            'por_forma' => $porForma,
            'dinheiro_recebido' => $dinheiro,
            'suprimentos' => $soma('suprimento'),
            'sangrias' => $soma('sangria'),
            'pagamentos' => $soma('pagamento'),
            'retiradas' => $soma('retirada'),
            'movimentos' => $movs->map(fn (CaixaMovimento $m) => ['id' => $m->id, 'tipo' => $m->tipo, 'rotulo' => $m->rotulo(), 'valor' => (float) $m->valor,
                'sinal' => $m->sinal(), 'motivo' => $m->motivo, 'quem' => $m->usuario?->nome, 'hora' => $m->criado_em?->format('d/m H:i')])->values()->all(),
            'dinheiro_esperado' => $esperado,
            // Pix pago no QR que não virou recebimento (app fechou, internet caiu): resolver pela gestão
            'pix_sem_uso' => PixPdv::semUso($c->id)->map(fn ($p) => ['valor' => (float) $p->valor_pago, 'hora' => $p->pago_em?->format('d/m H:i'),
                'pagador' => $p->pagador, 'descricao' => $p->descricao])->values()->all(),
        ];
    }

    /** categorias de despesa para o pagamento pelo caixa: as mais usadas primeiro, depois as outras (só as que recebem lançamento) */
    public static function categorias(): array
    {
        PlanoContas::garantirPadrao();
        $contas = PlanoConta::where('tipo', 'despesa')->where('ativa', true)->orderBy('ordem')->get(['id', 'nome', 'pai_id', 'chave']);
        $temFilhas = $contas->pluck('pai_id')->filter()->unique()->flip();
        $folhas = $contas->filter(fn ($c) => ! isset($temFilhas[$c->id]) && $c->pai_id !== null && $c->chave !== 'despesa_cartao');
        $uso = CaixaMovimento::where('tipo', 'pagamento')->whereNotNull('plano_conta_id')->groupBy('plano_conta_id')
            ->selectRaw('plano_conta_id, COUNT(*) AS n')->pluck('n', 'plano_conta_id');

        return $folhas->map(fn ($c) => ['id' => $c->id, 'nome' => $c->nome, 'uso' => (int) ($uso[$c->id] ?? 0)])
            ->sortByDesc('uso')->values()->all();
    }

    /**
     * Movimento da gaveta. $d: tipo (suprimento, sangria, pagamento, retirada), valor, motivo, destino
     * (sangria: cofre ou banco + conta_financeira_id), plano_conta_id + descricao (pagamento).
     * Pagamento vira despesa paga em dinheiro (aparece em Contas a pagar e no Resultado).
     */
    public static function movimentar(array $d): CaixaMovimento
    {
        $tipo = $d['tipo'] ?? '';
        $valor = round((float) ($d['valor'] ?? 0), 2);
        if (! isset(CaixaMovimento::TIPOS[$tipo])) {
            self::erro('tipo', 'Escolha o tipo.');
        }
        if ($valor <= 0) {
            self::erro('valor', 'Informe o valor.');
        }
        $motivo = mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 200) ?: null;
        $descricao = mb_substr(trim((string) ($d['descricao'] ?? '')), 0, 120) ?: null;
        if (in_array($tipo, ['suprimento', 'sangria'], true) && ! $motivo) {
            self::erro('motivo', 'Informe o motivo.');
        }

        return DB::transaction(function () use ($tipo, $valor, $motivo, $descricao, $d) {
            $c = Caixa::whereKey(self::atual()->id)->lockForUpdate()->first();
            if ($tipo !== 'suprimento') {
                $tem = self::resumo($c)['dinheiro_esperado'];
                if ($valor > $tem + 0.001) {
                    self::erro('valor', 'O caixa deveria ter só '.Formato::moeda($tem).' em dinheiro.');
                }
            }
            $dados = ['caixa_id' => $c->id, 'tipo' => $tipo, 'data' => now()->toDateString(), 'valor' => $valor, 'motivo' => $motivo,
                'usuario_id' => Auth::id(), 'aparelho_id' => request()?->attributes->get('aparelho')?->id];
            if ($tipo === 'sangria') {
                $destino = ($d['destino'] ?? 'cofre') === 'banco' ? 'banco' : 'cofre';
                $conta = null;
                if ($destino === 'banco') {
                    $conta = ContaFinanceira::where('ativa', true)->where('tipo', 'banco')->find((int) ($d['conta_financeira_id'] ?? 0))
                        ?? self::erro('conta_financeira_id', 'Escolha a conta do banco.');
                }
                $dados += ['destino' => $destino, 'conta_financeira_id' => $conta?->id];
            }
            if ($tipo === 'pagamento') {
                $categoria = collect(self::categorias())->firstWhere('id', (int) ($d['plano_conta_id'] ?? 0))
                    ?? self::erro('plano_conta_id', 'Escolha o destino do pagamento.');
                FormasPagamento::garantirPadrao();
                $dinheiro = FormaPagamento::where('tipo', 'dinheiro')->first();
                $hoje = now();
                $desp = Despesa::create([
                    'unidade_id' => $c->unidade_id, 'conta_id' => $categoria['id'], 'descricao' => $descricao ?: $categoria['nome'].' (caixa do PDV)',
                    'competencia' => $hoje->copy()->startOfMonth()->toDateString(), 'vencimento' => $hoje->toDateString(), 'valor' => $valor,
                    'situacao' => 'paga', 'pago_em' => $hoje->toDateString(), 'valor_pago' => $valor, 'forma_pagamento_id' => $dinheiro?->id,
                    'conta_financeira_id' => $dinheiro?->conta_financeira_id, 'observacao' => 'Pago com o dinheiro do caixa do PDV'.($motivo ? ': '.$motivo : ''),
                    'criado_por' => Auth::id(), 'pago_por' => Auth::id(),
                ]);
                $dados += ['plano_conta_id' => $categoria['id'], 'despesa_id' => $desp->id, 'descricao' => $descricao];
            }
            $m = CaixaMovimento::create($dados);
            Atividades::registrar('caixa_movimento', $m->rotulo().' '.Formato::moeda($valor).($motivo ? ' · '.$motivo : ''));

            return $m;
        });
    }

    /** fecha o caixa (gerente): guarda o retrato, a diferença e abre o próximo com o troco deixado */
    public static function fechar(float $contado, float $trocoDeixado, ?string $observacao = null): Caixa
    {
        $contado = round($contado, 2);
        $trocoDeixado = round($trocoDeixado, 2);
        if ($contado < 0 || $trocoDeixado < 0) {
            self::erro('dinheiro_contado', 'Valores não podem ser negativos.');
        }
        if ($trocoDeixado > $contado + 0.001) {
            self::erro('troco_deixado', 'O troco deixado não pode ser maior que o dinheiro contado.');
        }

        return DB::transaction(function () use ($contado, $trocoDeixado, $observacao) {
            $c = Caixa::whereKey(self::atual()->id)->lockForUpdate()->first();
            $r = self::resumo($c);
            $dif = round($contado - $r['dinheiro_esperado'], 2);
            $agora = now();
            $r = array_merge($r, ['situacao' => 'fechado', 'fechado_em' => $agora->format('Y-m-d H:i'), 'fechado_por' => Auth::user()?->nome,
                'dinheiro_contado' => $contado, 'diferenca' => $dif, 'troco_deixado' => $trocoDeixado]);
            $c->update(['situacao' => 'fechado', 'fechado_em' => $agora, 'fechado_por' => Auth::id(),
                'fechado_aparelho_id' => request()?->attributes->get('aparelho')?->id, 'dinheiro_esperado' => $r['dinheiro_esperado'],
                'dinheiro_contado' => $contado, 'diferenca' => $dif, 'troco_deixado' => $trocoDeixado,
                'observacao' => $observacao ? mb_substr(trim($observacao), 0, 255) : null, 'resumo' => $r]);
            Caixa::create(['unidade_id' => $c->unidade_id, 'aberto_em' => $agora, 'aberto_por' => Auth::id(), 'troco_inicial' => $trocoDeixado]);
            Atividades::registrar('caixa_fechado', 'caixa '.$c->id.': esperado '.Formato::moeda($r['dinheiro_esperado']).', contado '.Formato::moeda($contado)
                .', diferença '.Formato::moeda($dif).', troco deixado '.Formato::moeda($trocoDeixado));

            return $c;
        });
    }

    /** retirada do sócio registrada na gestão (saiu do banco, não da gaveta) */
    public static function retiradaGestao(array $d): CaixaMovimento
    {
        $valor = round((float) ($d['valor'] ?? 0), 2);
        if ($valor <= 0) {
            self::erro('valor', 'Informe o valor.');
        }
        $conta = ContaFinanceira::where('ativa', true)->find((int) ($d['conta_financeira_id'] ?? 0)) ?? self::erro('conta_financeira_id', 'Escolha de onde saiu.');
        $m = CaixaMovimento::create(['tipo' => 'retirada', 'data' => $d['data'] ?? now()->toDateString(), 'valor' => $valor, 'conta_financeira_id' => $conta->id,
            'motivo' => mb_substr(trim((string) ($d['motivo'] ?? '')), 0, 200) ?: null, 'usuario_id' => Auth::id()]);
        Atividades::registrar('caixa_movimento', 'retirada do sócio '.Formato::moeda($valor).' ('.$conta->nome.')');

        return $m;
    }

    /** total das retiradas dos sócios entre duas datas (entra no Resultado abaixo do lucro e no fluxo de caixa) */
    public static function retiradas(string $de, string $ate): float
    {
        return round((float) CaixaMovimento::where('tipo', 'retirada')->whereBetween('data', [$de, $ate])->sum('valor'), 2);
    }

    /** contas em aberto do cliente para receber no balcão (as mais antigas primeiro) */
    public static function contasDoCliente(int $clienteId): array
    {
        return OrdemServico::abertas()->where('cliente_id', $clienteId)->orderByRaw('COALESCE(vencimento, data)')->orderBy('numero')->get()
            ->map(fn (OrdemServico $o) => ['id' => $o->id, 'numero' => $o->numero, 'tipo' => $o->tipo, 'descricao' => $o->descricao,
                'placa' => $o->placa ? Placa::formatar($o->placa) : null, 'vencimento' => $o->vencimento?->format('d/m/Y'),
                'valor' => round($o->valorComSinal(), 2), 'credito' => $o->tipo === 'credito',
                'vencida' => $o->tipo !== 'credito' && $o->dataReferencia() < now()->toDateString()])->values()->all();
    }
}
