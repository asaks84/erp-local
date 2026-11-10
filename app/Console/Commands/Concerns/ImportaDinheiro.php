<?php

namespace App\Console\Commands\Concerns;

use App\Models\MensalidadeContrato;
use App\Models\Servico;
use App\Models\Unidade;
use App\Servicos\FormasPagamento;
use App\Servicos\Numeracao;
use App\Support\EmpresaAtual;
use App\Support\Legado;
use App\Support\Placa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Etapa 3 da importação: ordens de serviço, pagamentos e extrato importado.
 *
 * - Cada linha de ORDEM_SERVICOS vira uma ordem com o mesmo número (ord_id); o código de serviço
 *   vira o tipo (MEN mensalidade, EST estadia, CDT crédito, DBT débito, demais lavagem/serviço).
 * - O sistema atual não tem pagamentos: as ordens pagas de um cliente no mesmo dia e hora viram um
 *   pagamento com forma "não informada" e o valor das ordens (crédito usado abate).
 * - Até a virada o sistema atual manda: os pagamentos importados são refeitos a cada importação e
 *   as ordens apagadas lá saem daqui. O que foi lançado só no ERP fica intocado.
 */
trait ImportaDinheiro
{
    private function importarDinheiro(Legado $legado): void
    {
        $tabela = $legado->tabela('ORDEM_SERVICOS');
        if (! $tabela) {
            return;
        }
        $db = $legado->conexao();
        $empresa = app(EmpresaAtual::class)->id();
        $agora = now()->format('Y-m-d H:i:s');
        FormasPagamento::garantirPadrao();
        $naoInformada = FormasPagamento::naoInformada()->id;
        $unidade = Unidade::orderBy('id')->value('id');

        $temDesc = $legado->tem('ORDEM_SERVICOS', 'ord_srv_desc');
        $temVclDesc = $legado->tem('ORDEM_SERVICOS', 'ord_vcl_desc');
        $temHora = $legado->tem('ORDEM_SERVICOS', 'ord_hr_pagamento');

        $servicos = Servico::whereNotNull('legado_codigo')->get(['id', 'nome', 'legado_codigo'])->keyBy(fn ($s) => strtoupper($s->legado_codigo));
        $contratos = MensalidadeContrato::whereNotNull('legado_id')->orderBy('inicio')->get(['id', 'veiculo_id'])->keyBy('veiculo_id');
        $fixos = ['MEN' => 'Mensalidade', 'EST' => 'Estadia avulsa', 'CDT' => 'Crédito', 'DBT' => 'Débito'];
        $tipos = ['MEN' => 'mensalidade', 'EST' => 'estadia', 'CDT' => 'credito', 'DBT' => 'debito'];

        // 1. pagamentos importados são refeitos do zero (o sistema atual pode ter mudado)
        DB::table('pagamentos')->where('empresa_id', $empresa)->where('origem', 'importacao')->delete();

        // 2. número que o ERP já usou em ordens próprias e que coincide com o de uma ordem antiga: renumera a do ERP
        $maiorAntigo = (int) $db->table($tabela)->max('ord_id');
        $conflitos = DB::table('ordens_servico')->where('empresa_id', $empresa)->whereNull('legado_id')->where('numero', '<=', $maiorAntigo)->orderBy('numero')->pluck('id');
        if ($conflitos->count()) {
            $proximo = max($maiorAntigo, (int) DB::table('ordens_servico')->where('empresa_id', $empresa)->max('numero'));
            foreach ($conflitos as $id) {
                DB::table('ordens_servico')->where('id', $id)->update(['numero' => ++$proximo]);
            }
            $this->warn($conflitos->count().' ordem(ns) lançada(s) só no ERP ganharam número novo (o número já era de uma ordem do sistema atual).');
        }

        // ordens antigas já recebidas no ERP (pagamento feito aqui, confirmado): vale o pagamento do ERP;
        // se o sistema atual também deu baixa, não se cria um segundo pagamento para a mesma conta
        $recebidasAqui = DB::table('ordens_servico as o')->join('pagamento_itens as i', 'i.ordem_id', '=', 'o.id')->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')
            ->where('o.empresa_id', $empresa)->whereNotNull('o.legado_id')->where('p.origem', '<>', 'importacao')->where('p.situacao', 'confirmado')
            ->pluck('o.legado_id')->flip()->all();
        $duplas = 0;

        // 3. ordens: insere ou atualiza pelo id antigo
        $existentes = DB::table('ordens_servico')->where('empresa_id', $empresa)->whereNotNull('legado_id')->count();
        $pagas = [];
        $total = 0;
        $vistos = [];
        $semData = 0;
        $db->table($tabela)->orderBy('ord_id')->chunk(1000, function ($linhas) use (&$pagas, &$total, &$vistos, &$semData, &$duplas, $recebidasAqui, $empresa, $agora, $unidade, $servicos, $contratos, $fixos, $tipos, $temDesc, $temVclDesc, $temHora) {
            $lote = [];
            foreach ($linhas as $o) {
                $codigo = strtoupper(trim((string) $o->ord_srv_codigo));
                $tipo = $tipos[$codigo] ?? 'servico';
                $placaOriginal = strtoupper(trim((string) $o->ord_vcl_placa));
                $veiculo = $placaOriginal !== '' ? ($this->mapaVeiculos[$placaOriginal] ?? null) : null;
                $data = $this->data($o->ord_dt_geracao) ?? $this->data($o->ord_dt_vencimento) ?? $this->data($o->ord_dt_pagamento) ?? '2000-01-01';
                $vencimento = $this->data($o->ord_dt_vencimento);
                $pagoEm = $this->data($o->ord_dt_pagamento);
                // data de pagamento preenchida mas inválida (ex.: 0000-00-00): o sistema atual conta como paga
                // (só considera em aberto quando está vazia). Fica paga aqui, sem data e sem pagamento reconstruído.
                $pagaSemData = $pagoEm === null && $o->ord_dt_pagamento !== null && trim((string) $o->ord_dt_pagamento) !== '';
                if ($pagaSemData) {
                    $semData++;
                }
                $hora = $temHora && $o->ord_hr_pagamento ? substr((string) $o->ord_hr_pagamento, 0, 8) : '00:00:00';
                $clienteId = $o->ord_cli_id ? ($this->mapaClientes[(int) $o->ord_cli_id] ?? null) : null;
                $descricao = $temDesc ? $this->texto($o->ord_srv_desc, 80) : null;
                $vistos[(int) $o->ord_id] = true;

                $lote[] = [
                    'empresa_id' => $empresa,
                    'unidade_id' => $unidade,
                    'uuid' => (string) Str::uuid(),
                    'numero' => (int) $o->ord_id,
                    'tipo' => $tipo,
                    'cliente_id' => $clienteId,
                    'veiculo_id' => $veiculo?->id,
                    'contrato_id' => $tipo === 'mensalidade' && $veiculo ? $contratos->get($veiculo->id)?->id : null,
                    'servico_id' => $tipo === 'servico' ? $servicos->get($codigo)?->id : null,
                    'descricao' => $descricao ?? ($servicos->get($codigo)?->nome ?? $fixos[$codigo] ?? ($codigo ?: 'Serviço')),
                    'placa' => $placaOriginal !== '' ? mb_substr(Placa::normalizar($placaOriginal) ?: $placaOriginal, 0, 10) : null,
                    'veiculo_descricao' => $temVclDesc ? $this->texto($o->ord_vcl_desc, 60) : null,
                    'data' => $data,
                    'competencia' => $tipo === 'mensalidade' ? substr($vencimento ?? $data, 0, 7) : null,
                    'vencimento' => $vencimento,
                    'valor' => round((float) $o->ord_valor, 2),
                    'situacao' => ($pagoEm || $pagaSemData) ? 'paga' : 'aberta',
                    'pago_em' => $pagoEm ? $pagoEm.' '.$hora : null,
                    'origem' => 'importacao',
                    'legado_id' => (int) $o->ord_id,
                    'criado_em' => $agora,
                    'atualizado_em' => $agora,
                ];
                if ($pagoEm && isset($recebidasAqui[(int) $o->ord_id])) {
                    $duplas++;
                } elseif ($pagoEm) {
                    $chave = 'c'.($clienteId ?? 0).'|'.$pagoEm.'|'.$hora;
                    $pagas[$chave]['cliente_id'] = $clienteId;
                    $pagas[$chave]['recebido_em'] = $pagoEm.' '.$hora;
                    $pagas[$chave]['ordens'][(int) $o->ord_id] = ($tipo === 'credito' ? -1 : 1) * round((float) $o->ord_valor, 2);
                }
            }
            // uuid e criado_em ficam como estavam; o resto segue o sistema atual
            DB::table('ordens_servico')->upsert($lote, ['empresa_id', 'legado_id'], [
                'unidade_id', 'numero', 'tipo', 'cliente_id', 'veiculo_id', 'contrato_id', 'servico_id', 'descricao', 'placa', 'veiculo_descricao',
                'data', 'competencia', 'vencimento', 'valor', 'situacao', 'pago_em', 'atualizado_em',
            ]);
            $total += count($lote);
        });

        // 4. ordens apagadas no sistema atual saem daqui (as que não foram usadas no ERP)
        $sumiram = DB::table('ordens_servico')->where('empresa_id', $empresa)->whereNotNull('legado_id')->pluck('id', 'legado_id')
            ->filter(fn ($id, $legadoId) => ! isset($vistos[$legadoId]));
        $usadas = $sumiram->isEmpty() ? collect() : DB::table('pagamento_itens')->whereIn('ordem_id', $sumiram->values())->pluck('ordem_id')
            ->merge(DB::table('ajustes')->whereIn('ordem_id', $sumiram->values())->pluck('ordem_id'))->unique();
        $apagar = $sumiram->values()->diff($usadas)->values();
        foreach ($apagar->chunk(1000) as $parte) {
            DB::table('ordens_servico')->whereIn('principal_id', $parte)->update(['principal_id' => null]);
            DB::table('ordens_servico')->whereIn('id', $parte)->delete();
        }
        if ($usadas->count()) {
            $this->warn($usadas->count().' ordem(ns) apagada(s) no sistema atual continuam no ERP porque já foram usadas aqui (pagamento ou ajuste).');
        }

        $ids = DB::table('ordens_servico')->where('empresa_id', $empresa)->whereNotNull('legado_id')->pluck('id', 'legado_id');

        // 5. ordem que o ERP já recebeu e que o sistema atual ainda mostra em aberto: fica paga aqui
        $recebidasNoErp = DB::table('ordens_servico as o')->join('pagamento_itens as i', 'i.ordem_id', '=', 'o.id')->join('pagamentos as p', 'p.id', '=', 'i.pagamento_id')
            ->where('o.empresa_id', $empresa)->whereNotNull('o.legado_id')->where('o.situacao', 'aberta')->where('p.situacao', 'confirmado')
            ->select('o.id', 'p.recebido_em')->get();
        foreach ($recebidasNoErp as $r) {
            DB::table('ordens_servico')->where('id', $r->id)->update(['situacao' => 'paga', 'pago_em' => $r->recebido_em]);
        }
        if ($recebidasNoErp->count()) {
            $this->warn($recebidasNoErp->count().' ordem(ns) em aberto no sistema atual já foram recebidas no ERP (ficaram pagas aqui).');
        }

        // 6. pagamentos: um por cliente e data/hora de pagamento
        $qtdPag = 0;
        foreach (array_chunk($pagas, 500, true) as $grupo) {
            $linhas = [];
            foreach ($grupo as $chave => $p) {
                $valor = round(array_sum($p['ordens']), 2);
                $linhas[] = [
                    'empresa_id' => $empresa, 'unidade_id' => $unidade, 'uuid' => (string) Str::uuid(), 'cliente_id' => $p['cliente_id'],
                    'forma_pagamento_id' => $naoInformada, 'parcelas' => 1, 'valor' => $valor, 'taxa' => 0, 'liquido' => $valor,
                    'previsto_para' => substr($p['recebido_em'], 0, 10), 'recebido_em' => $p['recebido_em'], 'situacao' => 'confirmado',
                    'origem' => 'importacao', 'legado_chave' => mb_substr($chave, 0, 40), 'criado_em' => $agora, 'atualizado_em' => $agora,
                ];
            }
            DB::table('pagamentos')->insert($linhas);
            $novos = DB::table('pagamentos')->where('empresa_id', $empresa)->where('origem', 'importacao')->whereIn('legado_chave', array_keys($grupo))->pluck('id', 'legado_chave');
            $itens = [];
            foreach ($grupo as $chave => $p) {
                foreach ($p['ordens'] as $legadoId => $valor) {
                    if (isset($ids[$legadoId], $novos[$chave])) {
                        $itens[] = ['pagamento_id' => $novos[$chave], 'ordem_id' => $ids[$legadoId], 'valor' => $valor];
                    }
                }
            }
            foreach (array_chunk($itens, 1000) as $parte) {
                DB::table('pagamento_itens')->insert($parte);
            }
            $qtdPag += count($linhas);
        }

        if ($duplas) {
            $this->warn("{$duplas} ordem(ns) pagas nos dois sistemas: vale o pagamento feito no ERP (o do sistema atual não foi recriado).");
        }
        if ($semData) {
            $this->warn("{$semData} ordem(ns) com data de pagamento inválida no sistema atual (ex.: 0000-00-00) entraram como pagas, sem data.");
        }
        Numeracao::acertar('ordem');
        $this->contar('Ordens de serviço', max(0, $total - $existentes), min($total, $existentes), 0);
        $this->contar('Pagamentos (forma não informada)', $qtdPag);
        $this->importarExtrato($legado);
    }

