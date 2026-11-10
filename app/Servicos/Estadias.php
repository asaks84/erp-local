<?php

namespace App\Servicos;

use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\MensalidadeContrato;
use App\Models\TipoVeiculo;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Estadias (tickets): entrada, cálculo pela tabela de estadia do tipo do veículo (tarifador),
 * divisão com o convênio e saída. Na saída, a parte de quem usou é paga na hora ou vai para a
 * conta do cliente; a parte do convênio vai para a conta do convênio (entra na cobrança dele).
 */
class Estadias
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** contrato de mensalidade vigente na data, se o veículo for mensalista */
    public static function contratoVigente(?int $veiculoId, ?CarbonInterface $quando = null): ?MensalidadeContrato
    {
        return $veiculoId ? MensalidadeContrato::where('veiculo_id', $veiculoId)->vigentes($quando ?? now())->orderByDesc('inicio')->first() : null;
    }

    /** $d: placa (ou identificacao), tipo_veiculo_id (sem cadastro), entrada, convenio_id, celular */
    public static function entrar(array $d): Estadia
    {
        $placa = Placa::normalizar($d['placa'] ?? null);
        $ident = trim((string) ($d['identificacao'] ?? '')) ?: null;
        if (! $placa && ! $ident) {
            self::erro('placa', 'Informe a placa (ou, sem placa, uma identificação do veículo).');
        }
        $veiculo = $placa ? Vendas::veiculoDaPlaca($placa) : null;
        $tipoId = $veiculo?->tipo_veiculo_id ?? (int) ($d['tipo_veiculo_id'] ?? 0);
        if (! TipoVeiculo::find($tipoId)) {
            self::erro('tipo_veiculo_id', 'Placa sem cadastro: escolha o tipo do veículo.');
        }
        $entrada = Carbon::parse($d['entrada'] ?? now())->seconds(0);
        if ($entrada->isAfter(now()->addMinutes(5))) {
            self::erro('entrada', 'A entrada não pode ser no futuro.');
        }
        $convenio = ! empty($d['convenio_id']) ? Convenio::where('ativo', true)->where('vale_estadia', true)->find($d['convenio_id']) : null;
        if (! empty($d['convenio_id']) && ! $convenio) {
            self::erro('convenio_id', 'Convênio não encontrado, inativo ou sem estadias.');
        }

        return DB::transaction(function () use ($placa, $ident, $veiculo, $tipoId, $entrada, $convenio, $d) {
            if ($placa && Estadia::where('situacao', 'patio')->where('placa', $placa)->lockForUpdate()->exists()) {
                self::erro('placa', Placa::formatar($placa).' já está no pátio.');
            }
            $e = Estadia::create([
                'unidade_id' => Ordens::unidadePadrao(),
                'uuid' => (string) Str::uuid(),
                'numero' => Numeracao::proximo('estadia'),
                'placa' => $placa,
                'identificacao' => $placa ? null : mb_substr($ident, 0, 40),
                'veiculo_id' => $veiculo?->id,
                'cliente_id' => $veiculo?->cliente_id,
                'tipo_veiculo_id' => $tipoId,
                'celular' => $veiculo ? null : (preg_replace('/\D/', '', (string) ($d['celular'] ?? '')) ?: null),
                'entrada' => $entrada,
                'convenio_id' => $convenio?->id,
                'mensalista' => (bool) self::contratoVigente($veiculo?->id, $entrada),
                'criado_por' => Auth::id(),
            ]);
            Atividades::registrar('estadia_entrada', 'ticket '.$e->numero.' · '.$e->rotulo().' · '.$entrada->format('d/m H:i'));

            return $e;
        });
    }

    /**
     * Valor da estadia até a saída, já dividido com o convênio.
     *
     * @return array{saida: string, minutos: int, valor: float, detalhes: array, convenio: float, desconto: float, cliente: float, regra: string, tarifa_id: int, tarifa: string}
     */
    public static function calcular(Estadia $e, ?CarbonInterface $saida = null): array
    {
        $saida = ($saida ?? now())->copy()->seconds(0);
        if ($saida->lt($e->entrada)) {
            self::erro('saida', 'A saída precisa ser depois da entrada ('.$e->entrada->format('d/m/Y H:i').').');
        }
        $tarifa = Precos::tarifa($e->tipo_veiculo_id, $e->unidade_id, $e->entrada)
            ?? self::erro('saida', 'Não há tabela de estadia para '.($e->tipo?->nome ?? 'este tipo de veículo').'. Cadastre em Configurações › Tabelas de estadia.');
        $t = new Tarifador($tarifa->regras());
        $r = $t->calcular($e->entrada, $saida);
        $div = Convenios::dividir($e->convenio()->with('cliente')->first(), 'estadia', $r['valor'], $r['minutos'],
            fn (int $min) => $t->calcular($e->entrada, $e->entrada->copy()->addMinutes($min))['valor']);

        return [
            'saida' => $saida->format('Y-m-d H:i'),
            'minutos' => $r['minutos'],
            'tempo' => Formato::duracao($r['minutos']),
            'valor' => $r['valor'],
            'detalhes' => $r['detalhes'],
            'tarifa_id' => $tarifa->id,
            'tarifa' => $tarifa->nome,
        ] + $div;
    }

    /**
     * Saída. $destino: pago (quem usou paga agora), conta (vai para a conta do cliente), mensalista (sem cobrança).
     * A parte do convênio sempre vai para a conta do convênio. Valor zero (tolerância) não gera ordem.
     */
    public static function sair(Estadia $e, array $d): Estadia
    {
        if ($e->situacao !== 'patio') {
            self::erro('saida', 'Esta estadia já foi finalizada ou cancelada.');
        }
        $c = self::calcular($e, Carbon::parse($d['saida'] ?? now()));
        $destino = $d['destino'] ?? 'pago';
        if ($destino === 'mensalista' && ! $e->mensalista) {
            self::erro('destino', 'Este veículo não tem mensalidade vigente.');
        }
        if ($destino === 'conta' && ! $e->cliente_id) {
            self::erro('destino', 'Só dá para lançar na conta de um cliente cadastrado.');
        }
        $cliente = $destino === 'mensalista' ? 0.0 : $c['cliente'];
        if ($cliente > 0 && $destino === 'pago' && empty($d['forma_pagamento_id'])) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }
        if (! in_array($destino, ['pago', 'conta', 'mensalista'], true)) {
            self::erro('destino', 'Escolha como fica o pagamento.');
        }

        return DB::transaction(function () use ($e, $c, $destino, $cliente, $d) {
            $e = Estadia::whereKey($e->id)->lockForUpdate()->first();
            if ($e->situacao !== 'patio') {
                self::erro('saida', 'Esta estadia já foi finalizada.');
            }
            $saida = Carbon::parse($c['saida']);
            $periodo = $e->entrada->format('d/m H:i').' a '.($saida->isSameDay($e->entrada) ? $saida->format('H:i') : $saida->format('d/m H:i'));
            $base = ['tipo' => 'estadia', 'unidade_id' => $e->unidade_id, 'estadia_id' => $e->id, 'veiculo_id' => $e->veiculo_id,
                'placa' => $e->placa, 'tipo_veiculo_id' => $e->tipo_veiculo_id, 'data' => $saida->toDateString(), 'vencimento' => $saida->toDateString()];

            $ordemConv = null;
            if ($c['convenio'] > 0) {
                $conv = $e->convenio;
                $ordemConv = Ordens::lancar($base + ['cliente_id' => $conv->cliente_id, 'convenio_id' => $conv->id, 'valor' => $c['convenio'],
                    'descricao' => mb_substr('Estadia '.$e->rotulo().' '.$periodo.' (convênio)', 0, 80)]);
            }
            $ordem = null;
            if ($cliente > 0) {
                $ordem = Ordens::lancar($base + ['cliente_id' => $e->cliente_id, 'convenio_id' => $e->convenio_id, 'celular' => $e->celular, 'valor' => $cliente,
                    'descricao' => mb_substr('Estadia '.$periodo.($c['desconto'] > 0 ? ' (desc. convênio '.Formato::moeda($c['desconto']).')' : ''), 0, 80)]);
                if ($destino === 'pago') {
                    Baixa::receberNaHora([$ordem], (int) $d['forma_pagamento_id'], (int) ($d['parcelas'] ?? 1));
                }
            }
            $final = match (true) {
                $destino === 'mensalista' => 'mensalista',
                $cliente > 0 => $destino,
                $c['convenio'] > 0 => 'convenio',
                default => 'gratis',
            };
            $e->update([
                'saida' => $saida, 'minutos' => $c['minutos'], 'tarifa_id' => $c['tarifa_id'], 'valor_calculado' => $c['valor'],
                'valor_convenio' => $c['convenio'], 'desconto' => $c['desconto'], 'valor_cliente' => $cliente,
                'situacao' => 'finalizada', 'destino' => $final, 'detalhes' => $c['detalhes'],
                'ordem_id' => $ordem?->id, 'ordem_convenio_id' => $ordemConv?->id, 'finalizado_por' => Auth::id(),
            ]);
            Atividades::registrar('estadia_saida', 'ticket '.$e->numero.' · '.$e->rotulo().' · '.$c['tempo'].' · '.Formato::moeda($c['valor'])
                .($c['convenio'] > 0 ? ' (convênio '.Formato::moeda($c['convenio']).')' : '').' · '.Estadia::DESTINOS[$final]);

            return $e->fresh();
        });
    }

    /** Cancela uma estadia que ainda está no pátio (entrada lançada por engano), com motivo. */
    public static function cancelar(Estadia $e, string $motivo): void
    {
        if ($e->situacao !== 'patio') {
            self::erro('motivo', 'Só dá para cancelar estadia que ainda está no pátio. Depois da saída, cancele ou estorne a conta gerada.');
        }
        $e->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr($motivo, 0, 200)]);
        Atividades::registrar('estadia_cancelada', 'ticket '.$e->numero.' · '.$e->rotulo().' ('.$motivo.')');
    }
}
