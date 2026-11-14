<?php

namespace Tests\Feature;

use App\Models\Estorno;
use App\Models\OrdemServico;
use App\Servicos\Estornos;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Resultado;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class EstornoClienteTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:00');
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function saldoExtrato(): float
    {
        $r = $this->actingAs($this->a->admin)->get('/clientes/'.$this->x->cliente->id.'/extrato?de=2026-11-01&ate=2026-11-30')->assertOk();

        return (float) $r->viewData('page')['props']['saldo_final'];
    }

    public function test_estorno_parcial_baixa_o_credito_e_sai_no_caixa(): void
    {
        $cli = $this->x->cliente;
        Ordens::lancarNaConta($cli, 'credito', 100, 'Crédito (pagou a mais)', '2026-11-10', 'Pagou o boleto depois do cancelamento');
        $this->assertSame(100.0, Estornos::disponivel($cli));
        $pix = \App\Models\FormaPagamento::where('tipo', 'pix')->first();

        // acima do crédito, sem motivo, sem permissão: recusa
        $this->actingAs($this->a->admin)->post('/clientes/'.$cli->id.'/estornos', ['valor' => 150, 'estornado_em' => '2026-11-14', 'motivo' => 'x'])->assertSessionHasErrors('valor');
        $this->actingAs($this->a->admin)->post('/clientes/'.$cli->id.'/estornos', ['valor' => 60, 'estornado_em' => '2026-11-14'])->assertSessionHasErrors('motivo');
        $this->actingAs($this->a->operador)->post('/clientes/'.$cli->id.'/estornos', ['valor' => 60, 'estornado_em' => '2026-11-14', 'motivo' => 'x'])->assertForbidden();
        $this->assertSame(0, Estorno::count());

        $this->actingAs($this->a->admin)->post('/clientes/'.$cli->id.'/estornos', ['valor' => 60, 'estornado_em' => '2026-11-14', 'forma_pagamento_id' => $pix->id, 'motivo' => 'Devolvido por Pix'])
            ->assertSessionHasNoErrors();
        $e = Estorno::first();
        $this->assertSame(60.0, (float) $e->valor);
        $this->assertSame(40.0, Estornos::disponivel($cli)); // sobrou um crédito de 40
        $this->assertSame(1, OrdemServico::abertas()->where('cliente_id', $cli->id)->where('tipo', 'credito')->count());
        $this->assertSame(-40.0, $this->saldoExtrato());

        // resultado (caixa) e fluxo
        $r = Resultado::mes('2026-11', 'caixa');
        $this->assertSame(-60.0, collect($r['receitas'])->firstWhere('id', 'estornos')['valor']);
        $this->assertNull(collect(Resultado::mes('2026-11', 'referencia')['receitas'])->firstWhere('id', 'estornos'));
        $f = Resultado::fluxo('2026-11-01', '2026-11-30');
        $this->assertSame(60.0, $f['realizado']['saidas']);

        // desfazer: o crédito inteiro volta, a sobra é cancelada
        $this->actingAs($this->a->admin)->post('/estornos/'.$e->id.'/desfazer', ['motivo' => 'Lançado errado'])->assertSessionHasNoErrors();
        $this->assertSame('desfeito', $e->refresh()->situacao);
        $this->assertSame(100.0, Estornos::disponivel($cli));
        $this->assertSame(-100.0, $this->saldoExtrato());
        $this->assertNull(collect(Resultado::mes('2026-11', 'caixa')['receitas'])->firstWhere('id', 'estornos'));
    }

    public function test_cliente_devendo_nao_tem_o_que_estornar(): void
    {
        $cli = $this->x->cliente;
        Ordens::lancarNaConta($cli, 'credito', 30, 'Crédito', '2026-11-10', 'x');
        Ordens::lancar(['tipo' => 'debito', 'cliente_id' => $cli->id, 'valor' => 50, 'descricao' => 'Débito', 'data' => '2026-11-10', 'vencimento' => '2026-11-10']);
        $this->assertSame(0.0, Estornos::disponivel($cli));
        $this->actingAs($this->a->admin)->post('/clientes/'.$cli->id.'/estornos', ['valor' => 10, 'estornado_em' => '2026-11-14', 'motivo' => 'x'])->assertSessionHasErrors('valor');
    }
}
