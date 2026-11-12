<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\MensalidadeContrato;
use App\Models\Veiculo;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Clientes, veículos e mensalidades pelas telas. */
class CadastrosTest extends TestCase
{
    use RefreshDatabase;

    private function dadosCliente(array $extra = []): array
    {
        return array_merge(['nome' => 'maria da silva', 'documento' => '529.982.247-25', 'celular' => '(13) 99999-0000', 'dia_vencimento' => 10], $extra);
    }

    public function test_cadastra_cliente_em_maiusculas_e_so_numeros(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->post('/clientes', $this->dadosCliente())->assertRedirect();
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $c = Cliente::first();
        $this->assertSame('MARIA DA SILVA', $c->nome);
        $this->assertSame('52998224725', $c->documento);
        $this->assertSame('13999990000', $c->celular);
        $this->assertSame('gestao', $c->origem);
    }

    public function test_cpf_invalido_e_repetido(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->post('/clientes', $this->dadosCliente(['documento' => '111.111.111-11']))->assertSessionHasErrors('documento');
        $this->actingAs($a->operador)->post('/clientes', $this->dadosCliente());
        $this->actingAs($a->operador)->post('/clientes', $this->dadosCliente(['nome' => 'outra']))
            ->assertSessionHasErrors(['documento' => 'Este CPF/CNPJ já está cadastrado em outro cliente.']);
        // em outra empresa o mesmo CPF pode
        $b = $this->criarEmpresa('b');
        $this->actingAs($b->operador)->post('/clientes', $this->dadosCliente())->assertSessionHasNoErrors();
    }

    public function test_cnpj_valido(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->post('/clientes', $this->dadosCliente(['documento' => '11.222.333/0001-81']))->assertSessionHasNoErrors();
    }

