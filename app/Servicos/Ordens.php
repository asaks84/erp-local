<?php

namespace App\Servicos;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lançamento, alteração e cancelamento de ordens de serviço.
 * Toda ordem nasce aqui (telas, geração de mensalidades, baixa, extrato), com número da empresa.
 */
class Ordens
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** unidade do usuário ou, sem ela, a primeira unidade ativa */
    public static function unidadePadrao(): ?int
    {
        return Auth::user()?->unidade_id ?? Unidade::where('ativa', true)->orderBy('id')->value('id');
    }

    /**
     * Cria a ordem. $d: tipo, valor, descricao e opcionais (cliente_id, veiculo_id, data, vencimento,
     * competencia, contrato_id, servico_id, principal_id, origem, situacao, pago_em, pagamento_origem_id).
     */
    public static function lancar(array $d): OrdemServico
    {
        if (round((float) $d['valor'], 2) <= 0) {
            self::erro('valor', 'O valor precisa ser maior que zero.');
        }
        $veiculo = ! empty($d['veiculo_id']) ? Veiculo::find($d['veiculo_id']) : null;
        if ($veiculo) {
            $d['cliente_id'] ??= $veiculo->cliente_id;
            $d['placa'] ??= $veiculo->placa;
            $d['veiculo_descricao'] ??= mb_substr(trim($veiculo->marca.' '.$veiculo->modelo), 0, 60) ?: $veiculo->identificacao;
        }

        return DB::transaction(function () use ($d) {
            $o = new OrdemServico(array_merge([
                'unidade_id' => self::unidadePadrao(),
                'data' => now()->toDateString(),
                'criado_por' => Auth::id(),
            ], $d, [
                'uuid' => (string) Str::uuid(),
                'numero' => Numeracao::proximo('ordem'),
                'valor' => round((float) $d['valor'], 2),
                'descricao' => mb_substr($d['descricao'], 0, 80),
            ]));
            $o->save();

            return $o;
        });
    }

    /** Lavagem/serviço com adicionais: uma ordem por serviço, adicionais ligados à principal. */
    /** preço do serviço pelo tipo do veículo na data; no modo "até", valida o valor informado */
    public static function precoServico(Servico $s, int $tipoVeiculoId, ?int $unidade, Carbon $dia, ?float $informado = null): float
    {
        $tabela = Precos::servico($s->id, $tipoVeiculoId, $unidade, $dia);
        if ($tabela === null) {
            $tipo = \App\Models\TipoVeiculo::find($tipoVeiculoId)?->nome ?? 'este tipo de veículo';
            self::erro('servico', "\"{$s->nome}\" não tem preço para {$tipo}. Cadastre em Configurações › Lavagens e serviços.");
        }
        // "até": o valor da tabela é o máximo; quem lança informa o valor cobrado
        if ($s->preco_modo === 'ate' && $informado !== null) {
            if ($informado <= 0 || $informado > $tabela) {
                self::erro('valor', "O valor de \"{$s->nome}\" vai até ".Formato::moeda($tabela).'.');
            }

            return $informado;
        }

        return $tabela;
    }

    public static function lancarServico(Veiculo $veiculo, Servico $principal, array $adicionais, ?string $data = null, ?float $valorPrincipal = null): array
    {
        $dia = Carbon::parse($data ?? now());
        $unidade = self::unidadePadrao();
        $preco = fn (Servico $s, ?float $informado) => self::precoServico($s, $veiculo->tipo_veiculo_id, $unidade, $dia, $informado);

        return DB::transaction(function () use ($veiculo, $principal, $adicionais, $dia, $preco, $valorPrincipal) {
            $base = ['tipo' => 'servico', 'veiculo_id' => $veiculo->id, 'data' => $dia->toDateString(), 'vencimento' => $dia->toDateString()];
            $p = self::lancar($base + ['servico_id' => $principal->id, 'descricao' => $principal->nome, 'valor' => $preco($principal, $valorPrincipal)]);
            $ordens = [$p];
            $permitidos = $principal->adicionais()->pluck('servicos.id')->all();
            foreach ($adicionais as $a) {
                if (! in_array($a->id, $permitidos)) {
                    self::erro('adicionais', "\"{$a->nome}\" não é adicional de \"{$principal->nome}\".");
                }
                $ordens[] = self::lancar($base + ['servico_id' => $a->id, 'principal_id' => $p->id, 'descricao' => $a->nome, 'valor' => $preco($a, null)]);
            }
            Atividades::registrar('ordem_lancada', $veiculo->rotulo().': '.collect($ordens)->map(fn ($o) => '#'.$o->numero.' '.$o->descricao.' '.Formato::moeda($o->valor))->implode(', '));

            return $ordens;
        });
    }

    /** Estadia, débito ou crédito lançado direto na conta do cliente (débito e crédito exigem motivo). */
    public static function lancarNaConta(Cliente $cliente, string $tipo, float $valor, string $descricao, ?string $data = null, ?string $motivo = null): OrdemServico
    {
        return DB::transaction(function () use ($cliente, $tipo, $valor, $descricao, $data, $motivo) {
            $dia = Carbon::parse($data ?? now())->toDateString();
            $o = self::lancar(['tipo' => $tipo, 'cliente_id' => $cliente->id, 'valor' => $valor, 'descricao' => $descricao, 'data' => $dia, 'vencimento' => $tipo === 'credito' ? null : $dia]);
            if (in_array($tipo, ['credito', 'debito'])) {
                Ajuste::create(['tipo' => $tipo, 'cliente_id' => $cliente->id, 'ordem_id' => $o->id, 'valor' => $o->valor, 'motivo' => $motivo ?: $descricao, 'criado_por' => Auth::id()]);
            }
            Atividades::registrar('ordem_lancada', 'cliente '.$cliente->id.': #'.$o->numero.' '.$o->descricao.' '.Formato::moeda($o->valor).($motivo ? ' ('.$motivo.')' : ''));

            return $o;
        });
    }

    /** Muda valor, vencimento ou descrição de uma ordem em aberto (quem tem permissão de alterar contas). */
    public static function alterar(OrdemServico $o, float $valor, ?string $vencimento, string $descricao): void
    {
        if ($o->situacao !== 'aberta') {
            self::erro('valor', 'Só ordens em aberto podem ser alteradas.');
        }
        if (round($valor, 2) <= 0) {
            self::erro('valor', 'O valor precisa ser maior que zero.');
        }
        $antes = Formato::moeda($o->valor).($o->vencimento ? ', venc. '.$o->vencimento->format('d/m/Y') : '');
        $o->update(['valor' => round($valor, 2), 'vencimento' => $vencimento, 'descricao' => mb_substr($descricao, 0, 80)]);
        Atividades::registrar('ordem_alterada', '#'.$o->numero.' '.$o->descricao.': '.$antes.' → '.Formato::moeda($o->valor).($o->vencimento ? ', venc. '.$o->vencimento->format('d/m/Y') : ''));
    }

    /** Cancela uma ordem em aberto, com motivo. Nunca apaga. */
    public static function cancelar(OrdemServico $o, string $motivo): void
    {
        if ($o->situacao !== 'aberta') {
            self::erro('motivo', 'Só ordens em aberto podem ser canceladas.');
        }
        DB::transaction(function () use ($o, $motivo) {
            $o->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr($motivo, 0, 200)]);
            Cobrancas::atualizarSituacao([$o->cobranca_id]);
        });
        Atividades::registrar('ordem_cancelada', '#'.$o->numero.' '.$o->descricao.' '.Formato::moeda($o->valor).' ('.$motivo.')');
    }
}
