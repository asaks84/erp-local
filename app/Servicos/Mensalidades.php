<?php

namespace App\Servicos;

use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Support\Atividades;
use App\Support\Formato;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ordens de mensalidade: geração do mês e primeira cobrança de quem acabou de entrar.
 * Mês comercial de 30 dias; nunca duas mensalidades do mesmo mês para o mesmo veículo.
 * Ver docs/05-regras-de-negocio.md (Mensalidade).
 */
class Mensalidades
{
    public const OPCOES_PRIMEIRA = [
        'proporcional' => 'Só o proporcional deste mês',
        'prop_proximo' => 'Proporcional + próximo mês',
        'integral' => 'Mês cheio agora',
        'proximo' => 'Cortesia neste mês (só o próximo)',
        'nenhuma' => 'Não gerar agora',
    ];

    /** opções que qualquer usuário pode escolher; as outras exigem "mudar valor de mensalidade" */
    public const OPCOES_LIVRES = ['proporcional', 'prop_proximo', 'integral'];

    /** proporcional = mensalidade × (30 − min(dia, 30) + 1) / 30. Devolve [valor, dias, "dd/mm", "dd/mm"] */
    public static function proporcional(float $mensal, Carbon $data): array
    {
        $dia = min($data->day, 30);
        $dias = 30 - $dia + 1;

        return [round($mensal * $dias / 30, 2), $dias, $data->format('d/m'), $data->copy()->endOfMonth()->format('d/m')];
    }

    /** vencimento no mês ("2026-11") no dia do cliente; dia 31 vira o último dia do mês */
    public static function vencimentoNoMes(int $dia, string $competencia): Carbon
    {
        $mes = Carbon::parse($competencia.'-01');
        $dia = ($dia >= 1 && $dia <= 31) ? $dia : 5;

        return $mes->copy()->day(min($dia, $mes->daysInMonth));
    }

    public static function competenciaValida(?string $c): bool
    {
        if (! $c || ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $c, $m)) {
            return false;
        }

