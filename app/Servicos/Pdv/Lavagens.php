<?php

namespace App\Servicos\Pdv;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\OrdemEtapa;
use App\Models\OrdemServico;
use App\Servicos\Baixa;
use App\Servicos\Convenios;
use App\Servicos\EtapasLavagem;
use App\Servicos\Ordens;
use App\Servicos\Precos;
use App\Servicos\Tarifador;
use App\Servicos\Vendas;
use App\Support\Atividades;
use App\Support\Formato;
use App\Support\Placa;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lavagem no PDV (parte 8.4): etapas, entrega com convênio escolhido na hora, conta de cliente e a estadia
 * cobrada depois que a lavagem ficou pronta (configurável). A venda em si é a mesma da gestão (Vendas::vender).
 * Ver docs/15-plano-pdv.md (8b e 8f).
 */
class Lavagens
{
    private static function erro(string $campo, string $msg): never
    {
        throw ValidationException::withMessages([$campo => $msg]);
    }

    /** configuração da estadia depois de pronta: [cobrar, tolerância em minutos] */
    public static function regraEstadia(): array
    {
        $e = Empresa::atual();

        return ['cobrar' => (bool) $e->config('lavagem_estadia_cobrar'), 'tolerancia_min' => (int) ($e->config('lavagem_estadia_tolerancia_min') ?? 30)];
    }

    /** a etapa "pronta": a última ativa antes de Entregue */
    public static function etapaPronta(): ?string
    {
        $ativas = array_keys(EtapasLavagem::ativas());
        $meio = array_values(array_diff($ativas, [EtapasLavagem::ULTIMA]));

        return count($meio) > 1 ? end($meio) : null; // só "aguardando": não há etapa de pronta
    }

    /** próxima etapa ativa (sem pular para Entregue: entregar é pela entrega) */
    public static function proximaEtapa(string $atual): ?string
    {
        $ativas = array_keys(EtapasLavagem::ativas());
        $i = array_search($atual, $ativas, true);
        $prox = $i === false ? ($ativas[1] ?? null) : ($ativas[$i + 1] ?? null);

        return $prox === EtapasLavagem::ULTIMA ? null : $prox;
    }

    /** quando a lavagem chegou na etapa pronta (se está nela) */
    public static function prontaEm(OrdemServico $principal): ?CarbonInterface
    {
        $pronta = self::etapaPronta();
        if (! $pronta || $principal->etapa !== $pronta) {
            return null;
        }

        return OrdemEtapa::where('ordem_id', $principal->id)->where('etapa', $pronta)->orderByDesc('quando')->first()?->quando;
    }

    /**
     * Estadia depois de pronta: com a regra ligada e passada a tolerância (contada de quando ficou pronta),
     * todo o tempo desde que ficou pronta é cobrado pela tabela de estadia do tamanho do veículo.
     */
    public static function estadiaDepois(OrdemServico $principal, ?CarbonInterface $agora = null): ?array
    {
        $regra = self::regraEstadia();
        $desde = self::prontaEm($principal);
        if (! $regra['cobrar'] || ! $desde || ! $principal->tipo_veiculo_id) {
            return null;
        }
        // o carro continua em estadia (a lavagem foi pedida com ele no pátio): o tempo já é cobrado por ela
        if ($principal->placa && Estadia::where('situacao', 'patio')->where('placa', $principal->placa)->exists()) {
            return null;
        }
        $agora ??= now();
        $min = (int) floor($desde->diffInMinutes($agora));
        if ($min <= $regra['tolerancia_min']) {
            return null;
        }
        $tarifa = Precos::tarifa($principal->tipo_veiculo_id, $principal->unidade_id, $agora);
        if (! $tarifa) {
            return null;
        }
        $t = new Tarifador($tarifa->regras());
        $r = $t->calcular($desde, $agora);
        if ($r['valor'] <= 0) {
            return null;
        }

        return ['minutos' => $min, 'tempo' => Formato::duracao($min), 'valor' => round($r['valor'], 2), 'desde' => $desde->format('d/m H:i'),
            'valor_ate' => fn (int $m) => $t->calcular($desde, $desde->copy()->addMinutes($m))['valor']];
    }

    /** o que está em aberto na venda para pagar no balcão (sem dono: avulso) e o que já foi pago */
    private static function abertas(Collection $grupo): Collection
    {
        return $grupo->where('situacao', 'aberta')->whereNull('cliente_id')->values();
    }

