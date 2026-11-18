<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Caixa;
use App\Models\CaixaMovimento;
use App\Models\ContaFinanceira;
use App\Models\Despesa;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Servicos\Resultado;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Etapa 8.3: caixa do PDV (recebimentos no caixa, receber conta, retirada, suprimento, fechamento) e retirada do sócio. */
class PdvCaixaTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00:00');
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        $t->tipos()->attach($this->a->tipo->id);
        Pins::definir($this->a->operador, '4831');
        Pins::definir($this->a->gerente, '5072');
        $this->a->niveis->gerente->update(['permissoes' => ['configuracoes', 'desconto', 'pdv_gerente']]);
        $ap = Aparelhos::novoCodigo(Aparelho::create(['nome' => 'Balcão']));
        $this->token = $this->postJson('/api/pdv/parear', ['codigo' => $ap])->assertOk()->json('token');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pdv(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function forma(string $tipo): int
    {
        return FormaPagamento::where('tipo', $tipo)->value('id');
    }

    /** estadia de 2h (R$ 20) pela API do PDV */
    private function estadia(string $placa, string $forma, ?float $recebido = null): void
    {
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => $placa, 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $e = Estadia::where('placa', $placa)->first();
        Carbon::setTestNow(now()->addHours(2));
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma($forma), 'valor_recebido' => $recebido, 'pin' => '4831'])->assertOk();
    }

    public function test_recebimentos_do_pdv_caem_no_caixa_e_o_fechamento_confere(): void
    {
        $this->estadia('AAA1A11', 'dinheiro', 50);
        $this->estadia('BBB1B22', 'pix');
        // recebido pela gestão não entra no caixa
        $m = $this->criarCliente($this->a, 'JOAO', 'JOA1O11');
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $m->cliente->id, 'valor' => 180, 'descricao' => 'Mensalidade 11/2026', 'data' => '2026-11-01', 'vencimento' => '2026-11-05']);
        $o2 = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $m->cliente->id, 'valor' => 180, 'descricao' => 'Mensalidade 12/2026', 'data' => '2026-12-01', 'vencimento' => '2026-12-05']);
        $this->actingAs($this->a->admin)->post("/clientes/{$m->cliente->id}/receber", ['ordens' => [$o2->id], 'valor' => 180, 'forma_pagamento_id' => $this->forma('dinheiro'),
            'recebido_em' => now()->toDateString()])->assertSessionHasNoErrors();

        // receber conta no balcão: em dinheiro, com troco
        $this->pdv()->getJson("/api/pdv/clientes/{$m->cliente->id}/contas")->assertJsonCount(1, 'contas')->assertJsonPath('contas.0.vencida', true);
        $this->pdv()->postJson("/api/pdv/clientes/{$m->cliente->id}/receber", ['ordens' => [$o->id], 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 150, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('valor_recebido');
        $this->pdv()->postJson("/api/pdv/clientes/{$m->cliente->id}/receber", ['ordens' => [$o->id], 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 200, 'pin' => '4831'])
            ->assertOk()->assertJsonPath('troco', 20)->assertJsonPath('recibo.total', 180);
        $this->assertSame('paga', $o->fresh()->situacao);

        // só gerente vê o caixa
        $this->pdv()->postJson('/api/pdv/caixa/resumo', ['pin' => '4831'])->assertStatus(422)->assertJsonValidationErrors('pin');
        $r = $this->pdv()->postJson('/api/pdv/caixa/resumo', ['pin' => '5072'])->assertOk()->json();
        $this->assertSame([['Mensalidades', 1, 180], ['Estadias', 2, 40], ['Lavagens e serviços', 0, 0]], array_map(fn ($l) => [$l['servico'], $l['qtd'], $l['total']], $r['por_servico']));
        $this->assertSame(220, $r['total_recebido']);
        $this->assertEqualsCanonicalizing([['Dinheiro', 200], ['Pix', 20]], array_map(fn ($l) => [$l['forma'], $l['total']], $r['por_forma']));
        $this->assertSame(200, $r['dinheiro_esperado']); // troco inicial 0 + 200 em dinheiro (20 + 180)

        // retiradas e suprimento (gerente)
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'sangria', 'valor' => 50, 'destino' => 'cofre', 'motivo' => 'excesso', 'pin' => '4831'])->assertStatus(422);
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'sangria', 'valor' => 50, 'destino' => 'cofre', 'pin' => '5072'])->assertStatus(422)->assertJsonValidationErrors('motivo');
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'sangria', 'valor' => 50, 'destino' => 'cofre', 'motivo' => 'excesso', 'pin' => '5072'])->assertOk();
        $banco = ContaFinanceira::where('tipo', 'banco')->value('id');
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'sangria', 'valor' => 30, 'destino' => 'banco', 'conta_financeira_id' => $banco, 'motivo' => 'depósito', 'pin' => '5072'])->assertOk();
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'suprimento', 'valor' => 10, 'motivo' => 'moedas do cofre', 'pin' => '5072'])->assertOk();
        $cat = collect($this->pdv()->getJson('/api/pdv/caixa/opcoes')->json('categorias'))->firstWhere('nome', 'Benefícios (transporte, alimentação)');
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'pagamento', 'valor' => 45, 'plano_conta_id' => $cat['id'], 'descricao' => 'Almoço', 'pin' => '5072'])->assertOk();
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'retirada', 'valor' => 60, 'pin' => '5072'])->assertOk();
        // mais do que tem na gaveta (200 − 50 − 30 + 10 − 45 − 60 = 25)
        $this->pdv()->postJson('/api/pdv/caixa/movimentos', ['tipo' => 'retirada', 'valor' => 26, 'pin' => '5072'])->assertStatus(422)->assertJsonValidationErrors('valor');

        // o pagamento virou despesa paga em dinheiro
        $d = Despesa::first();
        $this->assertSame(['Almoço', 'paga', '45.00', $cat['id']], [$d->descricao, $d->situacao, $d->valor_pago, $d->conta_id]);
        $this->assertSame($this->forma('dinheiro'), $d->forma_pagamento_id);

        // fechamento
        $this->pdv()->postJson('/api/pdv/caixa/fechar', ['dinheiro_contado' => 20, 'troco_deixado' => 25, 'pin' => '5072'])->assertStatus(422)->assertJsonValidationErrors('troco_deixado');
        $f = $this->pdv()->postJson('/api/pdv/caixa/fechar', ['dinheiro_contado' => 20, 'troco_deixado' => 15, 'observacao' => 'faltou moeda', 'pin' => '5072'])->assertOk()->json('resumo');
        $this->assertSame([25, 20, -5, 15], [$f['dinheiro_esperado'], $f['dinheiro_contado'], $f['diferenca'], $f['troco_deixado']]);
        $this->assertSame(2, Caixa::count());
        $novo = Caixa::where('situacao', 'aberto')->first();
        $this->assertSame('15.00', $novo->troco_inicial);
        $this->assertSame($this->a->gerente->id, Caixa::where('situacao', 'fechado')->first()->fechado_por);

        // a próxima estadia cai no caixa novo
        $this->estadia('CCC1C33', 'debito');
        $this->assertSame(20, $this->pdv()->postJson('/api/pdv/caixa/resumo', ['pin' => '5072'])->json('total_recebido'));

        // gestão: caixas, retirada no Resultado (abaixo do lucro) e no fluxo
        $this->actingAs($this->a->admin)->get('/resultado/caixas')->assertInertia(fn ($p) => $p->has('caixas', 2)->has('retiradas', 1));
        $res = Resultado::mes('2026-11');
        $this->assertSame(60.0, $res['retiradas']);
        $this->assertSame(round($res['resultado'] - 60, 2), $res['resultado_final']);
        $this->assertContains('Retirada do sócio', collect(Resultado::fluxo('2026-11-01', '2026-11-30')['dias'])->flatMap(fn ($d) => array_column($d['itens'], 'rotulo'))->all());
    }

    public function test_retirada_do_socio_pela_gestao(): void
    {
        $banco = ContaFinanceira::where('tipo', 'banco')->value('id');
        $this->actingAs($this->a->operador)->post('/resultado/retiradas', ['valor' => 1000, 'data' => '2026-11-10', 'conta_financeira_id' => $banco])->assertForbidden();
        $this->actingAs($this->a->admin)->post('/resultado/retiradas', ['valor' => 1000, 'data' => '2026-11-10', 'conta_financeira_id' => $banco, 'motivo' => 'Pró-labore'])->assertSessionHas('sucesso');
        $m = CaixaMovimento::first();
        $this->assertNull($m->caixa_id);
        $this->assertSame(1000.0, Resultado::mes('2026-11')['retiradas']);
        $this->actingAs($this->a->admin)->get('/resultado')->assertInertia(fn ($p) => $p->where('resultado.retiradas', 1000));
        $this->actingAs($this->a->admin)->delete("/resultado/retiradas/{$m->id}")->assertSessionHas('sucesso');
        $this->assertSame(0.0, Resultado::mes('2026-11')['retiradas']);

        // a do PDV não se apaga pela gestão
        $c = Caixa::create(['aberto_em' => now(), 'troco_inicial' => 0]);
        $p = CaixaMovimento::create(['caixa_id' => $c->id, 'tipo' => 'retirada', 'data' => '2026-11-10', 'valor' => 10]);
        $this->actingAs($this->a->admin)->delete("/resultado/retiradas/{$p->id}")->assertSessionHas('erro');
        $this->assertNotNull($p->fresh());
    }
}
