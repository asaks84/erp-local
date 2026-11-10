<?php

namespace Tests\Unit;

use App\Servicos\Tarifador;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Casos reais das tabelas do Local (sistema atual) e das regras especiais. */
class TarifadorTest extends TestCase
{
    private static function carro(): array
    {
        // "Estadia de carro": 1ª hora R$ 10, depois R$ 5 a cada 30 min, diária 12h R$ 25
        return ['tolerancia' => 0, 'fracao' => 0, 'restante' => 'faixas',
            'faixas' => [['duracao' => 60, 'modo' => 'fixo', 'valor' => 10], ['duracao' => null, 'modo' => 'bloco', 'bloco' => 30, 'valor' => 5]],
            'pacotes' => [['nome' => 'Diária 12h', 'duracao' => 720, 'valor' => 25]]];
    }

    private static function moto(): array
    {
        // "Motos": só diária de 12h R$ 10, período começado = diária cheia
        return ['tolerancia' => 0, 'fracao' => 0, 'restante' => 'pacotes', 'faixas' => [], 'pacotes' => [['nome' => 'Diária', 'duracao' => 720, 'valor' => 10]]];
    }

    public static function casos(): array
    {
        return [
            'carro 40 min' => ['carro', '2026-11-05 08:10', '2026-11-05 08:50', 10.0],
            'carro 1h' => ['carro', '2026-11-05 08:10', '2026-11-05 09:10', 10.0],
            'carro 1h10' => ['carro', '2026-11-05 08:10', '2026-11-05 09:20', 15.0],
            'carro 2h35 (SUV 8h10→10h45)' => ['carro', '2026-11-05 08:10', '2026-11-05 10:45', 25.0],
            'carro 5h: teto na diária' => ['carro', '2026-11-05 08:00', '2026-11-05 13:00', 25.0],
            'carro 13h: diária + 1h' => ['carro', '2026-11-05 07:00', '2026-11-05 20:00', 35.0],
            'carro 30h' => ['carro', '2026-11-05 07:00', '2026-11-06 13:00', 50.0 + 25.0],
            'moto 10 min' => ['moto', '2026-11-05 08:00', '2026-11-05 08:10', 10.0],
            'moto 13h' => ['moto', '2026-11-05 07:00', '2026-11-05 20:00', 20.0],
        ];
    }

    #[DataProvider('casos')]
    public function test_valores_das_tabelas_do_local(string $tabela, string $entrada, string $saida, float $esperado): void
    {
        $t = new Tarifador(self::$tabela());
        $this->assertEqualsWithDelta($esperado, $t->calcular($entrada, $saida)['valor'], 0.001);
    }

    public function test_tolerancia_e_fracao(): void
    {
        $t = new Tarifador(['tolerancia' => 10, 'fracao' => 5, 'faixas' => [['modo' => 'bloco', 'bloco' => 60, 'valor' => 8]]]);
        $this->assertSame(0.0, $t->porMinutos(10)['valor']);       // dentro da tolerância
        $this->assertSame(8.0, $t->porMinutos(11)['valor']);
        $this->assertSame(8.0, $t->porMinutos(65)['valor']);       // 5 min de cortesia
        $this->assertSame(16.0, $t->porMinutos(66)['valor']);
    }

    public function test_pernoite_por_horario(): void
    {
        $t = new Tarifador(['faixas' => [['modo' => 'bloco', 'bloco' => 60, 'valor' => 10]],
            'pacotes' => [['nome' => 'Pernoite', 'hora_inicio' => '19:00', 'hora_fim' => '08:00', 'valor' => 20]]]);
        $r = $t->calcular('2026-11-05 19:30', '2026-11-06 07:45');
        $this->assertSame(20.0, $r['valor']);
        $this->assertStringContainsString('Pernoite', $r['detalhes'][0]);
        // saiu depois da janela: volta às faixas
        $this->assertSame(130.0, $t->calcular('2026-11-05 19:30', '2026-11-06 08:15')['valor']);
    }

    public function test_saida_antes_da_entrada_e_erro(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Tarifador(self::carro()))->calcular('2026-11-05 10:00', '2026-11-05 09:00');
    }
}
