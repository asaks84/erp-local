<?php

namespace App\Servicos;

use App\Support\Placa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Código do cliente = id, na mesma sequência do sistema atual (quem vem de lá tem o código de lá).
 * corrigir() arruma o que já colidiu: exclui o cadastro duplicado (ficha do sistema atual aprovada também
 * no ERP), tira do caminho o cliente criado no ERP que ocupa um código do sistema atual e devolve ao
 * importado o código de lá.
 */
class NumeracaoClientes
{
    /** tabelas e colunas que apontam para clientes.id */
    private static function referencias(): array
    {
        return collect(DB::select("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'clientes' AND REFERENCED_COLUMN_NAME = 'id'"))
            ->map(fn ($r) => [$r->t, $r->c])->unique(fn ($r) => $r[0].'.'.$r[1])->values()->all();
    }

    /** troca o código (id) de um cliente em todas as tabelas */
    public static function renumerar(int $de, int $para): void
    {
        if ($de === $para) {
            return;
        }
        if (DB::table('clientes')->where('id', $para)->exists()) {
            throw new \RuntimeException("Código {$para} já está em uso.");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::table('clientes')->where('id', $de)->update(['id' => $para]);
            foreach (self::referencias() as [$tabela, $coluna]) {
                DB::table($tabela)->where($coluna, $de)->update([$coluna => $para]);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private static function proximoLivre(): int
    {
        return (int) DB::table('clientes')->max('id') + 1;
    }

    /** a sequência continua depois do maior código */
    public static function ajustarSequencia(): void
    {
        DB::statement('ALTER TABLE clientes AUTO_INCREMENT = '.max(1, self::proximoLivre()));
    }

    /**
     * Só exclui um cadastro criado no ERP que tem apenas veículo, mensalidade, contas em aberto geradas no
     * ERP, cobrança sem boleto e link de recadastro. Pagamento, boleto ou qualquer outro registro: não exclui.
     */
    private static function podeExcluir(int $id): bool
    {
        $permitidas = ['veiculos', 'mensalidades_contratos', 'ordens_servico', 'cobrancas', 'atualizacoes_cadastrais'];
        foreach (self::referencias() as [$t, $c]) {
            if (! in_array($t, $permitidas, true) && DB::table($t)->where($c, $id)->exists()) {
                return false;
            }
        }
        $ordens = DB::table('ordens_servico')->where('cliente_id', $id)->get(['id', 'situacao', 'legado_id']);
        if ($ordens->contains(fn ($o) => $o->situacao === 'paga' || $o->legado_id !== null)) {
            return false;
        }
        $oids = $ordens->pluck('id')->all();
        $vids = DB::table('veiculos')->where('cliente_id', $id)->pluck('id')->all();
        $cids = DB::table('cobrancas')->where('cliente_id', $id)->pluck('id')->all();
        foreach (['pagamento_itens', 'ajustes'] as $t) {
            if ($oids && Schema::hasTable($t) && Schema::hasColumn($t, 'ordem_id') && DB::table($t)->whereIn('ordem_id', $oids)->exists()) {
                return false;
            }
        }
        if ($vids && DB::table('ordens_servico')->whereIn('veiculo_id', $vids)->where('cliente_id', '<>', $id)->exists()) {
            return false;
        }
        if ($cids && Schema::hasTable('boletos') && DB::table('boletos')->whereIn('cobranca_id', $cids)->exists()) {
            return false;
        }

        return true;
    }

    private static function excluir(int $id): void
    {
        $cids = DB::table('cobrancas')->where('cliente_id', $id)->pluck('id')->all();
        $vids = DB::table('veiculos')->where('cliente_id', $id)->pluck('id')->all();
        if ($cids && Schema::hasTable('cobranca_itens')) {
            DB::table('cobranca_itens')->whereIn('cobranca_id', $cids)->delete();
        }
        DB::table('cobrancas')->where('cliente_id', $id)->delete();
        DB::table('ordens_servico')->where('cliente_id', $id)->delete();
        DB::table('mensalidades_contratos')->where('cliente_id', $id)->delete();
        if (Schema::hasTable('atualizacoes_cadastrais')) {
            DB::table('atualizacoes_cadastrais')->where('cliente_id', $id)->delete();
        }
        if ($vids) {
            DB::table('veiculos')->whereIn('id', $vids)->delete();
        }
        DB::table('clientes')->where('id', $id)->delete();
    }

    private static function registrar(?int $empresa, string $texto): void
    {
        if ($empresa) {
            $log = new \App\Models\LogAtividade(['acao' => 'numeracao_clientes', 'detalhe' => mb_substr($texto, 0, 500), 'usuario_nome' => 'Sistema']);
            $log->empresa_id = $empresa;
            $log->save();
        }
    }

    /** devolve o resumo do que foi feito (também vai para o log e para o Registro de atividades) */
    public static function corrigir(): array
    {
        $feito = ['excluidos' => [], 'liberados' => [], 'codigo_de_la' => [], 'avisos' => []];
        if (! Schema::hasTable('clientes')) {
            return $feito;
        }

        DB::transaction(function () use (&$feito) {
            // 1. duplicado: cadastro criado no ERP que é o mesmo cliente de um importado (mesmo CPF ou mesma placa)
            foreach (DB::table('clientes')->whereNull('legado_id')->get(['id', 'empresa_id', 'nome', 'documento']) as $c) {
                $placas = DB::table('veiculos')->where('cliente_id', $c->id)->pluck('placa')->filter()->values()->all();
                if (! $c->documento && ! $placas) {
                    continue;
                }
                $gemeo = DB::table('clientes as i')->whereNotNull('i.legado_id')->where('i.ativo', true)->where('i.empresa_id', $c->empresa_id)
                    ->where(function ($q) use ($c, $placas) {
                        if ($c->documento) {
                            $q->orWhere('i.documento', $c->documento);
                        }
                        if ($placas) {
                            // o importado fica sem placa quando ela já estava no cadastro do ERP: compara pela identificação
                            $q->orWhereExists(fn ($s) => $s->from('veiculos as v')->whereColumn('v.cliente_id', 'i.id')->where('v.ativo', true)
                                ->where(fn ($w) => $w->whereIn('v.placa', $placas)->orWhereIn(DB::raw("UPPER(REPLACE(REPLACE(v.identificacao, '-', ''), ' ', ''))"), $placas)));
                        }
                    })->first(['i.id', 'i.documento']);
                if (! $gemeo) {
                    continue;
                }
                if (! self::podeExcluir((int) $c->id)) {
                    $feito['avisos'][] = "Cliente {$c->id} ({$c->nome}) parece o mesmo do {$gemeo->id}, mas tem pagamento ou outro registro: não foi excluído.";
                    self::registrar($c->empresa_id, end($feito['avisos']));

                    continue;
                }
                self::excluir((int) $c->id);
                // o CPF e a placa ficam com o cliente que vale (o importado ficou sem, por estarem repetidos)
                if ($c->documento && ! $gemeo->documento) {
                    DB::table('clientes')->where('id', $gemeo->id)->update(['documento' => $c->documento]);
                }
                foreach ($placas as $p) {
                    DB::table('veiculos')->where('cliente_id', $gemeo->id)->where('ativo', true)->whereNull('placa')
                        ->whereRaw("UPPER(REPLACE(REPLACE(identificacao, '-', ''), ' ', '')) = ?", [Placa::normalizar($p) ?? $p])
                        ->limit(1)->update(['placa' => $p]);
                }
                $feito['excluidos'][] = [$c->id, $gemeo->id];
                self::registrar($c->empresa_id, "Cliente {$c->id} ({$c->nome}) excluído: era o mesmo cliente do {$gemeo->id} (ficha do sistema atual aprovada também no ERP). O código {$c->id} ficou livre.");
            }

            // 2. importados com código diferente do sistema atual: voltam ao código de lá. Se um cliente criado
            //    no ERP está nesse código, ele vai para o próximo número livre.
            foreach (DB::table('clientes')->whereNotNull('legado_id')->whereColumn('id', '<>', 'legado_id')->get(['id', 'legado_id', 'empresa_id', 'nome']) as $c) {
                $ocupante = DB::table('clientes')->where('id', $c->legado_id)->first(['id', 'legado_id', 'empresa_id', 'nome']);
                if ($ocupante && $ocupante->legado_id !== null) {
                    $feito['avisos'][] = "Cliente {$c->id}: o código {$c->legado_id} do sistema atual está com outro cliente importado.";

                    continue;
                }
                if ($ocupante) {
                    $novo = self::proximoLivre();
                    self::renumerar((int) $ocupante->id, $novo);
                    $feito['liberados'][] = [$ocupante->id, $novo];
                    self::registrar($ocupante->empresa_id, "Cliente criado no ERP ({$ocupante->nome}) mudou do código {$ocupante->id} para {$novo}: o {$ocupante->id} é de um cliente do sistema atual.");
                }
                self::renumerar((int) $c->id, (int) $c->legado_id);
                $feito['codigo_de_la'][] = [$c->id, $c->legado_id];
                self::registrar($c->empresa_id, "Cliente {$c->nome} voltou do código {$c->id} para o {$c->legado_id}, o mesmo do sistema atual.");
            }
        });
        self::ajustarSequencia();
        Log::info('Numeração de clientes corrigida', $feito);

        return $feito;
    }
}