    /**
     * Cálculo da entrega (sem gravar): serviços em aberto, a parte do convênio escolhido (por serviço), estadia
     * depois de pronta e o que o cliente paga. Contas que já estão na conta de alguém ou do convênio não entram.
     */
    public static function calculo(OrdemServico $o, ?int $convenioId = null, ?CarbonInterface $agora = null): array
    {
        $agora ??= now();
        $grupo = Vendas::grupo($o);
        $principal = $grupo->first();
        $convenio = $convenioId ? self::convenio($convenioId) : null;
        $itens = [];
        foreach (self::abertas($grupo) as $x) {
            $div = Convenios::dividir($convenio, 'servico', (float) $x->valor, 0, null, $x->servico_id);
            $itens[] = ['id' => $x->id, 'descricao' => $x->descricao, 'valor' => (float) $x->valor, 'convenio' => $div['convenio'], 'cliente' => $div['cliente'], 'regra' => $div['regra']];
        }
        $est = self::estadiaDepois($principal, $agora);
        $estadia = null;
        if ($est) {
            $div = Convenios::dividir($convenio, 'estadia', $est['valor'], $est['minutos'], $est['valor_ate']);
            $estadia = ['tempo' => $est['tempo'], 'desde' => $est['desde'], 'minutos' => $est['minutos'], 'valor' => $est['valor'], 'convenio' => $div['convenio'], 'cliente' => $div['cliente']];
        }
        $pagas = $grupo->where('situacao', 'paga');
        $naConta = $grupo->where('situacao', 'aberta')->whereNotNull('cliente_id')->load('cliente:id,nome');
        $soma = fn (string $k) => round(collect($itens)->sum($k) + ($estadia[$k] ?? 0), 2);

        return [
            'itens' => $itens,
            // o PDV devolve na entrega: o preço fica o da tela, mesmo que o Pix demore
            'calculado_em' => $agora->format('Y-m-d H:i:s'),
            'estadia' => $estadia,
            'pago_antes' => round((float) $pagas->sum('valor'), 2),
            'na_conta' => round((float) $naConta->sum('valor'), 2),
            'na_conta_de' => $naConta->first()?->cliente?->nome,
            'total' => $soma('valor'),
            'convenio' => $soma('convenio'),
            'a_pagar' => $soma('cliente'),
            'regras_convenio' => collect($itens)->pluck('regra')->push($estadia ? Convenios::dividir($convenio, 'estadia', 1)['regra'] : '')->filter()->unique()->values()->all(),
        ];
    }

    private static function convenio(int $id): Convenio
    {
        return Convenio::with('cliente', 'regras')->where('ativo', true)->find($id) ?? self::erro('convenio_id', 'Convênio não encontrado ou inativo.');
    }

