<?php

namespace App\Servicos;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\CobrancaItem;
use App\Models\OrdemServico;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobrança: agrupa as ordens em aberto de um cliente, com número e vencimento.
 * Emitida, não muda: guarda a cópia dos itens. O que não for pago continua em aberto e entra
 * na próxima cobrança como "saldo anterior"; a antiga fica como parcialmente paga.
 * Ver docs/06-modulos.md (Cobrança agrupando as ordens de serviço).
 */
class Cobrancas
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** vencimento sugerido: o da mensalidade do mês; sem ela, o dia do cliente no mês */
    public static function vencimentoSugerido(Cliente $cliente, string $competencia, Collection $ordens): string
    {
        $mens = $ordens->where('tipo', 'mensalidade')->where('competencia', $competencia)->whereNotNull('vencimento')->max(fn ($o) => $o->vencimento->toDateString());

        return $mens ?: Mensalidades::vencimentoNoMes((int) $cliente->dia_vencimento, $competencia)->toDateString();
    }

    /** clientes com contas em aberto e sem cobrança no mês: prévia da emissão em lote */
    public static function previa(string $competencia): Collection
    {
        $comCobranca = Cobranca::where('competencia', $competencia)->where('situacao', '<>', 'cancelada')->pluck('cliente_id')->all();
        $porCliente = OrdemServico::abertas()->whereNotNull('cliente_id')->with('cliente')
            ->orderByRaw('COALESCE(vencimento, data)')->get()->groupBy('cliente_id');

        $linhas = collect();
        foreach ($porCliente as $clienteId => $ordens) {
            $total = round($ordens->sum(fn ($o) => $o->valorComSinal()), 2);
            if ($total <= 0) {
                continue;
            }
            $cliente = $ordens->first()->cliente;
            $ja = in_array((int) $clienteId, $comCobranca);
            $linhas->push([
                'cliente_id' => (int) $clienteId,
                'cliente' => $cliente->nome,
                'itens' => $ordens->where('tipo', '<>', 'credito')->count(),
                'mensalidade_do_mes' => $ordens->contains(fn ($o) => $o->tipo === 'mensalidade' && $o->competencia === $competencia),
                'saldo_anterior' => $ordens->contains(fn ($o) => $o->cobranca_id !== null),
                'total' => $total,
                'vencimento' => self::vencimentoSugerido($cliente, $competencia, $ordens),
                'ja_emitida' => $ja,
                'marcar' => ! $ja,
            ]);
        }

        return $linhas->sortBy('cliente')->values();
    }

    /** Emite a cobrança do mês para um cliente com todas as ordens em aberto. */
    /**
     * Desconto negociado na cobrança (só a unitária): fica gravado com motivo e quem deu; o total das
     * ordens não muda. Vale para o boleto (total em aberto menos o desconto). 0 tira o desconto.
     */
    public static function definirDesconto(Cobranca $cob, float $desconto, ?string $motivo = null): void
    {
        $desconto = round(max(0, $desconto), 2);
        if ($desconto === round((float) $cob->desconto, 2) && ($desconto == 0 || $motivo === $cob->desconto_motivo)) {
            return;
        }
        if (! in_array($cob->situacao, ['aberta', 'parcial'], true)) {
            self::erro('desconto', 'Só dá para dar desconto em cobrança em aberto.');
        }
        if (\App\Servicos\Inter\Boletos::ativo($cob)) {
            self::erro('desconto', 'A cobrança já tem boleto. Cancele o boleto para mudar o desconto.');
        }
        if ($desconto > 0 && ! Auth::user()?->pode('desconto')) {
            self::erro('desconto', 'Seu nível de acesso não permite dar desconto.');
        }
        if ($desconto > 0 && ! trim((string) $motivo)) {
            self::erro('desconto_motivo', 'Informe o motivo do desconto (ex.: negociado com o cliente).');
        }
        $aberto = \App\Servicos\Inter\Boletos::valorEmAberto($cob);
        if ($desconto >= $aberto) {
            self::erro('desconto', 'O desconto precisa ser menor que o valor em aberto ('.Formato::moeda($aberto).').');
        }
        $cob->update(['desconto' => $desconto, 'desconto_motivo' => $desconto > 0 ? mb_substr(trim($motivo), 0, 120) : null, 'desconto_por' => $desconto > 0 ? Auth::id() : null]);
        Atividades::registrar('cobranca_desconto', 'cobrança '.$cob->numero.': '.($desconto > 0 ? 'desconto de '.Formato::moeda($desconto).' ('.$cob->desconto_motivo.')' : 'desconto retirado'));
    }

    public static function emitir(Cliente $cliente, string $competencia, ?string $vencimento = null): Cobranca
    {
        if (! Mensalidades::competenciaValida($competencia)) {
            self::erro('competencia', 'Mês inválido.');
        }

        return DB::transaction(function () use ($cliente, $competencia, $vencimento) {
            Cliente::whereKey($cliente->id)->lockForUpdate()->first();
            if (Cobranca::where('cliente_id', $cliente->id)->where('competencia', $competencia)->where('situacao', '<>', 'cancelada')->exists()) {
                self::erro('competencia', "{$cliente->nome} já tem cobrança de ".Carbon::parse($competencia.'-01')->format('m/Y').'. Cancele a anterior para emitir de novo.');
            }
            $ordens = OrdemServico::abertas()->where('cliente_id', $cliente->id)->orderByRaw('COALESCE(vencimento, data)')->orderBy('numero')->lockForUpdate()->get();
            if (! $ordens->contains(fn ($o) => $o->tipo !== 'credito')) {
                self::erro('cliente', "{$cliente->nome} não tem contas em aberto.");
            }
            $total = round($ordens->sum(fn ($o) => $o->valorComSinal()), 2);
            if ($total <= 0) {
                self::erro('cliente', "O crédito de {$cliente->nome} cobre as contas em aberto; não há o que cobrar.");
            }

            $cob = Cobranca::create([
                'numero' => Numeracao::proximo('cobranca'),
                'cliente_id' => $cliente->id,
                'competencia' => $competencia,
                'emitida_em' => now(),
                'vencimento' => $vencimento ?: self::vencimentoSugerido($cliente, $competencia, $ordens),
                'total' => $total,
                'criado_por' => Auth::id(),
            ]);
            $anteriores = [];
            foreach ($ordens as $o) {
                CobrancaItem::create([
                    'cobranca_id' => $cob->id,
                    'ordem_id' => $o->id,
                    'grupo' => $o->cobranca_id ? 'saldo_anterior' : 'atual',
                    'numero' => $o->numero,
                    'tipo' => $o->tipo,
                    'descricao' => $o->descricao.($o->tipo === 'mensalidade' && $o->competencia ? ' '.Carbon::parse($o->competencia.'-01')->format('m/Y') : ''),
                    'placa' => $o->placa,
                    'data' => $o->data,
                    'vencimento' => $o->vencimento,
                    'valor' => $o->valorComSinal(),
                ]);
                if ($o->cobranca_id) {
                    $anteriores[] = $o->cobranca_id;
                }
                $o->update(['cobranca_id' => $cob->id]);
            }
            self::atualizarSituacao($anteriores);
            Atividades::registrar('cobranca_emitida', 'cobrança '.$cob->numero.' de '.$cliente->nome.': '.Formato::moeda($total).', vencimento '.$cob->vencimento->format('d/m/Y'));

            return $cob;
        });
    }

    /** Emissão em lote. Devolve [emitidas, mensagens de quem ficou de fora]. */
    public static function emitirLote(string $competencia, array $clienteIds): array
    {
        $emitidas = [];
        $erros = [];
        foreach (Cliente::whereIn('id', array_map('intval', $clienteIds))->orderBy('nome')->get() as $c) {
            try {
                $emitidas[] = self::emitir($c, $competencia);
            } catch (ValidationException $e) {
                $erros[] = collect($e->errors())->flatten()->first();
            }
        }

        return [$emitidas, $erros];
    }

    /** Cancela uma cobrança sem nada pago desde a emissão; as ordens voltam para a cobrança anterior. */
    public static function cancelar(Cobranca $cob, string $motivo): void
    {
        if ($cob->situacao !== 'aberta') {
            self::erro('motivo', 'Só dá para cancelar uma cobrança em aberto, sem nada pago.');
        }
        DB::transaction(function () use ($cob, $motivo) {
            $cob->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr($motivo, 0, 200)]);
            $afetadas = [];
            foreach (OrdemServico::where('cobranca_id', $cob->id)->get() as $o) {
                $anterior = CobrancaItem::where('ordem_id', $o->id)->where('cobranca_id', '<>', $cob->id)
                    ->whereHas('cobranca', fn ($q) => $q->where('situacao', '<>', 'cancelada'))->max('cobranca_id');
                $o->update(['cobranca_id' => $anterior]);
                $afetadas[] = $anterior;
            }
            self::atualizarSituacao($afetadas);
        });
        Atividades::registrar('cobranca_cancelada', 'cobrança '.$cob->numero.' ('.Formato::moeda($cob->total).'): '.$motivo);
    }

    /**
     * Situação de cada cobrança pelas ordens que ela copiou (créditos não contam):
     * paga = tudo pago; parcial = parte paga e o resto levado para outra cobrança ou ainda em aberto;
     * levada = nada pago e tudo levado para a cobrança seguinte; aberta = nada pago ainda;
     * cancelada = todas as ordens canceladas.
     */
    public static function atualizarSituacao(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        foreach (Cobranca::whereIn('id', $ids)->where('situacao', '<>', 'cancelada')->get() as $cob) {
            $ordens = OrdemServico::whereIn('id', CobrancaItem::where('cobranca_id', $cob->id)->where('tipo', '<>', 'credito')->pluck('ordem_id'))->get();
            $pagas = $ordens->filter(fn ($o) => $o->situacao === 'paga' && $o->cobranca_id == $cob->id)->count();
            $pendentes = $ordens->filter(fn ($o) => $o->situacao === 'aberta' && $o->cobranca_id == $cob->id)->count();
            $levadas = $ordens->filter(fn ($o) => $o->cobranca_id != $cob->id && $o->situacao !== 'cancelada')->count();
            $nova = match (true) {
                $pendentes > 0 => $pagas > 0 || $levadas > 0 ? 'parcial' : 'aberta',
                $levadas > 0 => $pagas > 0 ? 'parcial' : 'levada',
                $pagas > 0 => 'paga',
                default => 'cancelada',
            };
            if ($cob->situacao !== $nova) {
                $cob->update(['situacao' => $nova]);
            }
        }
    }
}
