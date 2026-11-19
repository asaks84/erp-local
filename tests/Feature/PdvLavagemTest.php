<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Convenio;
use App\Models\Empresa;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Servicos\Convenios;
use App\Servicos\FormasPagamento;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Etapa 8.4: lavagem pelo PDV (venda, etapas, entrega com convênio, conta, estadia depois de pronta, carro em estadia). */
class PdvLavagemTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    private Servico $lav;

    private Servico $cera;

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
        $this->lav = Servico::create(['codigo' => 'LS', 'nome' => 'Lavagem simples']);
        $this->cera = Servico::create(['codigo' => 'CE', 'nome' => 'Cera', 'papel' => 'adicional']);
        $this->lav->adicionais()->attach($this->cera->id);
        ServicoPreco::create(['servico_id' => $this->lav->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 40, 'vigencia_inicio' => '2000-01-01']);
        ServicoPreco::create(['servico_id' => $this->cera->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 15, 'vigencia_inicio' => '2000-01-01']);
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

    private function vender(string $placa, array $extra = []): array
    {
        return $this->pdv()->postJson('/api/pdv/lavagens', $extra + ['placa' => $placa, 'modelo' => 'Gol', 'tipo_veiculo_id' => $this->a->tipo->id,
            'servico_id' => $this->lav->id, 'adicionais' => [$this->cera->id], 'pin' => '4831'])->assertOk()->json();
    }

    public function test_venda_etapas_e_entrega_com_convenio_e_troco(): void
    {
        $this->pdv()->getJson('/api/pdv/lavagens/opcoes')->assertJsonPath('servicos.0.precos.'.$this->a->tipo->id, 40);
        $v = $this->vender('LAV1A11', ['celular' => '(13) 99999-0000']);
        $this->assertSame('Lavagem simples + Cera', $v['recibo']['servico']);
        $p = OrdemServico::find($v['lavagem']['id']);
        $this->assertSame(['pdv', 'Gol', 'aguardando', '13999990000'], [$p->origem, $p->veiculo_descricao, $p->etapa, $p->celular]);
        $this->pdv()->getJson('/api/pdv/lavagens/andamento')->assertJsonCount(1)->assertJsonPath('0.proxima.chave', 'pronta'); // "lavando" vem desativada

        // um toque: próxima etapa; na pronta não avança (entregar é pela entrega)
        $this->pdv()->postJson("/api/pdv/lavagens/{$p->id}/etapa", ['pin' => '4831'])->assertOk()->assertJsonPath('lavagem.etapa', 'pronta')->assertJsonPath('lavagem.pronta', true);
        $this->pdv()->postJson("/api/pdv/lavagens/{$p->id}/etapa", ['pin' => '4831'])->assertStatus(422);

        // busca pelo QR (uuid), número e placa
        foreach (['https://x/t/'.$p->uuid, (string) $p->numero, 'LAV-1A11'] as $q) {
            $this->pdv()->getJson('/api/pdv/lavagens/buscar?q='.urlencode($q))->assertOk()->assertJsonPath('lavagem.id', $p->id);
        }

        // convênio escolhido na entrega: paga até 30 da lavagem; cera não coberta
        $conv = $this->criarCliente($this->a, 'OFICINA', 'OFC1N11');
        $c = Convenio::create(['cliente_id' => $conv->cliente->id]);
        Convenios::salvarRegras($c, [['servico_id' => $this->lav->id, 'modo' => 'parcial', 'limite_valor' => 30]]);
        $this->pdv()->getJson("/api/pdv/lavagens/{$p->id}/calculo?convenio_id={$c->id}")->assertJsonPath('calculo.convenio', 30)->assertJsonPath('calculo.a_pagar', 25);
        $this->pdv()->getJson("/api/pdv/lavagens/{$p->id}/calculo")->assertJsonPath('calculo.a_pagar', 55); // o cálculo não grava
        $this->pdv()->postJson("/api/pdv/lavagens/{$p->id}/entregar", ['destino' => 'pago', 'convenio_id' => $c->id, 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 20, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('valor_recebido');
        $this->pdv()->postJson("/api/pdv/lavagens/{$p->id}/entregar", ['destino' => 'pago', 'convenio_id' => $c->id, 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 50, 'pin' => '4831'])
            ->assertOk()->assertJsonPath('troco', 25)->assertJsonPath('recibo.pago.valor', 25);
        $this->assertSame('entregue', $p->fresh()->etapa);
        $this->assertSame(30.0, (float) OrdemServico::where('cliente_id', $conv->cliente->id)->where('situacao', 'aberta')->sum('valor'));
        $pag = Pagamento::latest('id')->first();
        $this->assertSame(['pdv', 25.0], [$pag->origem, (float) $pag->valor]);
        $this->assertNotNull($pag->caixa_id);
        // no caixa, na linha das lavagens
        $r = $this->pdv()->postJson('/api/pdv/caixa/resumo', ['pin' => '5072'])->json('por_servico');
        $this->assertSame(25, collect($r)->firstWhere('servico', 'Lavagens e serviços')['total']);
        // aparece no quadro da gestão
        $this->actingAs($this->a->admin)->get('/lavagens')->assertInertia(fn ($pg) => $pg->where('cartoes.0.etapa', 'entregue'));
    }

    public function test_pagar_agora_conta_de_cliente_e_estadia_depois_de_pronta(): void
    {
        // pagar agora, em dinheiro com troco
        $v = $this->vender('PAG1A11', ['pagar_agora' => true, 'forma_pagamento_id' => $this->forma('dinheiro'), 'valor_recebido' => 60]);
        $this->assertSame(5, $v['troco']);
        $this->pdv()->getJson("/api/pdv/lavagens/{$v['lavagem']['id']}/calculo")->assertJsonPath('calculo.a_pagar', 0)->assertJsonPath('calculo.pago_antes', 55);
        $this->pdv()->postJson("/api/pdv/lavagens/{$v['lavagem']['id']}/entregar", ['destino' => 'pago', 'pin' => '4831'])->assertOk();

        // estadia depois de pronta: cobra após 30 min, todo o tempo desde pronta
        $e = Empresa::find($this->a->empresa->id);
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['lavagem_estadia_cobrar' => true, 'lavagem_estadia_tolerancia_min' => 30]);
        $e->save();
        $v2 = $this->vender('EST1A11', ['adicionais' => []]);
        $id = $v2['lavagem']['id'];
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->pdv()->postJson("/api/pdv/lavagens/{$id}/etapa", ['pin' => '4831'])->assertOk();
        Carbon::setTestNow('2026-11-10 10:25:00');
        $this->pdv()->getJson("/api/pdv/lavagens/{$id}/calculo")->assertJsonPath('calculo.estadia', null);
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->pdv()->getJson("/api/pdv/lavagens/{$id}/calculo")->assertJsonPath('calculo.estadia.valor', 20)->assertJsonPath('calculo.a_pagar', 60);

        // na conta de um cliente (mensalista que paga depois)
        $m = $this->criarCliente($this->a, 'JOAO', 'JOA1O11');
        $this->pdv()->postJson("/api/pdv/lavagens/{$id}/entregar", ['destino' => 'conta', 'conta_cliente_id' => $m->cliente->id, 'pin' => '4831'])->assertOk();
        $this->assertSame(60.0, (float) OrdemServico::where('cliente_id', $m->cliente->id)->where('situacao', 'aberta')->sum('valor'));
        $this->assertTrue(OrdemServico::where('cliente_id', $m->cliente->id)->where('tipo', 'estadia')->exists());
        $this->assertSame('entregue', OrdemServico::find($id)->etapa);

        // gestão: configuração da regra
        $this->actingAs($this->a->admin)->post('/configuracoes/etapas/estadia', ['cobrar' => false, 'tolerancia_min' => 15])->assertSessionHas('sucesso');
        $this->actingAs($this->a->admin)->get('/configuracoes/etapas')->assertInertia(fn ($pg) => $pg->where('estadia.cobrar', false)->where('estadia.tolerancia_min', 15));
    }

    public function test_carro_em_estadia_pede_o_que_fazer_com_ela(): void
    {
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'PAT1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $e = Estadia::first();
        $info = $this->pdv()->getJson('/api/pdv/lavagens/placa/PAT1A11')->assertJsonPath('estadia.numero', $e->numero)->json();
        $base = ['estadia_id' => $info['estadia']['id']];
        $this->pdv()->postJson('/api/pdv/lavagens', ['placa' => 'PAT1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'pin' => '4831'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('estadia_acao');
        // cancelar pede gerente
        $this->pdv()->postJson('/api/pdv/lavagens', ['placa' => 'PAT1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'estadia_acao' => 'cancelar', 'pin' => '4831'] + $base)
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertSame(0, OrdemServico::where('tipo', 'servico')->count());
        $v = $this->pdv()->postJson('/api/pdv/lavagens', ['placa' => 'PAT1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'estadia_acao' => 'cancelar', 'pin' => '5072'] + $base)
            ->assertOk()->json();
        $this->assertSame('cancelada', $e->fresh()->situacao);
        $this->assertStringContainsString('Virou lavagem Nº '.$v['lavagem']['numero'], $e->fresh()->motivo_cancelamento);

        // manter: a estadia segue e a estadia depois de pronta não é cobrada em dobro
        $e2 = Empresa::find($this->a->empresa->id);
        $e2->configuracoes = array_merge($e2->configuracoes ?? [], ['lavagem_estadia_cobrar' => true, 'lavagem_estadia_tolerancia_min' => 0]);
        $e2->save();
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'PAT2B22', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $s = Estadia::where('placa', 'PAT2B22')->first();
        $v2 = $this->pdv()->postJson('/api/pdv/lavagens', ['placa' => 'PAT2B22', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'estadia_id' => $s->id, 'estadia_acao' => 'manter', 'pin' => '4831'])->assertOk()->json();
        $this->assertSame('patio', $s->fresh()->situacao);
        $this->pdv()->postJson("/api/pdv/lavagens/{$v2['lavagem']['id']}/etapa", ['pin' => '4831']);
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->pdv()->getJson("/api/pdv/lavagens/{$v2['lavagem']['id']}/calculo")->assertJsonPath('calculo.estadia', null)->assertJsonPath('calculo.a_pagar', 40);
    }

    public function test_dados_do_carro_passam_da_lavagem_para_a_estadia_e_vice_versa(): void
    {
        $this->vender('VIS1T22', ['modelo' => 'Onix prata', 'celular' => '13988887777']);
        $this->pdv()->getJson('/api/pdv/estadias/placa/VIS1T22')->assertJsonPath('ultima.modelo', 'Onix prata')
            ->assertJsonPath('ultima.tipo_id', $this->a->tipo->id)->assertJsonPath('ultima.celular', '13988887777');
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'VIS2T33', 'modelo' => 'HB20 azul', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $this->pdv()->getJson('/api/pdv/lavagens/placa/VIS2T33')->assertJsonPath('ultima.modelo', 'HB20 azul')->assertJsonPath('ultima.tipo_id', $this->a->tipo->id);
    }

    /** 8.8: números do Início e a saída que aceita qualquer ticket */
    public function test_inicio_e_saida_por_qualquer_ticket(): void
    {
        $this->pdv()->getJson('/api/pdv/inicio')->assertOk()->assertJson(['patio' => 0, 'lavagens' => 0, 'caixa' => null]);
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'EST1A11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $l = $this->vender('LAV2B22');
        $this->pdv()->getJson('/api/pdv/inicio')->assertJson(['patio' => 1, 'lavagens' => 1]);

        // pelo número da lavagem e pela placa da estadia: abre direto
        $this->pdv()->getJson('/api/pdv/ticket?q=LAV2B22')->assertOk()->assertJsonCount(1, 'achados')->assertJsonPath('achados.0.tipo', 'lavagem');
        $this->pdv()->getJson('/api/pdv/ticket?q=EST1A11')->assertOk()->assertJsonCount(1, 'achados')->assertJsonPath('achados.0.tipo', 'estadia');
        // carro com estadia e lavagem abertas: pergunta qual
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'LAV2B22', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $this->pdv()->getJson('/api/pdv/ticket?q=LAV2B22')->assertOk()->assertJsonCount(2, 'achados');
        $this->pdv()->getJson('/api/pdv/ticket?q=ZZZ9Z99')->assertStatus(404)->assertJsonPath('erro', 'Nenhum ticket aberto com esse QR, número ou placa.');
        $this->assertNotEmpty($l);
    }
}
