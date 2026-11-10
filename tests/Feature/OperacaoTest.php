<?php

namespace Tests\Feature;

use App\Models\Convenio;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Servicos\Baixa;
use App\Servicos\Cobrancas;
use App\Servicos\Estadias;
use App\Servicos\FormasPagamento;
use App\Servicos\Vendas;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Etapa 4: estadias com tarifador e convênio, venda de lavagens e serviços, etapa da lavagem. */
class OperacaoTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    private object $conv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        // tabela de carros: 10 min de tolerância, 1ª hora R$ 15, depois R$ 5 por hora
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        $t->tipos()->attach($this->a->tipo->id);
        $this->conv = $this->criarCliente($this->a, 'CLINICA SORRISO', 'CNV1A11');
        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pix(): int
    {
        return FormaPagamento::where('tipo', 'pix')->value('id');
    }

    private function convenio(array $d): Convenio
    {
        return Convenio::create($d + ['cliente_id' => $this->conv->cliente->id, 'vale_estadia' => true]);
    }

    // ---------------------------------------------------------------- estadias

    public function test_avulso_entra_e_paga_na_saida(): void
    {
        $e = Estadias::entrar(['placa' => 'xyz-9z99', 'tipo_veiculo_id' => $this->a->tipo->id, 'entrada' => '2026-11-10 08:10']);
        $this->assertSame('XYZ9Z99', $e->placa);
        $this->assertNull($e->cliente_id);
        // 8h10 às 10h45 = 2h35: 15 + 5 + 5 (blocos de 60 começados)
        $c = Estadias::calcular($e, Carbon::parse('2026-11-10 10:45'));
        $this->assertSame(155, $c['minutos']);
        $this->assertSame(25.0, $c['valor']);

        $e = Estadias::sair($e, ['saida' => '2026-11-10 10:45', 'destino' => 'pago', 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('finalizada', $e->situacao);
        $this->assertSame('pago', $e->destino);
        $o = OrdemServico::find($e->ordem_id);
        $this->assertSame('paga', $o->situacao);
        $this->assertSame('25.00', $o->valor);
        $this->assertSame('estadia', $o->tipo);
        $this->assertNull(Pagamento::first()->cliente_id);
    }

    public function test_placa_nao_entra_duas_vezes_e_cadastro_traz_o_tipo(): void
    {
        $e = Estadias::entrar(['placa' => 'ABC1D23']); // veículo do cliente (tipo vem do cadastro)
        $this->assertSame($this->x->cliente->id, $e->cliente_id);
        $this->expectException(ValidationException::class);
        Estadias::entrar(['placa' => 'ABC1D23']);
    }

    public function test_tolerancia_nao_gera_conta(): void
    {
        $e = Estadias::entrar(['placa' => 'ABC1D23', 'entrada' => '2026-11-10 11:55']);
        $e = Estadias::sair($e, ['saida' => '2026-11-10 12:00', 'destino' => 'pago']);
        $this->assertSame('gratis', $e->destino);
        $this->assertNull($e->ordem_id);
        $this->assertSame(0, OrdemServico::count());
    }

    public function test_na_conta_do_cliente_e_mensalista(): void
    {
        $e = Estadias::entrar(['placa' => 'ABC1D23', 'entrada' => '2026-11-10 09:00']);
        Estadias::sair($e, ['saida' => '2026-11-10 12:00', 'destino' => 'conta']);
        $this->assertSame(25.0, Baixa::totalAberto($this->x->cliente));

        MensalidadeContrato::create(['unidade_id' => $this->a->unidade->id, 'cliente_id' => $this->x->cliente->id, 'veiculo_id' => $this->x->veiculo->id,
            'periodo' => 'Diurno', 'valor' => 200, 'inicio' => '2026-01-01']);
        $m = Estadias::entrar(['placa' => 'ABC1D23', 'entrada' => '2026-11-10 10:00']);
        $this->assertTrue($m->mensalista);
        $m = Estadias::sair($m, ['saida' => '2026-11-10 12:00', 'destino' => 'mensalista']);
        $this->assertSame('mensalista', $m->destino);
        $this->assertSame(25.0, Baixa::totalAberto($this->x->cliente)); // nada novo
    }

    public function test_convenio_paga_tudo_vai_para_a_conta_do_convenio(): void
    {
        $c = $this->convenio(['modo' => 'tudo']);
        $e = Estadias::entrar(['placa' => 'QQQ1Q11', 'tipo_veiculo_id' => $this->a->tipo->id, 'convenio_id' => $c->id, 'entrada' => '2026-11-10 09:00']);
        $e = Estadias::sair($e, ['saida' => '2026-11-10 12:00', 'destino' => 'pago']);
        $this->assertSame('convenio', $e->destino);
        $this->assertSame(25.0, Baixa::totalAberto($this->conv->cliente));
        $this->assertStringContainsString('QQQ1Q11', OrdemServico::find($e->ordem_convenio_id)->descricao);
        // fechamento do convênio = a cobrança do mês do cliente-convênio, com os usos
        $cob = Cobrancas::emitir($this->conv->cliente, '2026-11');
        $this->assertSame('25.00', $cob->total);
    }

    public function test_convenio_por_tempo_excedente_com_quem_usou(): void
    {
        $c = $this->convenio(['modo' => 'tempo', 'limite_min' => 60]);
        $e = Estadias::entrar(['placa' => 'QQQ1Q11', 'tipo_veiculo_id' => $this->a->tipo->id, 'convenio_id' => $c->id, 'entrada' => '2026-11-10 09:00']);
        $calc = Estadias::calcular($e, Carbon::parse('2026-11-10 12:00'));
        $this->assertSame(15.0, $calc['convenio']);   // a 1ª hora
        $this->assertSame(10.0, $calc['cliente']);
        $e = Estadias::sair($e, ['saida' => '2026-11-10 12:00', 'destino' => 'pago', 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('15.00', $e->valor_convenio);
        $this->assertSame('paga', OrdemServico::find($e->ordem_id)->situacao);
        $this->assertSame('aberta', OrdemServico::find($e->ordem_convenio_id)->situacao);
    }

    public function test_convenio_desconto(): void
    {
        $c = $this->convenio(['modo' => 'desconto', 'desconto_tipo' => 'pct', 'desconto_valor' => 20]);
        $e = Estadias::entrar(['placa' => 'QQQ1Q11', 'tipo_veiculo_id' => $this->a->tipo->id, 'convenio_id' => $c->id, 'entrada' => '2026-11-10 09:00']);
        $e = Estadias::sair($e, ['saida' => '2026-11-10 12:00', 'destino' => 'pago', 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame('5.00', $e->desconto);
        $this->assertSame('20.00', $e->valor_cliente);
        $this->assertNull($e->ordem_convenio_id);
    }

    public function test_cancelar_so_no_patio(): void
    {
        $e = Estadias::entrar(['placa' => 'ABC1D23']);
        $this->actingAs($this->a->operador)->post("/estadias/{$e->id}/cancelar", ['motivo' => 'entrada errada'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $e->fresh()->situacao);
    }

    public function test_telas_de_estadia(): void
    {
        $this->actingAs($this->a->operador)->post('/estadias', ['placa' => 'ZZZ1Z11', 'tipo_veiculo_id' => $this->a->tipo->id])->assertSessionHasNoErrors();
        $e = Estadia::firstOrFail();
        $this->actingAs($this->a->operador)->get('/estadias')->assertOk();
        $this->actingAs($this->a->operador)->getJson("/estadias/{$e->id}/calcular")->assertOk()->assertJsonStructure(['valor', 'cliente', 'tempo']);
        Carbon::setTestNow('2026-11-10 13:30:00');
        $this->actingAs($this->a->operador)->post("/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->pix()])->assertSessionHasNoErrors();
        $this->assertSame('finalizada', $e->fresh()->situacao);
        $this->assertSame('20.00', $e->fresh()->valor_cliente);
    }

    // ---------------------------------------------------------------- vendas de lavagens e serviços

    private function servicos(): array
    {
        $lav = Servico::create(['codigo' => 'LS', 'nome' => 'Lavagem simples']);
        $cera = Servico::create(['codigo' => 'CE', 'nome' => 'Cera', 'papel' => 'adicional']);
        $lav->adicionais()->attach($cera->id);
        ServicoPreco::create(['servico_id' => $lav->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 40, 'vigencia_inicio' => '2000-01-01']);
        ServicoPreco::create(['servico_id' => $cera->id, 'tipo_veiculo_id' => $this->a->tipo->id, 'valor' => 15, 'vigencia_inicio' => '2000-01-01']);

        return [$lav, $cera];
    }

    public function test_venda_avulsa_paga_na_entrega_com_etapas(): void
    {
        [$lav, $cera] = $this->servicos();
        [$ordens] = Vendas::vender(['placa' => 'AVU1L50', 'tipo_veiculo_id' => $this->a->tipo->id, 'celular' => '(13) 99999-0000',
            'servico_id' => $lav->id, 'adicionais' => [$cera->id], 'destino' => 'entrega']);
        $this->assertCount(2, $ordens);
        $this->assertSame('13999990000', $ordens[0]->celular);
        $this->assertSame('aguardando', $ordens[1]->etapa);
        Vendas::etapa($ordens[1], 'pronta');
        $this->assertSame('pronta', $ordens[0]->fresh()->etapa);

        Baixa::receberNaHora($ordens, $this->pix());
        $this->assertSame(55.0, (float) Pagamento::first()->valor);
        $this->assertSame(0, OrdemServico::abertas()->count());
    }

    public function test_venda_na_conta_e_pago_na_hora(): void
    {
        [$lav] = $this->servicos();
        Vendas::vender(['placa' => 'ABC1D23', 'servico_id' => $lav->id, 'destino' => 'conta']);
        $this->assertSame(40.0, Baixa::totalAberto($this->x->cliente));
        Vendas::vender(['placa' => 'ABC1D23', 'servico_id' => $lav->id, 'destino' => 'na_hora', 'forma_pagamento_id' => $this->pix()]);
        $this->assertSame(40.0, Baixa::totalAberto($this->x->cliente));
        $this->expectException(ValidationException::class);
        Vendas::vender(['placa' => 'NAO1C00', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $lav->id, 'destino' => 'conta']);
    }

    public function test_venda_com_convenio_que_paga_servicos(): void
    {
        [$lav] = $this->servicos();
        $c = $this->convenio(['modo' => 'tudo', 'vale_servicos' => true]);
        [$ordens] = Vendas::vender(['placa' => 'QQQ1Q11', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $lav->id, 'convenio_id' => $c->id, 'destino' => 'entrega']);
        $this->assertSame($this->conv->cliente->id, $ordens[0]->cliente_id);
        $this->assertSame(40.0, Baixa::totalAberto($this->conv->cliente));
        // convênio por tempo não cobre serviço: quem usou paga
        $t = $this->convenio(['modo' => 'tempo', 'limite_min' => 60, 'vale_servicos' => true, 'cliente_id' => $this->x->cliente->id]);
        [$o2] = Vendas::vender(['placa' => 'QQQ1Q12', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $lav->id, 'convenio_id' => $t->id, 'destino' => 'entrega']);
        $this->assertNull($o2[0]->cliente_id);
    }

    public function test_telas_de_lavagens(): void
    {
        [$lav, $cera] = $this->servicos();
        $this->actingAs($this->a->operador)->get('/lavagens')->assertOk();
        $this->actingAs($this->a->operador)->get('/lavagens/nova')->assertOk();
        $this->actingAs($this->a->operador)->getJson('/lavagens/placa?placa=ABC1D23')->assertOk()->assertJsonPath('veiculo.cliente', 'FULANO');
        $this->actingAs($this->a->operador)->post('/lavagens', ['placa' => 'AVU1L50', 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $lav->id,
            'adicionais' => [$cera->id], 'destino' => 'entrega'])->assertSessionHasNoErrors();
        $o = OrdemServico::whereNull('principal_id')->firstOrFail();
        $this->actingAs($this->a->operador)->post("/lavagens/{$o->id}/etapa", ['etapa' => 'lavando'])->assertSessionHasNoErrors();
        $this->actingAs($this->a->operador)->post("/lavagens/{$o->id}/receber", ['forma_pagamento_id' => $this->pix()])->assertSessionHasNoErrors();
        $this->assertSame(0, OrdemServico::abertas()->count());
    }
}