    /**
     * Entrega: aplica o convênio escolhido (a parte dele vai para a conta do convênio; o excedente fica na venda),
     * lança a estadia depois de pronta, e o resto: pago agora, na conta de um cliente, ou nada (já pago).
     * $d: convenio_id, destino (pago | conta), conta_cliente_id, forma_pagamento_id, parcelas, valor_recebido.
     * Devolve [principal, pagamento|null, troco, mensagem].
     */
    public static function entregar(OrdemServico $o, array $d): array
    {
        $grupo = Vendas::grupo($o);
        $principal = $grupo->first();
        if ($grupo->every(fn ($x) => $x->situacao === 'cancelada')) {
            self::erro('entrega', 'Venda cancelada.');
        }
        if ($principal->etapa === EtapasLavagem::ULTIMA) {
            self::erro('entrega', 'Esta lavagem já foi entregue.');
        }
        $convenio = ! empty($d['convenio_id']) ? self::convenio((int) $d['convenio_id']) : null;
        $c = self::calculo($principal, $convenio?->id, $d['agora'] ?? null);
        $destino = $d['destino'] ?? 'pago';
        $contaDe = null;
        if ($c['a_pagar'] > 0 && $destino === 'conta') {
            $contaDe = Cliente::find((int) ($d['conta_cliente_id'] ?? 0)) ?? self::erro('conta_cliente_id', 'Escolha o cliente que vai pagar (na conta de quem).');
        }
        if ($c['a_pagar'] > 0 && $destino === 'pago' && empty($d['forma_pagamento_id'])) {
            self::erro('forma_pagamento_id', 'Escolha a forma de pagamento.');
        }
        $recebido = isset($d['valor_recebido']) && $d['valor_recebido'] !== null ? round((float) $d['valor_recebido'], 2) : null;
        $forma = ! empty($d['forma_pagamento_id']) ? FormaPagamento::find($d['forma_pagamento_id']) : null;
        if ($c['a_pagar'] > 0 && $destino === 'pago' && $forma?->tipo === 'dinheiro' && $recebido !== null && $recebido + 0.001 < $c['a_pagar']) {
            self::erro('valor_recebido', 'O valor recebido é menor que o total ('.Formato::moeda($c['a_pagar']).').');
        }

        return DB::transaction(function () use ($grupo, $principal, $convenio, $c, $destino, $contaDe, $recebido, $forma, $d) {
            $doCliente = [];
            // serviços em aberto: com convênio, a parte dele vai para a conta do convênio e o resto fica na venda
            foreach ($c['itens'] as $i) {
                $x = $grupo->firstWhere('id', $i['id']);
                if ($i['convenio'] > 0) {
                    $x->update(['cliente_id' => $convenio->cliente_id, 'convenio_id' => $convenio->id, 'valor' => $i['convenio'],
                        'descricao' => mb_substr($x->descricao.' · '.Placa::formatar($x->placa).' (convênio)', 0, 80)]);
                    if ($i['cliente'] > 0) {
                        $doCliente[] = Ordens::lancar(['tipo' => 'servico', 'servico_id' => $x->servico_id, 'principal_id' => $principal->id, 'unidade_id' => $x->unidade_id,
                            'veiculo_id' => $x->veiculo_id, 'placa' => $x->placa, 'tipo_veiculo_id' => $x->tipo_veiculo_id, 'convenio_id' => $convenio->id,
                            'valor' => $i['cliente'], 'data' => now()->toDateString(), 'vencimento' => now()->toDateString(), 'etapa' => $principal->etapa,
                            'descricao' => mb_substr(preg_replace('/ \(.*\)$/', '', $x->descricao).' (excedente do convênio)', 0, 80)]);
                    }
                } else {
                    $doCliente[] = $x;
                }
            }
            // estadia depois de pronta
            if ($c['estadia']) {
                $e = $c['estadia'];
                $base = ['tipo' => 'estadia', 'principal_id' => $principal->id, 'unidade_id' => $principal->unidade_id, 'veiculo_id' => $principal->veiculo_id,
                    'placa' => $principal->placa, 'tipo_veiculo_id' => $principal->tipo_veiculo_id, 'data' => now()->toDateString(), 'vencimento' => now()->toDateString(),
                    'etapa' => $principal->etapa];
                $desc = 'Estadia após a lavagem pronta ('.$e['tempo'].')';
                if ($e['convenio'] > 0) {
                    Ordens::lancar($base + ['cliente_id' => $convenio->cliente_id, 'convenio_id' => $convenio->id, 'valor' => $e['convenio'], 'descricao' => mb_substr($desc.' (convênio)', 0, 80)]);
                }
                if ($e['cliente'] > 0) {
                    $doCliente[] = Ordens::lancar($base + ['convenio_id' => $convenio?->id, 'valor' => $e['cliente'], 'descricao' => mb_substr($desc, 0, 80)]);
                }
            }
            $pagamento = null;
            $troco = 0.0;
            $total = round(collect($doCliente)->sum(fn ($x) => (float) $x->valor), 2);
            if ($doCliente && $destino === 'conta') {
                foreach ($doCliente as $x) {
                    $x->update(['cliente_id' => $contaDe->id]);
                }
            } elseif ($doCliente) {
                $pagamento = Baixa::receberNaHora($doCliente, (int) $d['forma_pagamento_id'], (int) ($d['parcelas'] ?? 1));
                if ($forma?->tipo === 'dinheiro' && $recebido !== null && $recebido > $total) {
                    $troco = round($recebido - $total, 2);
                    $pagamento->update(['observacao' => 'Recebido '.Formato::moeda($recebido).' · troco '.Formato::moeda($troco)]);
                }
            }
            Vendas::etapa($principal->fresh(), EtapasLavagem::ULTIMA);
            $msg = 'Lavagem de '.Placa::formatar($principal->placa).' entregue'
                .($pagamento ? ' · pago '.Formato::moeda($total) : ($contaDe ? ' · '.Formato::moeda($total).' na conta de '.$contaDe->nome : ''))
                .($c['convenio'] > 0 ? ' · '.Formato::moeda($c['convenio']).' na conta do convênio' : '');
            Atividades::registrar('lavagem_entrega', $msg);

            return [$principal->fresh(), $pagamento, $troco, $msg];
        });
    }
}