    private function importarExtrato(Legado $legado): void
    {
        $tabela = $legado->tabela('EXTRATO_LANCAMENTOS');
        if (! $tabela) {
            return;
        }
        $empresa = app(EmpresaAtual::class)->id();
        $pagamentos = DB::table('pagamentos')->where('empresa_id', $empresa)->where('origem', 'importacao')->pluck('id', 'legado_chave');
        $usados = DB::table('extrato_lancamentos')->where('empresa_id', $empresa)->whereNull('legado_id')->pluck('identificador')->flip();
        $n = $ign = 0;
        foreach ($legado->conexao()->table($tabela)->orderBy('ext_id')->get() as $e) {
            if (isset($usados[$e->ext_identificador])) {
                $ign++; // já baixado pelo ERP

                continue;
            }
            $cliente = $e->ext_cli_id ? ($this->mapaClientes[(int) $e->ext_cli_id] ?? null) : null;
            $data = $this->data($e->ext_data);
            DB::table('extrato_lancamentos')->upsert([[
                'empresa_id' => $empresa,
                'identificador' => mb_substr($e->ext_identificador, 0, 64),
                'data' => $data,
                'valor' => $e->ext_valor,
                'nome' => $this->texto($e->ext_nome, 100),
                'documento' => $e->ext_documento ?: null,
                'cliente_id' => $cliente,
                // a baixa do extrato no sistema atual grava a hora 00:00:00
                'pagamento_id' => $pagamentos['c'.($cliente ?? 0).'|'.$data.'|00:00:00'] ?? null,
                'total_contas' => $e->ext_total_contas,
                'processado_em' => $e->ext_processado_em,
                'usuario_nome' => $this->texto($e->ext_usuario, 80),
                'legado_id' => (int) $e->ext_id,
            ]], ['empresa_id', 'legado_id'], ['identificador', 'data', 'valor', 'nome', 'documento', 'cliente_id', 'pagamento_id', 'total_contas', 'processado_em', 'usuario_nome']);
            $n++;
        }
        $this->contar('Extrato importado', $n, 0, $ign);
    }
}
