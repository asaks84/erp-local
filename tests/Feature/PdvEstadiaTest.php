<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Models\TarifaPacote;
use App\Servicos\Convenios;
use App\Servicos\FormasPagamento;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Etapa 8.2: estadia pelo PDV (entrada, diária marcada, saída com convênio/troco/ticket perdido, cancelamento). */
class PdvEstadiaTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    private TarifaPacote $diaria;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        $this->diaria = TarifaPacote::create(['tarifa_id' => $t->id, 'nome' => 'Diária 12h', 'duracao_min' => 720, 'valor' => 40]);
        $t->tipos()->attach($this->a->tipo->id);
        Pins::definir($this->a->operador, '4831');
        Pins::definir($this->a->gerente, '5072');
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

    public function test_entrada_e_saida_paga_em_dinheiro_com_troco(): void
    {
        $this->pdv()->getJson('/api/pdv/estadias/opcoes')->assertOk()->assertJsonPath('tipos.0.diarias.0.nome', 'Diária 12h');
        // sem PIN não entra
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'avu1a23', 'tipo_veiculo_id' => $this->a->tipo->id])->assertStatus(422);
        $r = $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'avu1a23', 'modelo' => 'Gol branco', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])
            ->assertOk()->assertJsonPath('recibo.placa', 'AVU1A23')->assertJsonPath('recibo.modelo', 'Gol branco');
        $e = Estadia::first();
        $this->assertSame(16, strlen($e->codigo));
        $this->assertNotNull($e->aparelho_id);
        $this->assertSame($this->a->operador->id, $e->criado_por);
        // mesma placa de novo: já está no pátio
        $this->pdv()->getJson('/api/pdv/estadias/placa/AVU1A23')->assertJsonPath('no_patio.numero', $e->numero);
        $this->pdv()->getJson('/api/pdv/estadias/patio')->assertJsonCount(1);

        // acha pelo QR (URL), número e placa
        foreach (['https://erp.x/t/'.$e->codigo, (string) $e->numero, 'AVU-1A23'] as $q) {
            $this->pdv()->getJson('/api/pdv/estadias/buscar?q='.urlencode($q))->assertOk()->assertJsonPath('estadia.id', $e->id);
        }

        Carbon::setTestNow('2026-11-10 14:00:00'); // 2h: 15 + 5
        $this->pdv()->getJson("/api/pdv/estadias/{$e->id}/calculo")->assertJsonPath('calculo.a_pagar', 20);
        // dinheiro: recebido menor que o total não passa
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 10, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('valor_recebido');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 50, 'pin' => '4831'])
            ->assertOk()->assertJsonPath('recibo.pago.valor', 20)->assertJsonPath('recibo.pago.observacao', 'Recebido R$ 50,00 · troco R$ 30,00');
        $p = Pagamento::first();
        $this->assertSame(['pdv', $e->aparelho_id], [$p->origem, $p->aparelho_id]);
        $this->assertSame('pago', $e->fresh()->destino);
        // ticket já usado
        $this->pdv()->getJson('/api/pdv/estadias/buscar?q='.$e->numero)->assertStatus(404)->assertJsonFragment(['erro' => 'Este ticket já foi finalizado (10/11 14:00).']);
        // aparece na gestão igual
        $this->actingAs($this->a->admin)->get('/estadias')->assertInertia(fn ($pg) => $pg->where('hoje.0.modelo', 'Gol branco')->where('hoje.0.pdv', true));
    }

    public function test_diaria_paga_na_entrada_e_excedente_na_saida(): void
    {
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'DIA1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pacote_id' => $this->diaria->id,
            'pagar_agora' => true, 'forma_pagamento_id' => $this->forma('pix'), 'pin' => '4831'])->assertOk()->assertJsonPath('recibo.pago.valor', 40)->assertJsonPath('recibo.servico', 'Diária 12h');
        $e = Estadia::first();
        $this->assertSame('40.00', $e->pago_entrada);
        $this->assertSame('pdv', Pagamento::first()->origem);
        // não cancela com a diária paga
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/cancelar", ['motivo' => 'teste', 'pin' => '5072'])->assertStatus(422);

        // saiu antes: nada a pagar
        Carbon::setTestNow('2026-11-10 15:00:00');
        $this->pdv()->getJson("/api/pdv/estadias/{$e->id}/calculo")->assertJsonPath('calculo.valor', 40)->assertJsonPath('calculo.a_pagar', 0);
        // passou: 14h → a tabela dá diária (40) + 2h (20) = 60; paga a diferença (20)
        Carbon::setTestNow('2026-11-11 02:00:00');
        $this->pdv()->getJson("/api/pdv/estadias/{$e->id}/calculo")->assertJsonPath('calculo.valor', 60)->assertJsonPath('calculo.a_pagar', 20);
        // convênio não entra em diária paga
        $c = Convenio::create(['cliente_id' => $this->criarCliente($this->a, 'OFICINA', 'OFC1N11')->cliente->id]);
        Convenios::salvarRegras($c, [['servico_id' => null, 'modo' => 'integral']]);
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'convenio_id' => $c->id, 'forma_pagamento_id' => $this->forma('pix'), 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('convenio_id');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('debito'), 'pin' => '4831'])->assertOk();
        $this->assertSame(60.0, (float) OrdemServico::where('estadia_id', $e->id)->sum('valor'));

        // diária marcada, paga na saída, saindo antes: paga a diária
        Carbon::setTestNow('2026-11-11 08:00:00');
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'DIA1A12', 'tipo_veiculo_id' => $this->a->tipo->id, 'pacote_id' => $this->diaria->id, 'pin' => '4831'])->assertOk();
        $e2 = Estadia::where('placa', 'DIA1A12')->first();
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->pdv()->getJson("/api/pdv/estadias/{$e2->id}/calculo")->assertJsonPath('calculo.a_pagar', 40);
    }

    public function test_convenio_na_saida_ticket_perdido_e_na_conta_de(): void
    {
        $conv = $this->criarCliente($this->a, 'OFICINA', 'OFC1N11');
        $c = Convenio::create(['cliente_id' => $conv->cliente->id]);
        Convenios::salvarRegras($c, [['servico_id' => null, 'modo' => 'tempo', 'limite_min' => 60]]);
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'CON1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $e = Estadia::first();
        Carbon::setTestNow('2026-11-10 15:00:00'); // 3h = 25; convênio paga 1h = 15
        $this->pdv()->getJson("/api/pdv/estadias/{$e->id}/calculo?convenio_id={$c->id}")->assertJsonPath('calculo.convenio', 15)->assertJsonPath('calculo.a_pagar', 10);
        $this->assertNull($e->fresh()->convenio_id); // o cálculo não grava
        // ticket perdido: pede nome e documento
        $saida = ['destino' => 'pago', 'convenio_id' => $c->id, 'forma_pagamento_id' => $this->forma('credito'), 'perdido' => true, 'pin' => '4831'];
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", $saida)->assertStatus(422)->assertJsonValidationErrors('retirada_nome');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", $saida + ['retirada_nome' => 'Maria Souza', 'retirada_documento' => '12.345.678-9'])
            ->assertOk()->assertJsonPath('recibo.convenio.valor', 15)->assertJsonPath('recibo.pago.valor', 10);
        $e->refresh();
        $this->assertSame([$c->id, 'Maria Souza'], [$e->convenio_id, $e->retirada_nome]);

        // na conta de outro cliente
        $joao = $this->criarCliente($this->a, 'JOAO', 'JOA1O11');
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'PAI1A23', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $e2 = Estadia::where('placa', 'PAI1A23')->first();
        $this->pdv()->getJson('/api/pdv/clientes?q=joa')->assertJsonPath('0.nome', 'JOAO');
        Carbon::setTestNow('2026-11-10 16:00:00');
        $this->pdv()->postJson("/api/pdv/estadias/{$e2->id}/saida", ['destino' => 'conta', 'conta_cliente_id' => $joao->cliente->id, 'pin' => '4831'])->assertOk();
        $this->assertSame($joao->cliente->id, OrdemServico::find($e2->fresh()->ordem_id)->cliente_id);
    }

    public function test_mensalista_pede_confirmacao_e_cancelar_pede_gerente(): void
    {
        $m = $this->criarCliente($this->a, 'MENSAL', 'MEN1S11');
        MensalidadeContrato::create(['unidade_id' => $this->a->unidade->id, 'cliente_id' => $m->cliente->id, 'veiculo_id' => $m->veiculo->id, 'periodo' => 'Diurno', 'valor' => 180, 'inicio' => '2026-01-01']);
        $this->pdv()->getJson('/api/pdv/estadias/placa/MEN1S11')->assertJsonPath('veiculo.mensalista', true)->assertJsonPath('veiculo.cliente', 'MENSAL');
        $dados = ['placa' => 'MEN1S11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'];
        $this->pdv()->postJson('/api/pdv/estadias', $dados)->assertStatus(422)->assertJsonValidationErrors('mensalista');
        $this->pdv()->postJson('/api/pdv/estadias', $dados + ['confirmar_mensalista' => true])->assertOk();
        $e = Estadia::first();
        $this->assertTrue($e->mensalista);

        // operador não cancela; gerente sem a permissão também não; com a permissão, sim
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/cancelar", ['motivo' => 'engano', 'pin' => '4831'])->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/cancelar", ['motivo' => 'engano', 'pin' => '5072'])->assertStatus(422);
        $this->a->niveis->gerente->update(['permissoes' => ['configuracoes', 'desconto', 'pdv_gerente']]);
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/cancelar", ['motivo' => 'engano', 'pin' => '5072'])->assertOk();
        $this->assertSame('cancelada', $e->fresh()->situacao);
        $this->assertSame($this->a->gerente->id, $e->fresh()->cancelada_por);

        // a placa (sugestão) vem da última visita avulsa
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'VIS1T11', 'modelo' => 'Onix prata', 'tipo_veiculo_id' => $this->a->moto->id, 'pin' => '4831'])->assertStatus(422); // moto sem tabela
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'VIS1T11', 'modelo' => 'Onix prata', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $v = Estadia::where('placa', 'VIS1T11')->first();
        Carbon::setTestNow('2026-11-10 12:05:00');
        $this->pdv()->postJson("/api/pdv/estadias/{$v->id}/saida", ['destino' => 'pago', 'pin' => '4831'])->assertOk(); // tolerância: sem forma
        $this->pdv()->getJson('/api/pdv/estadias/placa/vis1t11')->assertJsonPath('ultima.modelo', 'Onix prata')->assertJsonPath('ultima.tipo_id', $this->a->tipo->id);
    }

    public function test_gestao_entrada_com_diaria_e_convenio_na_saida(): void
    {
        $this->actingAs($this->a->operador)->get('/estadias')->assertInertia(fn ($p) => $p->where('tipos.0.diarias.0.valor', 40));
        $this->actingAs($this->a->operador)->post('/estadias', ['placa' => 'GES1T11', 'modelo' => 'HB20', 'tipo_veiculo_id' => $this->a->tipo->id,
            'pacote_id' => $this->diaria->id, 'pagar_agora' => true, 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 50])->assertSessionHasNoErrors();
        $e = Estadia::first();
        $this->assertSame(['HB20', '40.00', 'gestao'], [$e->modelo, $e->pago_entrada, Pagamento::first()->origem]);
        $this->assertStringContainsString('troco R$ 10,00', Pagamento::first()->observacao);
        Carbon::setTestNow('2026-11-10 13:00:00');
        $this->actingAs($this->a->operador)->post("/estadias/{$e->id}/saida", ['destino' => 'pago'])->assertSessionHasNoErrors();
        $this->assertSame('pago_entrada', $e->fresh()->destino);
    }
}
