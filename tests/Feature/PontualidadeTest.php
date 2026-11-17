<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Cobranca;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Pontualidade;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PontualidadeTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00');
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a, 'MARIA DA SILVA');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function config(bool $geral): void
    {
        $this->a->empresa->update(['configuracoes' => ['boleto_desconto_tipo' => 'valor', 'boleto_desconto_valor' => 20, 'desconto_pontualidade_geral' => $geral]]);
    }

    private function cobranca(): Cobranca
    {
        // mensalidade do mês (com desconto) + uma lavagem (sem desconto)
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 250, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        Ordens::lancar(['tipo' => 'avulso', 'cliente_id' => $this->x->cliente->id, 'valor' => 30, 'descricao' => 'Lavagem', 'data' => '2026-11-05', 'vencimento' => '2026-11-05']);

        return Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
    }

    public function test_desligada_nao_muda_nada(): void
    {
        $this->config(false);
        $c = $this->cobranca();
        $this->assertNull(Pontualidade::resumo($c));
        $this->actingAs($this->a->admin)->get('/cobrancas/'.$c->id)->assertInertia(fn ($p) => $p->where('pontualidade', null));
        // operador sem permissão de desconto continua barrado
        $this->actingAs($this->a->operador)->post('/clientes/'.$this->x->cliente->id.'/receber', ['ordens' => $c->itens->pluck('ordem_id')->all(), 'valor' => 260,
            'forma_pagamento_id' => FormasPagamento::naoInformada()->id, 'recebido_em' => '2026-11-17', 'falta' => 'desconto', 'motivo' => 'x'])->assertSessionHasErrors('falta');
    }

    public function test_ligada_aparece_e_entra_no_balcao_ate_o_vencimento(): void
    {
        $this->config(true);
        $c = $this->cobranca();
        $this->assertSame(['valor' => 20.0, 'ate' => '2026-11-20', 'a_pagar' => 260.0], Pontualidade::resumo($c));
        $this->actingAs($this->a->admin)->get('/cobrancas/'.$c->id)->assertInertia(fn ($p) => $p->where('pontualidade.a_pagar', 260));
        $this->actingAs($this->a->admin)->get('/clientes/'.$this->x->cliente->id.'/receber')->assertInertia(fn ($p) => $p->where('pontualidade.0.valor', 20)->where('pontualidade.0.ate', '2026-11-20'));

        // operador sem permissão de desconto: pontualidade é regra da empresa, entra
        $ordens = $c->itens->pluck('ordem_id')->all();
        $this->actingAs($this->a->operador)->post('/clientes/'.$this->x->cliente->id.'/receber', ['ordens' => $ordens, 'valor' => 260,
            'forma_pagamento_id' => FormasPagamento::naoInformada()->id, 'recebido_em' => '2026-11-17', 'falta' => 'desconto'])->assertSessionHasNoErrors();
        $aj = Ajuste::where('tipo', 'desconto')->first();
        $this->assertSame([20.0, 'Desconto de pontualidade'], [(float) $aj->valor, $aj->motivo]);
        $this->assertSame('paga', $c->refresh()->situacao);
    }

    public function test_depois_do_vencimento_ou_acima_do_desconto_nao_vale(): void
    {
        $this->config(true);
        $c = $this->cobranca();
        $ordens = $c->itens->pluck('ordem_id')->all();
        $forma = FormasPagamento::naoInformada()->id;
        // mais desconto do que o de pontualidade: precisa da permissão
        $this->actingAs($this->a->operador)->post('/clientes/'.$this->x->cliente->id.'/receber', ['ordens' => $ordens, 'valor' => 250,
            'forma_pagamento_id' => $forma, 'recebido_em' => '2026-11-17', 'falta' => 'desconto', 'motivo' => 'x'])->assertSessionHasErrors('falta');
        // pago depois do vencimento: sem desconto
        Carbon::setTestNow('2026-11-21 10:00');
        $this->assertNull(Pontualidade::resumo($c->refresh()));
        $this->actingAs($this->a->operador)->post('/clientes/'.$this->x->cliente->id.'/receber', ['ordens' => $ordens, 'valor' => 260,
            'forma_pagamento_id' => $forma, 'recebido_em' => '2026-11-21', 'falta' => 'desconto', 'motivo' => 'x'])->assertSessionHasErrors('falta');
        // só a lavagem marcada (sem a mensalidade): não tem desconto
        Carbon::setTestNow('2026-11-17 10:00');
        $lavagem = $c->itens->firstWhere('tipo', 'avulso')->ordem_id;
        $this->actingAs($this->a->operador)->post('/clientes/'.$this->x->cliente->id.'/receber', ['ordens' => [$lavagem], 'valor' => 10,
            'forma_pagamento_id' => $forma, 'recebido_em' => '2026-11-17', 'falta' => 'desconto', 'motivo' => 'x'])->assertSessionHasErrors('falta');
    }

    public function test_primeira_mensalidade_e_desconto_negociado_nao_tem(): void
    {
        $this->config(true);
        $c = $this->cobranca();
        $c->update(['desconto' => 15, 'desconto_motivo' => 'negociado']);
        $this->assertNull(Pontualidade::resumo($c->refresh()));
    }

    public function test_configuracao_salva_a_opcao(): void
    {
        $base = ['boleto_multa_tipo' => 'nenhuma', 'boleto_juros_tipo' => 'nenhum', 'boleto_desconto_tipo' => 'valor', 'boleto_desconto_valor' => 20, 'boleto_baixa_dias' => 30];
        $this->actingAs($this->a->admin)->put('/configuracoes/inter/regras', $base + ['desconto_pontualidade_geral' => true])->assertSessionHasNoErrors();
        $this->assertTrue(Pontualidade::ativa($this->a->empresa->refresh()));
        $this->actingAs($this->a->admin)->put('/configuracoes/inter/regras', $base)->assertSessionHasNoErrors();
        $this->assertFalse(Pontualidade::ativa($this->a->empresa->refresh()));
    }
}
