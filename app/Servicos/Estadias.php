<?php

namespace App\Servicos;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\Pagamento;
use App\Models\TipoVeiculo;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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

    /** código do ticket (vai no QR): difícil de adivinhar */
    public static function novoCodigo(): string
    {
        do {
            $c = Str::lower(Str::random(16));
        } while (Estadia::withoutGlobalScopes()->where('codigo', $c)->exists());

        return $c;
    }

    /** recebe na hora; em dinheiro, confere o valor recebido e anota o troco no pagamento */
    private static function receber(array $ordens, array $d): Pagamento
    {
        $total = round(collect($ordens)->sum(fn ($o) => (float) $o->valor), 2);
        $forma = FormaPagamento::find((int) ($d['forma_pagamento_id'] ?? 0));
        $recebido = isset($d['valor_recebido']) && $d['valor_recebido'] !== '' && $d['valor_recebido'] !== null ? round((float) $d['valor_recebido'], 2) : null;
        if ($forma?->tipo === 'dinheiro' && $recebido !== null && $recebido + 0.001 < $total) {
            self::erro('valor_recebido', 'O valor recebido é menor que o total ('.Formato::moeda($total).').');
        }
        $p = Baixa::receberNaHora($ordens, (int) ($d['forma_pagamento_id'] ?? 0), (int) ($d['parcelas'] ?? 1));
        if ($forma?->tipo === 'dinheiro' && $recebido !== null && $recebido > $total) {
            $p->update(['observacao' => 'Recebido '.Formato::moeda($recebido).' · troco '.Formato::moeda($recebido - $total)]);
        }

        return $p;
    }

    /** contrato de mensalidade vigente na data, se o veículo for mensalista */
    public static function contratoVigente(?int $veiculoId, ?CarbonInterface $quando = null): ?MensalidadeContrato
    {
        return $veiculoId ? MensalidadeContrato::where('veiculo_id', $veiculoId)->vigentes($quando ?? now())->orderByDesc('inicio')->first() : null;
    }

    /** diárias da tabela de estadia do tipo do veículo (para marcar na entrada) */
    public static function diarias(int $tipoId, ?CarbonInterface $quando = null): Collection
    {
        $t = Precos::tarifa($tipoId, Ordens::unidadePadrao(), $quando ?? now());

        return $t ? $t->pacotes()->whereNotNull('duracao_min')->where('valor', '>', 0)->get() : collect();
    }

    /**
     * $d: placa (ou identificacao), modelo, tipo_veiculo_id (vem do cadastro se não informado; informado vale sobre o
     * cadastro), entrada, convenio_id, celular; pacote_id (diária marcada) e, com ela, pagar_agora + forma_pagamento_id
     * (+ parcelas, valor_recebido); confirmar_mensalista (o PDV avisa antes de abrir estadia de mensalista).
     */
    public static function entrar(array $d): Estadia
    {
        $placa = Placa::normalizar($d['placa'] ?? null);
        $ident = trim((string) ($d['identificacao'] ?? '')) ?: null;
        if (! $placa && ! $ident) {
            self::erro('placa', 'Informe a placa (ou, sem placa, uma identificação do veículo).');
        }
        $veiculo = $placa ? Vendas::veiculoDaPlaca($placa) : null;
        $tipoId = (int) ($d['tipo_veiculo_id'] ?? 0) ?: (int) $veiculo?->tipo_veiculo_id;
        $tipo = TipoVeiculo::where('ativo', true)->find($tipoId);
        if (! $tipo) {
            self::erro('tipo_veiculo_id', 'Escolha o tipo do veículo.');
        }
        $entrada = Carbon::parse($d['entrada'] ?? now())->seconds(0);
        // sem tabela de preço o valor não sai na saída: barra já na entrada
        if (! Precos::tarifa($tipoId, Ordens::unidadePadrao(), $entrada)) {
            self::erro('tipo_veiculo_id', 'Não há tabela de estadia para '.$tipo->nome.'. Cadastre em Configurações › Tabelas de estadia.');
        }
        if ($entrada->isAfter(now()->addMinutes(5))) {
            self::erro('entrada', 'A entrada não pode ser no futuro.');
        }
        $convenio = ! empty($d['convenio_id']) ? Convenio::where('ativo', true)->whereHas('regras', fn ($q) => $q->whereNull('servico_id'))->find($d['convenio_id']) : null;
        if (! empty($d['convenio_id']) && ! $convenio) {
            self::erro('convenio_id', 'Convênio não encontrado, inativo ou sem estadias.');
        }
        // diária marcada: preço fechado do pacote da tabela daquele tipo
        $pacote = null;
        if (! empty($d['pacote_id'])) {
            $pacote = self::diarias($tipoId, $entrada)->firstWhere('id', (int) $d['pacote_id'])
                ?? self::erro('pacote_id', 'Diária não encontrada na tabela deste tamanho de veículo.');
        }
        $pagarAgora = $pacote && ! empty($d['pagar_agora']);
        if ($pagarAgora && empty($d['forma_pagamento_id'])) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento da diária.');
        }
        if ($pagarAgora && $convenio) {
            self::erro('convenio_id', 'Diária paga na entrada não leva convênio.');
        }
        // pelo PDV: placa de mensalista só abre estadia se o operador confirmar
        $mensalista = (bool) self::contratoVigente($veiculo?->id, $entrada);
        if ($mensalista && request()?->attributes->get('aparelho') && empty($d['confirmar_mensalista'])) {
            self::erro('mensalista', 'Mensalista: '.($veiculo?->cliente?->nome ?? Placa::formatar($placa)).'. Mensalista não registra entrada; confirme só se for preciso (ex.: veículo fora do contrato).');
        }

        return DB::transaction(function () use ($placa, $ident, $veiculo, $tipoId, $entrada, $convenio, $pacote, $pagarAgora, $mensalista, $d) {
            if ($placa && Estadia::where('situacao', 'patio')->where('placa', $placa)->lockForUpdate()->exists()) {
                self::erro('placa', Placa::formatar($placa).' já está no pátio.');
            }
            $e = Estadia::create([
                'unidade_id' => Ordens::unidadePadrao(),
                'uuid' => (string) Str::uuid(),
                'numero' => Numeracao::proximo('estadia'),
                'codigo' => self::novoCodigo(),
                'placa' => $placa,
                'identificacao' => $placa ? null : mb_substr($ident, 0, 40),
                'modelo' => mb_substr(trim((string) ($d['modelo'] ?? '')), 0, 40) ?: ($veiculo?->modelo ? mb_substr($veiculo->modelo, 0, 40) : null),
                'pacote_id' => $pacote?->id,
                'valor_pacote' => $pacote?->valor,
                'veiculo_id' => $veiculo?->id,
                'cliente_id' => $veiculo?->cliente_id,
                'tipo_veiculo_id' => $tipoId,
                'celular' => $veiculo ? null : (preg_replace('/\D/', '', (string) ($d['celular'] ?? '')) ?: null),
                'entrada' => $entrada,
                'convenio_id' => $convenio?->id,
                'mensalista' => $mensalista,
                'criado_por' => Auth::id(),
            ]);
            if ($pagarAgora) {
                $o = Ordens::lancar(['tipo' => 'estadia', 'unidade_id' => $e->unidade_id, 'estadia_id' => $e->id, 'veiculo_id' => $e->veiculo_id, 'placa' => $e->placa,
                    'tipo_veiculo_id' => $tipoId, 'cliente_id' => $veiculo?->cliente_id, 'celular' => $e->celular, 'valor' => (float) $pacote->valor,
                    'data' => $entrada->toDateString(), 'vencimento' => $entrada->toDateString(),
                    'descricao' => mb_substr($pacote->nome.' '.$e->rotulo().' (paga na entrada)', 0, 80)]);
                self::receber([$o], $d);
                $e->update(['ordem_entrada_id' => $o->id, 'pago_entrada' => $pacote->valor]);
            }
            Atividades::registrar('estadia_entrada', 'ticket '.$e->numero.' · '.$e->rotulo().' · '.$entrada->format('d/m H:i')
                .($pacote ? ' · '.$pacote->nome.($pagarAgora ? ' paga na entrada' : '') : ''));

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
        // diária marcada: preço fechado, mesmo saindo antes; passou da duração, vale o que a tabela der (regra do maior pacote)
        if ($e->pacote_id && (float) $e->valor_pacote > $r['valor']) {
            $r['valor'] = round((float) $e->valor_pacote, 2);
            $r['detalhes'] = array_merge((array) $r['detalhes'], ['Diária marcada na entrada: '.Formato::moeda($r['valor'])]);
        }
        $div = Convenios::dividir($e->convenio()->with('cliente', 'regras')->first(), 'estadia', $r['valor'], $r['minutos'],
            fn (int $min) => $t->calcular($e->entrada, $e->entrada->copy()->addMinutes($min))['valor']);

        return [
            'saida' => $saida->format('Y-m-d H:i'),
            'minutos' => $r['minutos'],
            'tempo' => Formato::duracao($r['minutos']),
            'valor' => $r['valor'],
            'detalhes' => $r['detalhes'],
            'tarifa_id' => $tarifa->id,
            'tarifa' => $tarifa->nome,
            'pago_entrada' => (float) $e->pago_entrada,
            'a_pagar' => round(max(0, $div['cliente'] - (float) $e->pago_entrada), 2),
        ] + $div;
    }

    /**
     * Saída. $destino: pago (quem usou paga agora), conta (vai para a conta de um cliente: conta_cliente_id, padrão o
     * dono do veículo), mensalista (sem cobrança).
     * A parte do convênio sempre vai para a conta do convênio. Valor zero (tolerância) não gera ordem.
     * Também: convenio_id (o convênio é escolhido na saída; vazio tira), perdido + retirada_nome/retirada_documento
     * (ticket perdido), valor_recebido (dinheiro: troco). Diária paga na entrada: só cobra o que passou dela.
     */
    public static function sair(Estadia $e, array $d): Estadia
    {
        if ($e->situacao !== 'patio') {
            self::erro('saida', 'Esta estadia já foi finalizada ou cancelada.');
        }
        if (array_key_exists('convenio_id', $d)) {
            $novo = null;
            if (! empty($d['convenio_id'])) {
                if ((float) $e->pago_entrada > 0) {
                    self::erro('convenio_id', 'A diária já foi paga na entrada: não leva convênio.');
                }
                $novo = Convenio::where('ativo', true)->whereHas('regras', fn ($q) => $q->whereNull('servico_id'))->find($d['convenio_id'])
                    ?? self::erro('convenio_id', 'Convênio não encontrado, inativo ou sem estadias.');
            }
            if ((int) $e->convenio_id !== (int) $novo?->id) {
                $e->update(['convenio_id' => $novo?->id]);
                $e->unsetRelation('convenio');
            }
        }
        $retirada = null;
        if (! empty($d['perdido'])) {
            $nome = trim((string) ($d['retirada_nome'] ?? ''));
            $doc = trim((string) ($d['retirada_documento'] ?? ''));
            if (mb_strlen($nome) < 3 || mb_strlen($doc) < 4) {
                self::erro('retirada_nome', 'Ticket perdido: informe o nome e o número de um documento de quem está retirando.');
            }
            $retirada = ['retirada_nome' => mb_substr($nome, 0, 80), 'retirada_documento' => mb_substr($doc, 0, 30)];
        }
        $c = self::calcular($e, Carbon::parse($d['saida'] ?? now()));
        $destino = $d['destino'] ?? 'pago';
        if ($destino === 'mensalista' && ! $e->mensalista) {
            self::erro('destino', 'Este veículo não tem mensalidade vigente.');
        }
        $contaDe = null;
        if ($destino === 'conta') {
            $contaDe = Cliente::find((int) ($d['conta_cliente_id'] ?? 0) ?: (int) $e->cliente_id)
                ?? self::erro('conta_cliente_id', 'Escolha o cliente que vai pagar (na conta de quem).');
        }
        $cliente = $destino === 'mensalista' ? 0.0 : $c['a_pagar'];
        if ($cliente > 0 && $destino === 'pago' && empty($d['forma_pagamento_id'])) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }
        if (! in_array($destino, ['pago', 'conta', 'mensalista'], true)) {
            self::erro('destino', 'Escolha como fica o pagamento.');
        }

        return DB::transaction(function () use ($e, $c, $destino, $cliente, $contaDe, $retirada, $d) {
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
                $ordem = Ordens::lancar($base + ['cliente_id' => $contaDe?->id ?? $e->cliente_id, 'convenio_id' => $e->convenio_id, 'celular' => $e->celular, 'valor' => $cliente,
                    'descricao' => mb_substr('Estadia '.$periodo.($c['pago_entrada'] > 0 ? ' (além da diária paga)' : '').($c['desconto'] > 0 ? ' (desc. convênio '.Formato::moeda($c['desconto']).')' : ''), 0, 80)]);
                if ($destino === 'pago') {
                    self::receber([$ordem], $d);
                }
            }
            $final = match (true) {
                $destino === 'mensalista' => 'mensalista',
                $cliente > 0 => $destino,
                $c['convenio'] > 0 => 'convenio',
                $c['pago_entrada'] > 0 => 'pago_entrada',
                default => 'gratis',
            };
            $e->update([
                'saida' => $saida, 'minutos' => $c['minutos'], 'tarifa_id' => $c['tarifa_id'], 'valor_calculado' => $c['valor'],
                'valor_convenio' => $c['convenio'], 'desconto' => $c['desconto'], 'valor_cliente' => $cliente,
                'situacao' => 'finalizada', 'destino' => $final, 'detalhes' => $c['detalhes'],
                'ordem_id' => $ordem?->id, 'ordem_convenio_id' => $ordemConv?->id, 'finalizado_por' => Auth::id(),
            ] + ($retirada ?? []));
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
        if ((float) $e->pago_entrada > 0) {
            self::erro('motivo', 'A diária foi paga na entrada: estorne esse pagamento em Recebimentos antes de cancelar a estadia.');
        }
        $e->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'cancelada_por' => Auth::id(), 'motivo_cancelamento' => mb_substr($motivo, 0, 200)]);
        Atividades::registrar('estadia_cancelada', 'ticket '.$e->numero.' · '.$e->rotulo().' ('.$motivo.')');
    }
}
