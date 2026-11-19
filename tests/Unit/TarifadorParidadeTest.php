<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioRegra;
use App\Servicos\Convenios;
use App\Servicos\Tarifador;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Tarifador do servidor x tarifador do celular (modo sem internet, 8.7). Casos sorteados (sempre os mesmos) ficam
 * em tests/js/fixtures/tarifador.json; este teste confere que o PHP ainda dá o que está no arquivo e o
 * tests/js/tarifador.test.mjs confere o JavaScript contra o mesmo arquivo.
 *
 * Mudou a regra do Tarifador de propósito? Gere de novo (PDV_GERAR_FIXTURE=1 php artisan test --filter=TarifadorParidade),
 * ajuste resources/js/pdv/offline/tarifador.js e rode `npm run test:js`.
 */
class TarifadorParidadeTest extends TestCase
{
    private const ARQUIVO = __DIR__.'/../js/fixtures/tarifador.json';

    private const CASOS = 400;

    public function test_casos_do_tarifador_batem_com_o_arquivo_do_javascript(): void
    {
        $casos = self::gerar();
        if (getenv('PDV_GERAR_FIXTURE')) {
            @mkdir(dirname(self::ARQUIVO), 0777, true);
            file_put_contents(self::ARQUIVO, json_encode($casos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
        }
        $this->assertFileExists(self::ARQUIVO, 'Gere o arquivo: PDV_GERAR_FIXTURE=1 php artisan test --filter=TarifadorParidade');
        $salvo = json_decode((string) file_get_contents(self::ARQUIVO), true);
        $this->assertCount(self::CASOS, $salvo);
        foreach ($casos as $i => $c) {
            $this->assertSame($salvo[$i]['esperado'], json_decode(json_encode($c['esperado'], JSON_PRESERVE_ZERO_FRACTION), true),
                "Caso {$i} mudou no PHP. Se foi de propósito, gere o arquivo de novo e ajuste o tarifador do celular.");
        }
    }

    private static function gerar(): array
    {
        mt_srand(8702);
        $casos = [];
        for ($i = 0; $i < self::CASOS; $i++) {
            $tabela = self::tabela();
            $entrada = Carbon::create(2026, 11, 1, 0, 0, 0, 'America/Sao_Paulo')->addMinutes(mt_rand(0, 10 * 1440));
            $dur = match (mt_rand(0, 4)) {
                0 => mt_rand(0, 30),
                1 => mt_rand(0, 300),
                2 => mt_rand(300, 1500),
                3 => mt_rand(1400, 4 * 1440),
                default => mt_rand(0, 2 * 1440),
            };
            $saida = $entrada->copy()->addMinutes($dur);
            $estadia = ['entrada' => $entrada->format('Y-m-d H:i'), 'pacote_id' => null, 'valor_pacote' => null, 'pago_entrada' => 0];
            if (mt_rand(0, 5) === 0) {
                $estadia['pacote_id'] = 1;
                $estadia['valor_pacote'] = (float) mt_rand(20, 80);
                if (mt_rand(0, 1)) {
                    $estadia['pago_entrada'] = $estadia['valor_pacote'];
                }
            }
            $conv = self::convenio();
            $casos[] = ['tabela' => $tabela, 'estadia' => $estadia, 'saida' => $saida->format('Y-m-d H:i'), 'convenio' => $conv,
                'esperado' => self::calcular($tabela, $estadia, $saida, $conv)];
        }

        return $casos;
    }

    /** mesmo cálculo de Estadias::calcular (tarifador + diária marcada + convênio), sem banco */
    private static function calcular(array $tabela, array $e, Carbon $saida, ?array $conv): array
    {
        $t = new Tarifador($tabela);
        $entrada = Carbon::parse($e['entrada'], 'America/Sao_Paulo');
        $r = $t->calcular($entrada, $saida);
        if ($e['pacote_id'] && (float) $e['valor_pacote'] > $r['valor']) {
            $r['valor'] = round((float) $e['valor_pacote'], 2);
            $r['detalhes'] = array_merge((array) $r['detalhes'], ['Diária marcada na entrada: '.Formato::moeda($r['valor'])]);
        }
        $c = null;
        if ($conv) {
            $c = new Convenio(['ativo' => true]);
            $c->setRelation('cliente', new Cliente(['nome' => $conv['nome']]));
            $c->setRelation('regras', collect([new ConvenioRegra(['servico_id' => null] + $conv['regra'])]));
        }
        $div = Convenios::dividir($c, 'estadia', $r['valor'], $r['minutos'], fn (int $min) => $t->calcular($entrada, $entrada->copy()->addMinutes($min))['valor']);

        return ['minutos' => $r['minutos'], 'valor' => (float) $r['valor'], 'detalhes' => $r['detalhes'],
            'convenio' => (float) $div['convenio'], 'cliente' => (float) $div['cliente'], 'regra' => $div['regra'],
            'a_pagar' => round(max(0, $div['cliente'] - (float) $e['pago_entrada']), 2)];
    }

    private static function tabela(): array
    {
        $faixas = [];
        for ($n = mt_rand(0, 3), $k = 0; $k < $n; $k++) {
            $faixas[] = ['duracao' => mt_rand(0, 3) === 0 ? null : [15, 30, 60, 120, 180][mt_rand(0, 4)], 'modo' => mt_rand(0, 2) === 0 ? 'fixo' : 'bloco',
                'bloco' => [1, 15, 30, 60, 120][mt_rand(0, 4)], 'valor' => mt_rand(100, 3000) / 100];
        }
        $pacotes = [];
        for ($n = mt_rand(0, 2), $k = 0; $k < $n; $k++) {
            $pacotes[] = ['nome' => ['Diária', 'Diária 12h', 'Semana'][mt_rand(0, 2)], 'duracao' => [360, 720, 1440, 10080][mt_rand(0, 3)], 'valor' => (float) mt_rand(20, 300),
                'hora_inicio' => null, 'hora_fim' => null];
        }
        if (mt_rand(0, 3) === 0) {
            $ini = sprintf('%02d:%02d', mt_rand(0, 23), [0, 30][mt_rand(0, 1)]);
            $fim = sprintf('%02d:%02d', mt_rand(0, 23), [0, 30][mt_rand(0, 1)]);
            $pacotes[] = ['nome' => 'Pernoite', 'duracao' => 0, 'valor' => (float) mt_rand(10, 60), 'hora_inicio' => $ini, 'hora_fim' => $fim];
        }

        return ['tolerancia' => [0, 5, 10, 15, 20][mt_rand(0, 4)], 'fracao' => [0, 5, 10, 15][mt_rand(0, 3)], 'restante' => mt_rand(0, 2) === 0 ? 'pacotes' : 'faixas',
            'faixas' => $faixas, 'pacotes' => $pacotes];
    }

    private static function convenio(): ?array
    {
        return match (mt_rand(0, 5)) {
            0 => ['nome' => 'Clínica', 'regra' => ['modo' => 'integral', 'limite_valor' => null, 'limite_min' => null]],
            1 => ['nome' => 'Loja', 'regra' => ['modo' => 'parcial', 'limite_valor' => (float) mt_rand(5, 40), 'limite_min' => null]],
            2 => ['nome' => 'Hotel', 'regra' => ['modo' => 'tempo', 'limite_valor' => null, 'limite_min' => [30, 60, 120, 240][mt_rand(0, 3)]]],
            default => null,
        };
    }
}