    public function test_veiculo_com_mensalidade_e_placa_normalizada(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a, 'JOAO', 'XYZ1A23');
        $this->actingAs($a->operador)->post("/clientes/{$x->cliente->id}/veiculos", [
            'placa' => 'abc-1234', 'tipo_veiculo_id' => $a->tipo->id, 'modelo' => 'gol',
            'mensalista' => true, 'periodo' => 'Diurno', 'valor' => 180.5, 'inicio' => '2026-11-01', 'unidade_id' => $a->unidade->id,
        ]);
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $v = Veiculo::where('placa', 'ABC1234')->firstOrFail();
        // mensalista novo segue para a primeira cobrança
        $r = MensalidadeContrato::where('veiculo_id', $v->id)->value('id');
        $this->actingAs($a->operador)->get("/mensalidades/{$r}/primeira-cobranca?de=veiculo")->assertOk();
        $this->assertSame('GOL', $v->modelo);
        $this->assertSame('ABC-1234', $v->rotulo());
        $c = MensalidadeContrato::where('veiculo_id', $v->id)->firstOrFail();
        $this->assertSame('180.50', $c->valor);
        $this->assertSame($x->cliente->id, $c->cliente_id);
    }

    public function test_placa_repetida_so_entre_veiculos_ativos(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a, 'JOAO', 'ABC1D23');
        $novo = ['placa' => 'ABC1D23', 'tipo_veiculo_id' => $a->tipo->id];
        $this->actingAs($a->admin)->post("/clientes/{$x->cliente->id}/veiculos", $novo)->assertSessionHasErrors('placa');
        $this->actingAs($a->admin)->post("/veiculos/{$x->veiculo->id}/remover");
        $this->actingAs($a->admin)->post("/clientes/{$x->cliente->id}/veiculos", $novo)->assertSessionHasNoErrors();
        // o removido não pode voltar enquanto a placa estiver em uso
        $this->actingAs($a->admin)->post("/veiculos/{$x->veiculo->id}/reativar")->assertSessionHas('erro');
    }

    public function test_veiculo_sem_placa_exige_identificacao(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $this->actingAs($a->operador)->post("/clientes/{$x->cliente->id}/veiculos", ['placa' => '', 'tipo_veiculo_id' => $a->moto->id])
            ->assertSessionHasErrors('identificacao');
        $this->actingAs($a->operador)->post("/clientes/{$x->cliente->id}/veiculos", ['placa' => '', 'identificacao' => 'bike azul', 'tipo_veiculo_id' => $a->moto->id])
            ->assertSessionHasNoErrors();
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame('BIKE AZUL', Veiculo::whereNull('placa')->first()->rotulo());
    }

    public function test_troca_de_placa_fica_no_historico(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a, 'JOAO', 'ABC1234');
        $this->actingAs($a->operador)->put("/veiculos/{$x->veiculo->id}", ['placa' => 'ABC1C34', 'tipo_veiculo_id' => $a->tipo->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('veiculo_placas', ['veiculo_id' => $x->veiculo->id, 'placa_anterior' => 'ABC1234', 'placa_nova' => 'ABC1C34']);
    }

    public function test_busca_por_nome_codigo_placa_e_documento(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a, 'JOAO DAS COUVES', 'QWE1R23');
        $this->criarCliente($a, 'OUTRO', 'ZZZ9Z99');
        foreach (['couves', 'qwe-1r', (string) $x->cliente->id] as $termo) {
            $r = $this->actingAs($a->operador)->getJson('/busca/clientes?q='.urlencode($termo))->assertOk()->json();
            $this->assertSame('JOAO DAS COUVES', $r[0]['nome'] ?? null, "busca por {$termo}");
        }
        // não acha cliente de outra empresa
        $b = $this->criarEmpresa('b');
        $this->assertSame([], $this->actingAs($b->operador)->getJson('/busca/clientes?q=couves')->json());
    }

    public function test_remover_cliente_desativa_e_encerra_mensalidade(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        app(EmpresaAtual::class)->como($a->empresa->id, fn () => MensalidadeContrato::create([
            'unidade_id' => $a->unidade->id, 'cliente_id' => $x->cliente->id, 'veiculo_id' => $x->veiculo->id, 'periodo' => 'Diurno', 'valor' => 100, 'inicio' => '2026-01-01',
        ]));
        $this->actingAs($a->operador)->post("/clientes/{$x->cliente->id}/remover")->assertForbidden(); // sem permissão
        $this->actingAs($a->admin)->post("/clientes/{$x->cliente->id}/remover")->assertRedirect('/clientes');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertFalse($x->cliente->fresh()->ativo);
        $this->assertFalse($x->veiculo->fresh()->ativo);
        $this->assertSame(now()->toDateString(), MensalidadeContrato::first()->fim->toDateString());
    }

    public function test_remover_cliente_com_conta_em_aberto_pergunta_o_que_fazer(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $y = $this->criarCliente($a, 'PEDRO', 'PQR1S23');
        $o = app(EmpresaAtual::class)->como($a->empresa->id, function () use ($a, $x, $y) {
            \Illuminate\Support\Facades\Auth::login($a->admin);
            $o = \App\Servicos\Ordens::lancarNaConta($x->cliente, 'debito', 80, 'Débito', now()->toDateString(), 'teste');
            \App\Servicos\Ordens::lancarNaConta($x->cliente, 'credito', 10, 'Crédito', now()->toDateString(), 'teste');
            \App\Servicos\Ordens::lancarNaConta($y->cliente, 'debito', 50, 'Débito', now()->toDateString(), 'teste');

            return $o;
        });
        // sem escolher: não remove
        $this->actingAs($a->admin)->post("/clientes/{$x->cliente->id}/remover")->assertSessionHasErrors('contas');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertTrue($x->cliente->fresh()->ativo);

        // excluir: as contas a receber são canceladas; o crédito fica
        $this->actingAs($a->admin)->post("/clientes/{$x->cliente->id}/remover", ['contas' => 'cancelar'])->assertRedirect('/clientes');
        $this->assertFalse($x->cliente->fresh()->ativo);
        $this->assertSame('cancelada', $o->fresh()->situacao);
        $this->assertSame('Cliente removido', $o->fresh()->motivo_cancelamento);
        $this->assertSame(1, \App\Models\OrdemServico::where('cliente_id', $x->cliente->id)->where('tipo', 'credito')->where('situacao', 'aberta')->count());

        // manter: removido e a conta continua em aberto, marcada em Contas em aberto
        $this->actingAs($a->admin)->post("/clientes/{$y->cliente->id}/remover", ['contas' => 'manter'])->assertRedirect('/clientes');
        $this->assertFalse($y->cliente->fresh()->ativo);
        $this->assertSame(50.0, \App\Servicos\Baixa::totalAberto($y->cliente->fresh()));
        $this->actingAs($a->admin)->get('/contas/abertas')->assertInertia(fn ($p) => $p->where('linhas.0.ativo', false));
    }

    public function test_ficha_e_lista_abrem(): void
    {
        $a = $this->criarEmpresa('a');
        $x = $this->criarCliente($a);
        $this->actingAs($a->operador)->get("/clientes/{$x->cliente->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Clientes/Show')->has('veiculos', 1)->where('veiculos.0.rotulo', 'ABC1D23'));
        $this->actingAs($a->operador)->get('/clientes?q=ABC1D')->assertOk()
            ->assertInertia(fn ($p) => $p->has('clientes.data', 1));
        $this->actingAs($a->operador)->get("/clientes/{$x->cliente->id}/veiculos/novo")->assertOk();
        $this->actingAs($a->operador)->get("/veiculos/{$x->veiculo->id}/editar")->assertOk();
        $this->actingAs($a->operador)->get("/clientes/{$x->cliente->id}/editar")->assertOk();
    }
}
