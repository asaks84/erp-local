<?php

namespace App\Servicos;

use App\Support\Formato;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Valor de uma estadia pelas regras de uma tabela de estacionamento.
 * Mesma regra do sistema atual (application/libraries/Tarifador.php), agora num lugar só
 * para gestão, PDV e relatórios. Ver docs/05-regras-de-negocio.md.
 *
 *  - tolerância: minutos iniciais grátis
 *  - fração: minutos de cortesia antes de cobrar o próximo bloco
 *  - faixas: em sequência; "fixo" (valor único pela faixa) ou "bloco" (a cada N minutos);
 *    duração vazia = até o fim (a última sempre vai até o fim)
 *  - pacotes: diárias/períodos com preço fechado, servem de teto; com hora de início e fim
 *    viram pernoite/período (preço fechado se a estadia couber toda na janela)
 *  - restante: acima do maior pacote, "faixas" (volta às faixas, com teto nos pacotes)
 *    ou "pacotes" (só pacotes inteiros)
 *  - sempre a combinação mais barata para o cliente
 */
class Tarifador
{
    private array $t;

    private array $memo = [];

    public function __construct(array $tabela)
    {
        $this->t = [
            'tolerancia' => max(0, (int) ($tabela['tolerancia'] ?? 0)),
            'fracao' => max(0, (int) ($tabela['fracao'] ?? 0)),
            'restante' => ($tabela['restante'] ?? 'faixas') === 'pacotes' ? 'pacotes' : 'faixas',
            'faixas' => [],
            'pacotes' => [],
            'janelas' => [],
        ];
        foreach ($tabela['faixas'] ?? [] as $f) {
            $dur = $f['duracao'] ?? null;
            $this->t['faixas'][] = [
                'duracao' => ($dur === null || $dur === '' || (int) $dur <= 0) ? null : (int) $dur,
                'modo' => ($f['modo'] ?? 'bloco') === 'fixo' ? 'fixo' : 'bloco',
                'bloco' => max(1, (int) ($f['bloco'] ?? 60)),
                'valor' => round((float) ($f['valor'] ?? 0), 2),
            ];
        }
        foreach ($tabela['pacotes'] ?? [] as $p) {
            $ini = self::hora($p['hora_inicio'] ?? null);
            $fim = self::hora($p['hora_fim'] ?? null);
            $pac = [
                'nome' => trim((string) ($p['nome'] ?? 'Diária')) ?: 'Diária',
                'duracao' => (int) ($p['duracao'] ?? 0),
                'valor' => round((float) ($p['valor'] ?? 0), 2),
            ];
            if ($ini !== null && $fim !== null) {
                $this->t['janelas'][] = $pac + ['inicio' => $ini, 'fim' => $fim];
            } elseif ($pac['duracao'] > 0) {
                $this->t['pacotes'][] = $pac;
            }
        }
    }

    /**
     * Valor entre entrada e saída.
     *
     * @return array{valor: float, minutos: int, detalhes: string[]}
     */
    public function calcular(CarbonInterface|string $entrada, CarbonInterface|string $saida): array
    {
        $e = $entrada instanceof CarbonInterface ? $entrada : Carbon::parse($entrada);
        $s = $saida instanceof CarbonInterface ? $saida : Carbon::parse($saida);
        if ($s->lt($e)) {
            throw new \InvalidArgumentException('A saída precisa ser depois da entrada.');
        }
        $min = intdiv($s->getTimestamp() - $e->getTimestamp(), 60);

        $r = $this->porMinutos($min);
        foreach ($this->t['janelas'] as $j) {
            if ($min > $this->t['tolerancia'] && $this->dentroJanela($e, $s, $j) && $j['valor'] < $r['valor']) {
                $r = ['valor' => $j['valor'], 'detalhes' => [$j['nome'].' ('.$j['inicio'].' às '.$j['fim'].'): '.Formato::moeda($j['valor'])]];
            }
        }

        return ['valor' => $r['valor'], 'minutos' => $min, 'detalhes' => $r['detalhes']];
    }

    /** Valor só pelo tempo de permanência (sem as janelas de horário). */
    public function porMinutos(int $min): array
    {
        $min = max(0, $min);
        if ($min <= $this->t['tolerancia']) {
            return ['valor' => 0.0, 'detalhes' => ['Dentro da tolerância ('.$this->t['tolerancia'].' min): sem cobrança']];
        }
        [$valor, $detalhes] = $this->agrupar($this->custo($min, true));

        return ['valor' => round($valor, 2), 'detalhes' => $detalhes];
    }

