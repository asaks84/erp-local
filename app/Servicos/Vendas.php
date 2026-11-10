<?php

namespace App\Servicos;

use App\Models\Convenio;
use App\Models\OrdemEtapa;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\TipoVeiculo;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Venda de lavagens e serviços no balcão: placa (veículo cadastrado ou avulso), serviço principal
 * e adicionais pelo preço da tabela do tipo do veículo, convênio, e na hora: pagar agora, pagar na
 * entrega ou deixar na conta do cliente. A etapa da lavagem fica na própria ordem (etapas configuráveis
 * em EtapasLavagem) e cada mudança vai para o histórico (ordem_etapas), com quem registrou.
 */
class Vendas
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** veículo ativo com essa placa (cadastrado), se houver */
    public static function veiculoDaPlaca(?string $placa): ?Veiculo
    {
        $p = Placa::normalizar($placa);

        return $p ? Veiculo::with(['cliente', 'tipo'])->where('ativo', true)->where('placa', $p)->first() : null;
    }

    /**
     * $d: placa, tipo_veiculo_id (avulso), celular, servico_id, adicionais[], valor (serviço "até"),
     *     convenio_id, destino (na_hora | entrega | conta), forma_pagamento_id, parcelas.
     * Devolve [ordens, mensagem].
     */
    public static function vender(array $d): array
    {
        $placa = Placa::normalizar($d['placa'] ?? null);
        if (! $placa) {
            self::erro('placa', 'Informe a placa.');
        }
        $veiculo = self::veiculoDaPlaca($placa);
        $tipoId = $veiculo?->tipo_veiculo_id ?? (int) ($d['tipo_veiculo_id'] ?? 0);
        if (! TipoVeiculo::find($tipoId)) {
            self::erro('tipo_veiculo_id', 'Placa sem cadastro: escolha o tipo do veículo.');
        }
        $principal = Servico::where('ativo', true)->where('papel', 'principal')->find($d['servico_id'] ?? 0) ?? self::erro('servico_id', 'Escolha o serviço.');
        $permitidos = $principal->adicionais()->pluck('servicos.id')->all();
        $adicionais = Servico::whereIn('id', (array) ($d['adicionais'] ?? []))->get();
        foreach ($adicionais as $a) {
            if (! in_array($a->id, $permitidos)) {
                self::erro('adicionais', "\"{$a->nome}\" não é adicional de \"{$principal->nome}\".");
            }
        }
        $convenio = ! empty($d['convenio_id']) ? Convenio::with('cliente')->where('ativo', true)->find($d['convenio_id']) : null;
        if (! empty($d['convenio_id']) && ! $convenio) {
            self::erro('convenio_id', 'Convênio não encontrado ou inativo.');
        }
        $destino = $d['destino'] ?? 'entrega';
        if (! in_array($destino, ['na_hora', 'entrega', 'conta'], true)) {
            self::erro('destino', 'Escolha quando o cliente paga.');
        }
        if ($destino === 'conta' && ! $veiculo?->cliente_id) {
            self::erro('destino', 'Só dá para lançar na conta de um cliente cadastrado.');
        }
        if ($destino === 'na_hora' && empty($d['forma_pagamento_id'])) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }

        $unidade = Ordens::unidadePadrao();
        $dia = now();
        $celular = preg_replace('/\D/', '', (string) ($d['celular'] ?? '')) ?: null;

        return DB::transaction(function () use ($placa, $veiculo, $tipoId, $principal, $adicionais, $convenio, $destino, $unidade, $dia, $celular, $d) {
            $ordens = [];
            $pagaConvenio = [];
            $principalId = null;
            foreach ([$principal, ...$adicionais] as $s) {
                $preco = Ordens::precoServico($s, $tipoId, $unidade, $dia, $s->id === $principal->id && isset($d['valor']) && $d['valor'] !== '' ? (float) $d['valor'] : null);
                $div = Convenios::dividir($convenio, 'servico', $preco);
                $base = [
                    'tipo' => 'servico', 'servico_id' => $s->id, 'principal_id' => $principalId, 'unidade_id' => $unidade,
                    'veiculo_id' => $veiculo?->id, 'placa' => $placa, 'tipo_veiculo_id' => $tipoId, 'celular' => $veiculo ? null : $celular,
                    'data' => $dia->toDateString(), 'vencimento' => $dia->toDateString(), 'etapa' => EtapasLavagem::PRIMEIRA,
                ];
                if ($div['convenio'] > 0) {
                    // o convênio paga: a ordem vai para a conta do convênio, com a placa de quem usou
                    $o = Ordens::lancar($base + ['cliente_id' => $convenio->cliente_id, 'convenio_id' => $convenio->id, 'valor' => $div['convenio'],
                        'descricao' => mb_substr($s->nome.' · '.Placa::formatar($placa).' (convênio)', 0, 80)]);
                    $pagaConvenio[] = $o;
                } elseif ($div['cliente'] > 0) {
                    $o = Ordens::lancar($base + ['cliente_id' => $veiculo?->cliente_id, 'convenio_id' => $convenio?->id, 'valor' => $div['cliente'],
                        'descricao' => mb_substr($s->nome.($div['desconto'] > 0 ? ' (desc. convênio '.Formato::moeda($div['desconto']).')' : ''), 0, 80)]);
                } else {
                    continue; // desconto de 100%: nada a cobrar
                }
                $principalId ??= $o->id;
                $ordens[] = $o;
            }
            if (! $ordens) {
                self::erro('servico_id', 'Nada a cobrar nesta venda.');
            }
            self::historico($ordens[0], EtapasLavagem::PRIMEIRA);
            $doCliente = array_values(array_filter($ordens, fn ($o) => ! in_array($o, $pagaConvenio, true)));
            $msg = count($ordens).' serviço(s) lançado(s) para '.Placa::formatar($placa);
            if ($doCliente && $destino === 'na_hora') {
                Baixa::receberNaHora($doCliente, (int) $d['forma_pagamento_id'], (int) ($d['parcelas'] ?? 1));
                $msg .= ' · pago '.Formato::moeda(collect($doCliente)->sum(fn ($o) => (float) $o->valor));
            } elseif ($doCliente && $destino === 'conta') {
                $msg .= ' · na conta de '.$veiculo->cliente->nome;
            } elseif ($doCliente) {
                $msg .= ' · paga na entrega';
            }
            if ($pagaConvenio) {
                $msg .= ' · '.Formato::moeda(collect($pagaConvenio)->sum(fn ($o) => (float) $o->valor)).' na conta do convênio';
            }
            Atividades::registrar('venda_servico', Placa::formatar($placa).': '.collect($ordens)->map(fn ($o) => '#'.$o->numero.' '.$o->descricao.' '.Formato::moeda($o->valor))->implode(', '));

            return [$ordens, $msg];
        });
    }

    /** ordens de uma venda (a principal e os adicionais ligados a ela) */
    public static function grupo(OrdemServico $o): \Illuminate\Support\Collection
    {
        $principal = $o->principal_id ? OrdemServico::find($o->principal_id) : $o;

        return OrdemServico::where('id', $principal->id)->orWhere('principal_id', $principal->id)->orderBy('id')->get();
    }

    private static function historico(OrdemServico $principal, string $etapa): void
    {
        OrdemEtapa::create(['ordem_id' => $principal->id, 'etapa' => $etapa, 'usuario_id' => Auth::id(), 'quando' => now()]);
    }

    /** muda a etapa da lavagem de toda a venda e registra no histórico */
    public static function etapa(OrdemServico $o, string $etapa): void
    {
        $etapas = EtapasLavagem::ativas();
        if (! array_key_exists($etapa, $etapas)) {
            self::erro('etapa', 'Etapa inválida ou desativada.');
        }
        $grupo = self::grupo($o);
        if ($grupo->every(fn ($x) => $x->situacao === 'cancelada')) {
            self::erro('etapa', 'Venda cancelada.');
        }
        if ($grupo->first()->etapa === $etapa) {
            return;
        }
        DB::transaction(function () use ($grupo, $etapa) {
            foreach ($grupo as $x) {
                $x->update(['etapa' => $etapa]);
            }
            self::historico($grupo->first(), $etapa);
        });
        Atividades::registrar('lavagem_etapa', Placa::formatar($grupo->first()->placa).' #'.$grupo->first()->numero.': '.$etapas[$etapa]);
    }
}
