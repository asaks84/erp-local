<?php

namespace Tests\Feature;

use App\Models\FormaPagamento;
use App\Servicos\Baixa;
use App\Servicos\Despesas;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\PlanoContas;
use App\Servicos\Resultado;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Etapa 6c: resultado do mês (por referência e pelo dinheiro) e fluxo de caixa. */
class ResultadoTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        PlanoContas::garantirPadrao();
        FormasPagamento::garantirPadrao();
        $this->travarData('2026-11-12 10:00:00');
    }

    private function travarData(string $quando): void
    {
        \Carbon\Carbon::setTestNow($quando);
    }

    private function ordem(string $tipo, float $valor, string $data, ?string $comp = null)
    {
        return Ordens::lancar(['tipo' => $tipo, 'cliente_id' => $this->x->cliente->id, 'valor' => $valor, 'descricao' => ucfirst($tipo),
            'data' => $data, 'vencimento' => $data, 'competencia' => $comp]);
    }

    private function conta(string $nome): int
    {
        return PlanoContas::lancaveis()->firstWhere('nome', $nome)['id'];
    }

    private function cenario(): void
    {
        $m = $this->ordem('mensalidade', 300, '2026-10-10', '2026-10');
        $e = $this->ordem('estadia', 50, '2026-10-05');
        $this->ordem('servico', 40, '2026-10-20');
        $this->ordem('debito', 20, '2026-10-21');                          // resto de conta: não é receita nova
        Ordens::cancelar($this->ordem('mensalidade', 999, '2026-10-10', '2026-10'), 'lançada errada');
        $this->ordem('mensalidade', 300, '2026-11-25', '2026-11');          // a receber em novembro

        $cartao = FormaPagamento::where('tipo', 'debito')->first() ?? FormaPagamento::where('tipo', 'pix')->first();
        $cartao->update(['taxa_pct' => 2, 'prazo_dias' => 1]);
        Baixa::receber($this->x->cliente, ['ordens' => [$m->id, $e->id], 'valor' => 350, 'forma_pagamento_id' => $cartao->id, 'recebido_em' => '2026-10-31']);

        $pix = FormaPagamento::where('tipo', 'pix')->value('id');
        [$energia] = Despesas::lancar(['descricao' => 'Energia de outubro', 'conta_id' => $this->conta('Energia'), 'competencia' => '2026-10', 'vencimento' => '2026-11-05', 'valor' => 100]);
        Despesas::pagar($energia, ['pago_em' => '2026-11-04', 'valor_pago' => 105, 'forma_pagamento_id' => $pix]);
        Despesas::lancar(['descricao' => 'Aluguel de outubro', 'conta_id' => $this->conta('Aluguel'), 'competencia' => '2026-10', 'vencimento' => '2026-11-20', 'valor' => 1000]);
        [$c] = Despesas::lancar(['descricao' => 'Errada', 'conta_id' => $this->conta('Aluguel'), 'competencia' => '2026-10', 'vencimento' => '2026-11-20', 'valor' => 500]);
        Despesas::cancelar($c, 'duplicada');
        Despesas::lancar(['descricao' => 'Contador atrasado', 'conta_id' => $this->conta('Contador'), 'competencia' => '2026-11', 'vencimento' => '2026-11-01', 'valor' => 200]);
    }

    public function test_resultado_por_referencia(): void
    {
        $this->cenario();
        $r = Resultado::mes('2026-10');
        $this->assertSame(390.0, $r['total_receitas']);                    // 300 + 50 + 40 (sem débito nem cancelada)
        $taxa = round(350 * 0.02, 2);
        $this->assertSame(round(105 + 1000 + $taxa, 2), $r['total_despesas']); // energia pelo valor pago, aluguel a pagar, taxa do cartão
        $this->assertSame(round(390 - 1105 - $taxa, 2), $r['resultado']);
        $this->assertSame(1000.0, $r['a_pagar']);
        $nomes = array_column($r['despesas'], 'nome');
        $this->assertContains('Ocupação', $nomes);                          // grupo somado para cima
        $this->assertContains('Taxas de cartão e maquininha', $nomes);
        $ocupacao = collect($r['despesas'])->firstWhere('nome', 'Ocupação');
        $this->assertSame(1000.0, $ocupacao['valor']);
    }

    public function test_resultado_pelo_dinheiro(): void
    {
        $this->cenario();
        $out = Resultado::mes('2026-10', 'caixa');
        $this->assertSame(350.0, $out['total_receitas']);                   // o que entrou em outubro
        $this->assertSame(round(350 * 0.02, 2), $out['total_despesas']);     // nada pago em outubro, só a taxa
        $nov = Resultado::mes('2026-11', 'caixa');
        $this->assertSame(0.0, $nov['total_receitas']);
        $this->assertSame(105.0, $nov['total_despesas']);                    // energia paga em 04/11
        $this->assertCount(6, Resultado::historico('2026-11'));
    }

    public function test_fluxo_de_caixa(): void
    {
        $this->cenario();
        $f = Resultado::fluxo('2026-10-25', '2026-11-30');
        $dias = collect($f['dias'])->keyBy('data');
        // cartão cai no dia seguinte, sem a taxa
        $this->assertSame(round(350 - 7, 2), $dias['2026-11-01']['entradas']);
        $this->assertSame(105.0, $dias['2026-11-04']['saidas']);
        $this->assertTrue($dias['2026-11-20']['previsto']);
        $this->assertSame(1000.0, $dias['2026-11-20']['saidas']);
        $this->assertSame(300.0, $dias['2026-11-25']['entradas']);
        $this->assertSame(round(343 - 105, 2), $f['realizado']['entradas'] - $f['realizado']['saidas']);
        $this->assertSame(200.0, $f['atrasado']['pagar']);                    // contador vencido em 01/11
        $this->assertSame(60.0, $f['atrasado']['receber']);                   // serviço e débito de outubro em aberto
        $this->assertSame(round(343 - 105 - 1000 + 300, 2), end($f['dias'])['acumulado']);
    }

    public function test_telas_e_permissao(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->get('/resultado?mes=2026-10&regime=caixa')->assertOk()->assertInertia(fn ($p) => $p->component('Resultado/Mes')->where('resultado.regime', 'caixa')->has('historico', 6));
        $adm->get('/resultado/fluxo?de=2026-11-01&ate=2026-11-30')->assertOk()->assertInertia(fn ($p) => $p->component('Resultado/Fluxo'));
        $this->actingAs($this->a->operador)->get('/resultado')->assertForbidden();
    }
}
