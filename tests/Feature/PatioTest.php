<?php

namespace Tests\Feature;

use App\Models\Estadia;
use App\Models\OrdemServico;
use App\Models\Servico;
use App\Models\ServicoPreco;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Models\TipoVeiculo;
use App\Servicos\FormasPagamento;
use App\Servicos\Vendas;
use App\Support\EmpresaAtual;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/** Pátio unificado da gestão: estadias e lavagens juntas e as correções do gerente. */
class PatioTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        $t->tipos()->attach($this->a->tipo->id);
        Carbon::setTestNow('2026-11-10 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lavagem(string $placa): OrdemServico
    {
        $lav = Servico::firstOrCreate(['codigo' => 'LS'], ['nome' => 'Lavagem simples']);
        ServicoPreco::firstOrCreate(['servico_id' => $lav->id, 'tipo_veiculo_id' => $this->a->tipo->id], ['valor' => 40, 'vigencia_inicio' => '2000-01-01']);
        [$ordens] = Vendas::vender(['placa' => $placa, 'tipo_veiculo_id' => $this->a->tipo->id, 'servico_id' => $lav->id, 'destino' => 'entrega']);

        return $ordens[0];
    }

    public function test_agora_e_historico_juntam_estadias_e_lavagens(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/estadias', ['placa' => 'EST1A11', 'modelo' => 'Gol', 'tipo_veiculo_id' => $this->a->tipo->id])->assertSessionHasNoErrors();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $l = $this->lavagem('LAV2B22');
        Vendas::etapa($l, 'pronta');
        Carbon::setTestNow('2026-11-10 11:00:00');

        // agora: as duas, mais antiga primeiro; estadia com o valor de agora (3h = 25); lavagem com estadia R$ 0 (regra desligada)
        $adm->get('/patio')->assertInertia(fn ($p) => $p->component('Patio/Index')->has('linhas', 2)
            ->where('linhas.0.tipo', 'estadia')->where('linhas.0.valor', 25)->where('linhas.0.placa', 'EST1A11')
            ->where('linhas.1.tipo', 'lavagem')->where('linhas.1.valor', 40)->where('linhas.1.estadia', 0)->where('linhas.1.a_receber', 40)
            ->where('totais.estadias', 1)->where('totais.lavagens', 1));

        // regra ligada (tolerância 30 min): pronta às 9h, às 11h cobra 2h de estadia = 20
        $e = $this->a->empresa->fresh();
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['lavagem_estadia_cobrar' => true, 'lavagem_estadia_tolerancia_min' => 30]);
        $e->save();
        $adm->get('/patio')->assertInertia(fn ($p) => $p->where('linhas.1.estadia', 20));

        // histórico: filtro por tipo e totais
        $adm->get('/patio/historico?de=2026-11-10&ate=2026-11-10')->assertInertia(fn ($p) => $p->component('Patio/Historico')
            ->has('linhas.data', 2)->where('totais.estadias', 1)->where('totais.lavagens', 1)->where('totais.patio', 2));
        $adm->get('/patio/historico?de=2026-11-10&ate=2026-11-10&tipo=lavagem')->assertInertia(fn ($p) => $p->has('linhas.data', 1)->where('linhas.data.0.placa', 'LAV2B22'));
        $adm->get('/patio/historico?de=2026-11-10&ate=2026-11-10&q=gol')->assertInertia(fn ($p) => $p->has('linhas.data', 1)->where('linhas.data.0.tipo', 'estadia'));
    }

    public function test_correcoes_da_estadia(): void
    {
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/estadias', ['placa' => 'ERR1A11', 'tipo_veiculo_id' => $this->a->tipo->id])->assertSessionHasNoErrors();
        $e = Estadia::firstOrFail();
        $moto = TipoVeiculo::firstOrCreate(['nome' => 'Moto'], ['ativo' => true]);

        $adm->post("/patio/estadias/{$e->id}/corrigir", ['placa' => 'CER-1A22', 'modelo' => 'Onix', 'tipo_veiculo_id' => $moto->id])->assertSessionHasNoErrors();
        $this->assertSame(['CER1A22', 'ONIX', $moto->id], [$e->fresh()->placa, $e->fresh()->modelo, $e->fresh()->tipo_veiculo_id]);
        $adm->post("/patio/estadias/{$e->id}/corrigir", ['tipo_veiculo_id' => $this->a->tipo->id])->assertSessionHasNoErrors();

        // encerrar esquecida sem receber: hora informada, valor registrado, nenhuma conta
        Carbon::setTestNow('2026-11-10 12:00:00');
        $adm->post("/patio/estadias/{$e->id}/encerrar", ['saida' => '2026-11-10 10:00', 'como' => 'sem_receber', 'motivo' => 'saiu sem baixa'])->assertSessionHasNoErrors();
        $e->refresh();
        $this->assertSame(['finalizada', 'sem_receber', '20.00', '0.00', 'saiu sem baixa'], [$e->situacao, $e->destino, $e->valor_calculado, $e->valor_cliente, $e->observacao]);
        $this->assertSame(0, OrdemServico::count());
        // não corrige depois que saiu
        $adm->post("/patio/estadias/{$e->id}/corrigir", ['modelo' => 'X'])->assertSessionHasErrors('placa');

        // encerrar na conta de um cliente: gera a conta
        $x = $this->criarCliente($this->a, 'FULANO', 'FUL1A11');
        $adm->post('/estadias', ['placa' => 'OUT1A11', 'tipo_veiculo_id' => $this->a->tipo->id]);
        $e2 = Estadia::where('placa', 'OUT1A11')->first();
        Carbon::setTestNow('2026-11-10 14:00:00');
        $adm->post("/patio/estadias/{$e2->id}/encerrar", ['saida' => '2026-11-10 12:00', 'como' => 'conta', 'motivo' => 'esqueceram'])->assertSessionHasErrors('conta_cliente_id');
        $adm->post("/patio/estadias/{$e2->id}/encerrar", ['saida' => '2026-11-10 15:00', 'como' => 'conta', 'conta_cliente_id' => $x->cliente->id, 'motivo' => 'x'])->assertSessionHasErrors('saida');
        $adm->post("/patio/estadias/{$e2->id}/encerrar", ['saida' => '2026-11-10 14:00', 'como' => 'conta', 'conta_cliente_id' => $x->cliente->id, 'motivo' => 'esqueceram'])->assertSessionHasNoErrors();
        $this->assertSame(['conta', $x->cliente->id], [$e2->fresh()->destino, OrdemServico::where('estadia_id', $e2->id)->value('cliente_id')]);

        // cancelar com motivo
        $adm->post('/estadias', ['placa' => 'CAN1A11', 'tipo_veiculo_id' => $this->a->tipo->id]);
        $e3 = Estadia::where('placa', 'CAN1A11')->first();
        $adm->post("/patio/estadias/{$e3->id}/cancelar", ['motivo' => 'duplicada'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $e3->fresh()->situacao);
    }

    public function test_correcoes_da_lavagem_e_permissao(): void
    {
        $l = $this->lavagem('LAV1A11');
        $adm = $this->actingAs($this->a->admin);
        $adm->post("/patio/lavagens/{$l->id}/corrigir", ['placa' => 'LAV1A99', 'modelo' => 'Civic'])->assertSessionHasNoErrors();
        $this->assertSame(['LAV1A99', 'CIVIC'], [$l->fresh()->placa, $l->fresh()->veiculo_descricao]);
        $adm->post("/patio/lavagens/{$l->id}/entregar", ['motivo' => 'já foi embora'])->assertSessionHasNoErrors();
        $this->assertSame('entregue', $l->fresh()->etapa);
        $this->assertSame('aberta', $l->fresh()->situacao); // continua a receber
        $adm->post("/patio/lavagens/{$l->id}/cancelar", ['motivo' => 'x'])->assertSessionHasErrors('motivo');

        $l2 = $this->lavagem('LAV2A22');
        $adm->post("/patio/lavagens/{$l2->id}/cancelar", ['motivo' => 'lançada errado'])->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $l2->fresh()->situacao);

        // operador vê o pátio, mas não corrige
        $this->actingAs($this->a->operador)->get('/patio')->assertOk();
        $l3 = $this->lavagem('LAV3A33');
        $this->actingAs($this->a->operador)->post("/patio/lavagens/{$l3->id}/cancelar", ['motivo' => 'x'])->assertForbidden();
    }
}