    private function custo(int $min, bool $primeiro): array
    {
        if ($min <= 0) {
            return [0.0, []];
        }
        $k = $min.'|'.(int) $primeiro;
        if (isset($this->memo[$k])) {
            return $this->memo[$k];
        }

        $maior = 0;
        foreach ($this->t['pacotes'] as $p) {
            $maior = max($maior, $p['duracao']);
        }

        $melhor = null;
        if ($this->t['faixas'] && ($this->t['restante'] === 'faixas' || ($primeiro && ($maior === 0 || $min <= $maior)))) {
            $melhor = $this->faixas($min);
        }
        foreach ($this->t['pacotes'] as $p) {
            $linha = $p['nome'].': '.Formato::moeda($p['valor']);
            if ($min <= $p['duracao']) {
                $c = [$p['valor'], [$linha]];
            } else {
                $resto = $this->custo($min - $p['duracao'], false);
                $c = [$p['valor'] + $resto[0], array_merge([$linha], $resto[1])];
            }
            if ($melhor === null || $c[0] < $melhor[0] - 0.0001) {
                $melhor = $c;
            }
        }

        return $this->memo[$k] = $melhor ?? [0.0, ['Tabela sem regras de cobrança']];
    }

    private function faixas(int $min): array
    {
        $resto = $min;
        $total = 0.0;
        $det = [];
        $n = count($this->t['faixas']);
        foreach ($this->t['faixas'] as $i => $f) {
            if ($resto <= 0) {
                break;
            }
            $ultima = $i === $n - 1;
            $usa = ($ultima || $f['duracao'] === null) ? $resto : min($resto, $f['duracao']);
            if ($f['modo'] === 'fixo') {
                $total += $f['valor'];
                $det[] = self::rotuloTempo($usa).': '.Formato::moeda($f['valor']);
            } else {
                $blocos = intdiv($usa, $f['bloco']);
                $sobra = $usa - $blocos * $f['bloco'];
                // cortesia: sobra pequena não vira outro bloco (a não ser que nada tenha sido cobrado ainda)
                if ($sobra > $this->t['fracao'] || ($blocos === 0 && $sobra > 0 && $total == 0)) {
                    $blocos++;
                }
                if ($blocos > 0) {
                    $total += $blocos * $f['valor'];
                    $det[] = $blocos.' × '.self::rotuloBloco($f['bloco']).' × '.Formato::moeda($f['valor']).' = '.Formato::moeda($blocos * $f['valor']);
                }
            }
            $resto -= $usa;
            if ($f['duracao'] === null) {
                break;
            }
        }

        return [$total, $det];
    }

    /** junta linhas repetidas de pacote: "Diária: R$ 50" ×3 → "3 × Diária: R$ 150,00" */
    private function agrupar(array $r): array
    {
        $cont = [];
        foreach ($r[1] as $l) {
            $cont[$l] = ($cont[$l] ?? 0) + 1;
        }
        $out = [];
        foreach ($cont as $l => $n) {
            if ($n > 1 && preg_match('/^(.*): R\$ ([\d.,]+)$/', $l, $m)) {
                $v = (float) str_replace(['.', ','], ['', '.'], $m[2]) * $n;
                $out[] = $n.' × '.$m[1].': '.Formato::moeda($v);
            } else {
                array_push($out, ...array_fill(0, $n, $l));
            }
        }

        return [$r[0], $out];
    }

    private function dentroJanela(CarbonInterface $e, CarbonInterface $s, array $j): bool
    {
        [$hi, $mi] = array_map('intval', explode(':', $j['inicio']));
        [$hf, $mf] = array_map('intval', explode(':', $j['fim']));
        $ini = $hi * 60 + $mi;
        $fim = $hf * 60 + $mf;
        $dur = ($fim - $ini + 1440) % 1440 ?: 1440;
        $minE = $e->hour * 60 + $e->minute;
        $comeco = $e->copy()->startOfDay()->addMinutes($ini);
        if ($ini > $fim && $minE < $fim) {
            $comeco->subDay(); // janela que vira a meia-noite
        }

        return $e->gte($comeco) && $s->lte($comeco->copy()->addMinutes($dur));
    }

    private static function rotuloTempo(int $min): string
    {
        if ($min % 60 === 0) {
            return ($min / 60).'h';
        }

        return $min >= 60 ? intdiv($min, 60).'h'.sprintf('%02d', $min % 60) : $min.' min';
    }

    private static function rotuloBloco(int $b): string
    {
        return match (true) {
            $b === 1 => 'minuto',
            $b === 60 => 'hora',
            $b % 60 === 0 => ($b / 60).'h',
            default => $b.' min',
        };
    }

    public static function hora(?string $h): ?string
    {
        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $h), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
    }
}
