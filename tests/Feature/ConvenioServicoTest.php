<?php

namespace Tests\Feature;

use App\Models\Cobranca;
use App\Models\Convenio;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Servicos\Convenios;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Vendas;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Convênio por serviço (integral, parcial, tempo), fechamento programado e "na conta de" outro cliente. */
class ConvenioServicoTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $mensalista;

    private object $conv;

    private Servico $lav;

    private Servico $cera;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00'); // terça
        $this->a = $this->criarEmpresa('a');
        $this->mensalista = $this->criarCliente($this->a, 'JOAO MENSALISTA', 'JOA1O11');
        $this->conv = $this->criarCliente($this->a, 'OFICINA DO ZE', 'OFC1N11');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function convenio(array $regras, array $d = []): Convenio
    {
        $c = Convenio::create($d + ['cliente_id' => $this->conv->cliente->id]);
        Convenios::salvarRegras($c, $regras);

        return $c->fresh();
    }

    public function test_na_conta_de_outro_cliente(): void
    {
        // mensalista traz o carro do pai (sem cadastro) e a lavagem vai para a conta dele
        [$ordens, $msg] = Vendas::vender(['placa' => 'PAI1A23', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id,
            'destino' => 'conta', 'conta_cliente_id' => $this->mensalista->cliente->id]);
        $this->assertSame([$this->mensalista->cliente->id, 'PAI1A23'], [$ordens[0]->cliente_id, $ordens[0]->placa]);
        $this->assertStringContainsString('na conta de JOAO MENSALISTA', $msg);
        // sem dono e sem escolher: pede o cliente
        try {
            Vendas::vender(['placa' => 'PAI1A24', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'destino' => 'conta']);
            $this->fail('devia pedir o cliente');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('conta_cliente_id', $e->errors());
        }
        // dono do veículo é o padrão
        [$o2] = Vendas::vender(['placa' => 'JOA1O11', 'servico_id' => $this->lav->id, 'destino' => 'conta']);
        $this->assertSame($this->mensalista->cliente->id, $o2[0]->cliente_id);

        // estadia: saída na conta de outro cliente, pela tela
        $e = Estadias::entrar(['placa' => 'IRM1A00', 'tipo_veiculo_id' => $this->a->tipo->id, 'entrada' => '2026-11-10 09:00']);
        $this->actingAs($this->a->admin)->post("/estadias/{$e->id}/saida", ['saida' => '2026-11-10 11:00', 'destino' => 'conta', 'conta_cliente_id' => $this->mensalista->cliente->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->mensalista->cliente->id, OrdemServico::find($e->fresh()->ordem_id)->cliente_id);
    }

    public function test_regra_por_servico_integral_parcial_e_nao_marcado(): void
    {
        // lavagem: convênio paga até R$ 30; cera não marcada: quem usou paga
        $c = $this->convenio([['servico_id' => $this->lav->id, 'modo' => 'parcial', 'limite_valor' => 30]]);
        [$ordens] = Vendas::vender(['placa' => 'AVU1L50', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'adicionais' => [$this->cera->id],
            'convenio_id' => $c->id, 'destino' => 'entrega']);
        $porCliente = collect($ordens)->groupBy(fn ($o) => $o->cliente_id ?? 0)->map(fn ($g) => $g->sum(fn ($o) => (float) $o->valor));
        $this->assertSame(30.0, $porCliente[$this->conv->cliente->id]);   // convênio: R$ 30 da lavagem
        $this->assertSame(25.0, $porCliente[0]);                          // quem usou: R$ 10 da lavagem + R$ 15 da cera

        // estadia não marcada: o convênio não aparece para estadia
        try {
            Estadias::entrar(['placa' => 'AVU1L51', 'tipo_veiculo_id' => $this->a->tipo->id, 'convenio_id' => $c->id]);
            $this->fail('convênio sem estadia');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('convenio_id', $e->errors());
        }

        // integral na estadia
        Convenios::salvarRegras($c, [['servico_id' => null, 'modo' => 'integral'], ['servico_id' => $this->cera->id, 'modo' => 'integral']]);
        $e = Estadias::entrar(['placa' => 'AVU1L51', 'tipo_veiculo_id' => $this->a->tipo->id, 'convenio_id' => $c->id, 'entrada' => '2026-11-10 08:00']);
        $calc = Estadias::calcular($e, Carbon::parse('2026-11-10 11:00'));
        $this->assertSame([25.0, 0.0], [$calc['convenio'], $calc['cliente']]);
        $this->assertSame('Estadia: paga tudo · Cera: paga tudo', $c->fresh()->resumo());
    }

    public function test_fechamento_semanal_avisa_e_gera_cobranca_so_do_convenio(): void
    {
        $c = $this->convenio([['servico_id' => $this->lav->id, 'modo' => 'integral']], ['fechamento' => 'semana', 'fechamento_semana' => 5]); // sexta
        // o conveniado também tem uma conta própria, que não entra no fechamento semanal
        \App\Servicos\Ordens::lancar(['tipo' => 'avulso', 'cliente_id' => $this->conv->cliente->id, 'valor' => 99, 'descricao' => 'Outra conta', 'data' => '2026-11-10', 'vencimento' => '2026-11-30']);
        Vendas::vender(['placa' => 'AVU1L50', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'convenio_id' => $c->id, 'destino' => 'entrega']);
        $this->assertSame('Toda sexta', $c->resumoFechamento());
        $this->assertSame('2026-11-13', Convenios::proximoFechamento($c)->toDateString());
        $this->assertCount(0, Convenios::aFechar());

        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->assertCount(1, Convenios::aFechar());
        $this->actingAs($this->a->admin)->get('/')->assertInertia(fn ($p) => $p->has('convenios_fechar', 1)->where('convenios_fechar.0.total', 40));
        $this->actingAs($this->a->operador)->post("/convenios/{$c->id}/fechar")->assertForbidden();
        $this->actingAs($this->a->admin)->post("/convenios/{$c->id}/fechar")->assertRedirect();
        $cob = Cobranca::latest('id')->first();
        $this->assertSame(['40.00', '2026-11-20'], [$cob->total, $cob->vencimento->toDateString()]);
        $this->assertSame('2026-11-13', $c->fresh()->ultimo_fechamento->toDateString());
        $this->assertCount(0, Convenios::aFechar());

        // semana seguinte, no mesmo mês: outra cobrança do convênio
        Vendas::vender(['placa' => 'AVU1L52', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'convenio_id' => $c->id, 'destino' => 'entrega']);
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->assertSame('2026-11-20', Convenios::proximoFechamento($c->fresh())->toDateString());
        Convenios::fechar($c->fresh());
        $this->assertSame(2, Cobranca::where('cliente_id', $this->conv->cliente->id)->count());
        // a conta própria ficou de fora dos fechamentos semanais
        $this->assertNull(OrdemServico::where('descricao', 'Outra conta')->value('cobranca_id'));
    }

    public function test_fechamento_a_cada_x_dias_e_com_a_mensalidade(): void
    {
        $c = $this->convenio([['servico_id' => $this->lav->id, 'modo' => 'integral']], ['fechamento' => 'dias', 'fechamento_dias' => 10]);
        Vendas::vender(['placa' => 'AVU1L50', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $this->lav->id, 'convenio_id' => $c->id, 'destino' => 'entrega']);
        $this->assertSame('2026-11-20', Convenios::proximoFechamento($c)->toDateString());

        $c->update(['fechamento' => 'mensalidade']);
        $this->conv->cliente->update(['dia_vencimento' => 15]);
        $this->assertSame('2026-11-15', Convenios::proximoFechamento($c->fresh('cliente'))->toDateString());
    }

    public function test_importacao_nao_mexe_no_convenio_editado_no_erp(): void
    {
        $c = Convenio::create(['cliente_id' => $this->conv->cliente->id, 'modo' => 'tudo', 'vale_estadia' => true, 'vale_servicos' => true]);
        $this->assertCount(3, $c->regras); // estadia + 2 serviços, vindos do modo antigo
        $c->update(['modo' => 'tempo', 'limite_min' => 60]);
        Convenios::refazerDoLegado($c);
        $this->assertSame('Estadia: paga até 1h', $c->fresh()->resumo());

        Convenios::salvarRegras($c, [['servico_id' => $this->lav->id, 'modo' => 'integral']]);
        $c->update(['modo' => 'tudo']);
        Convenios::refazerDoLegado($c->fresh());
        $this->assertSame('Lavagem simples: paga tudo', $c->fresh()->resumo());
    }
}