        return (int) $m[1] >= 2000 && $c <= now()->addMonths(12)->format('Y-m');
    }

    /** a versão do contrato que vale na data, ou a mais nova que encosta no mês */
    private static function versaoDoMes(Collection $versoes, Carbon $vencimento): MensalidadeContrato
    {
        $dia = $vencimento->toDateString();

        return $versoes->first(fn ($c) => $c->inicio->toDateString() <= $dia && (! $c->fim || $c->fim->toDateString() >= $dia))
            ?? $versoes->sortByDesc(fn ($c) => $c->inicio->toDateString())->first();
    }

    /** primeiro dia da sequência de versões (reajustes) até este contrato */
    private static function inicioDaCadeia(MensalidadeContrato $c, Collection $todos): string
    {
        $inicio = $c->inicio->toDateString();
        $atual = $c;
        $voltas = 0;
        while ($atual->anterior_id && ($ant = $todos->get($atual->anterior_id)) && $voltas++ < 100) {
            $inicio = $ant->inicio->toDateString();
            $atual = $ant;
        }

        return $inicio;
    }

    /**
     * Prévia da geração de um mês: cada veículo mensalista com o que vai acontecer.
     * situacao: gerar, inicio_no_mes (começou no mês: confira a primeira cobrança), pausa (taxa de reserva),
     * pausa_gratis, ja_gerada, coberta (a primeira cobrança já gerou um mês seguinte).
     */
    public static function previa(string $competencia): Collection
    {
        $ini = Carbon::parse($competencia.'-01')->startOfDay();
        $fim = $ini->copy()->endOfMonth()->startOfDay();

        $versoes = MensalidadeContrato::with(['cliente', 'veiculo', 'pausas'])
            ->where('inicio', '<=', $fim->toDateString())
            ->where(fn ($q) => $q->whereNull('fim')->orWhere('fim', '>=', $ini->toDateString()))
            ->get();
        $veiculos = $versoes->pluck('veiculo_id')->unique()->values();
        $todos = MensalidadeContrato::whereIn('veiculo_id', $veiculos)->get(['id', 'anterior_id', 'inicio'])->keyBy('id');

        $existentes = OrdemServico::where('tipo', 'mensalidade')->where('situacao', '<>', 'cancelada')
            ->whereIn('veiculo_id', $veiculos)->where('competencia', '>=', $competencia)
            ->get(['id', 'numero', 'veiculo_id', 'competencia', 'data', 'valor']);

        $linhas = collect();
        foreach ($versoes->groupBy('veiculo_id') as $veiculoId => $doVeiculo) {
            $primeira = $doVeiculo->first();
            $cliente = $primeira->cliente;
            $vencimento = self::vencimentoNoMes((int) $cliente->dia_vencimento, $competencia);
            $c = self::versaoDoMes($doVeiculo, $vencimento);
            $valor = (float) $c->valor;
            $situacao = 'gerar';
            $detalhe = null;

            $ja = $existentes->first(fn ($o) => $o->veiculo_id == $veiculoId && $o->competencia === $competencia);
            $depois = $existentes->first(fn ($o) => $o->veiculo_id == $veiculoId && $o->competencia > $competencia && $o->data->lte($fim));
            $pausa = $c->pausaEm($ini);

            if ($ja) {
                $situacao = 'ja_gerada';
                $detalhe = 'Ordem #'.$ja->numero.' ('.Formato::moeda($ja->valor).')';
            } elseif ($depois) {
                $situacao = 'coberta';
                $detalhe = 'A primeira cobrança já gerou '.Carbon::parse($depois->competencia.'-01')->format('m/Y');
            } elseif ($pausa) {
                $taxa = $pausa->taxaSobre($valor);
                $situacao = $taxa > 0 ? 'pausa' : 'pausa_gratis';
                $detalhe = $taxa > 0 ? 'Pausado: taxa de reserva de '.Formato::moeda($taxa).' no lugar de '.Formato::moeda($valor) : 'Pausado sem taxa';
                $valor = $taxa;
            } elseif (! $c->legado_id && self::inicioDaCadeia($c, $todos) >= $ini->toDateString()) {
                // (contrato importado não entra aqui: o sistema atual não guardava o início, e quem
                // entrou no mês lá já teve a primeira cobrança gerada, que aparece como "já gerada")
                $situacao = 'inicio_no_mes';
                $detalhe = 'Começou em '.Carbon::parse(self::inicioDaCadeia($c, $todos))->format('d/m/Y').': confira a primeira cobrança antes de gerar o mês cheio';
            } elseif ($c->fim && $c->fim->lt($vencimento)) {
                $detalhe = 'Última mensalidade: termina em '.$c->fim->format('d/m/Y');
            }

            $linhas->push([
                'contrato_id' => $c->id,
                'unidade_id' => $c->unidade_id,
                'veiculo_id' => (int) $veiculoId,
                'cliente_id' => $cliente->id,
                'cliente' => $cliente->nome,
                'veiculo' => $c->veiculo->rotulo(),
                'veiculo_descricao' => trim($c->veiculo->marca.' '.$c->veiculo->modelo),
                'periodo' => $c->periodo,
                'valor' => round($valor, 2),
                'vencimento' => $vencimento->toDateString(),
                'situacao' => $situacao,
                'detalhe' => $detalhe,
                'selecionavel' => in_array($situacao, ['gerar', 'pausa', 'inicio_no_mes']),
                'marcar' => in_array($situacao, ['gerar', 'pausa']),
            ]);
        }

        return $linhas->sortBy([['cliente', 'asc'], ['veiculo', 'asc']])->values();
    }

    /** Gera as mensalidades do mês para os contratos escolhidos. Devolve [quantidade, total]. */
    public static function gerar(string $competencia, array $contratoIds): array
    {
        if (! self::competenciaValida($competencia)) {
            throw ValidationException::withMessages(['competencia' => 'Mês inválido.']);
        }
        $escolhidos = array_map('intval', $contratoIds);
        $linhas = self::previa($competencia)->filter(fn ($l) => $l['selecionavel'] && in_array($l['contrato_id'], $escolhidos, true));

        $qtd = 0;
        $total = 0.0;
        DB::transaction(function () use ($linhas, $competencia, &$qtd, &$total) {
            foreach ($linhas as $l) {
                if ($l['valor'] <= 0) {
                    continue;
                }
                Ordens::lancar([
                    'tipo' => 'mensalidade',
                    'unidade_id' => $l['unidade_id'],
                    'veiculo_id' => $l['veiculo_id'],
                    'contrato_id' => $l['contrato_id'],
                    'competencia' => $competencia,
                    'vencimento' => $l['vencimento'],
                    'valor' => $l['valor'],
                    'descricao' => $l['situacao'] === 'pausa' ? 'Mensalidade (reserva de vaga em pausa)' : 'Mensalidade',
                ]);
                $qtd++;
                $total += $l['valor'];
            }
        });
        if ($qtd) {
            Atividades::registrar('mensalidades_geradas', Carbon::parse($competencia.'-01')->format('m/Y').': '.$qtd.' mensalidade(s), '.Formato::moeda($total));
        }

        return [$qtd, round($total, 2)];
    }

    /**
     * Primeira cobrança de um veículo que acabou de virar mensalista.
     * $valorProporcional: o proporcional confirmado (ou ajustado) na tela; vazio = o calculado.
     * Devolve as ordens geradas.
     */
    public static function primeiraCobranca(MensalidadeContrato $c, string $opcao, ?float $valorProporcional = null, ?Carbon $hoje = null): array
    {
        if (! array_key_exists($opcao, self::OPCOES_PRIMEIRA)) {
            throw ValidationException::withMessages(['opcao' => 'Escolha como fica a primeira cobrança.']);
        }
        $hoje = ($hoje ?? now())->copy()->startOfDay();
        $mensal = round((float) $c->valor, 2);
        if ($mensal <= 0 || $opcao === 'nenhuma') {
            return [];
        }
        [$calculado, , $de, $ate] = self::proporcional($mensal, $hoje);
        $prop = $valorProporcional === null ? $calculado : round($valorProporcional, 2);
        if ($prop <= 0 || $prop > $mensal) {
            throw ValidationException::withMessages(['valor' => 'O proporcional precisa ficar entre R$ 0,01 e o valor da mensalidade.']);
        }

        $mesAtual = $hoje->format('Y-m');
        $proximo = $hoje->copy()->startOfMonth()->addMonth()->format('Y-m');
        $dia = (int) $c->cliente->dia_vencimento;
        $itens = match ($opcao) {
            'integral' => [['Mensalidade', $mensal, $hoje->toDateString(), $mesAtual]],
            'proporcional' => [["Mensalidade proporcional {$de} a {$ate}", $prop, $hoje->toDateString(), $mesAtual]],
            'prop_proximo' => [
                ["Mensalidade proporcional {$de} a {$ate}", $prop, $hoje->toDateString(), $mesAtual],
                ['Mensalidade', $mensal, self::vencimentoNoMes($dia, $proximo)->toDateString(), $proximo],
            ],
            'proximo' => [['Mensalidade', $mensal, self::vencimentoNoMes($dia, $proximo)->toDateString(), $proximo]],
        };

        return DB::transaction(function () use ($c, $itens) {
            $geradas = [];
            foreach ($itens as [$descricao, $valor, $vencimento, $competencia]) {
                $existe = OrdemServico::where('tipo', 'mensalidade')->where('veiculo_id', $c->veiculo_id)
                    ->where('competencia', $competencia)->where('situacao', '<>', 'cancelada')->exists();
                if ($existe) {
                    continue; // já existe a mensalidade desse mês
                }
                $o = Ordens::lancar([
                    'tipo' => 'mensalidade',
                    'unidade_id' => $c->unidade_id,
                    'veiculo_id' => $c->veiculo_id,
                    'contrato_id' => $c->id,
                    'competencia' => $competencia,
                    'vencimento' => $vencimento,
                    'valor' => $valor,
                    'descricao' => $descricao,
                ]);
                Atividades::registrar('mensalidade_gerada', $c->veiculo->rotulo().': #'.$o->numero.' '.$descricao.', '.Formato::moeda($valor).', vencimento '.Carbon::parse($vencimento)->format('d/m/Y'));
                $geradas[] = $o;
            }

            return $geradas;
        });
    }
}
