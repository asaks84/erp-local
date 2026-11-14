<?php

namespace Tests\Feature;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\MensalidadeContrato;
use App\Models\OrdemServico;
use App\Models\Veiculo;
use App\Servicos\NumeracaoClientes;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NumeracaoClientesTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrige_colisao_de_codigo_e_desativa_o_duplicado(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        Auth::login($a->admin);
        $base = ['unidade_id' => $a->unidade->id, 'inicio' => '2026-11-01', 'periodo' => 'Diurno'];

        // teste criado no ERP com o código 1009 (pago por boleto)
        $teste = $this->criarCliente($a, 'CAUE', 'DJN5226');
        NumeracaoClientes::renumerar($teste->cliente->id, 1009);
        MensalidadeContrato::create($base + ['cliente_id' => 1009, 'veiculo_id' => $teste->veiculo->id, 'valor' => 250]);
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => 1009, 'valor' => 250, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-05', 'competencia' => '2026-11']);

        // ficha aprovada no ERP por engano: 1010, com CPF e placa
        $erp = $this->criarCliente($a, 'MATHEUS', 'CUV1996');
        NumeracaoClientes::renumerar($erp->cliente->id, 1010);
        Cliente::whereKey(1010)->update(['documento' => '52998224725', 'origem' => 'cadastro_publico']);
        MensalidadeContrato::create($base + ['cliente_id' => 1010, 'veiculo_id' => $erp->veiculo->id, 'valor' => 220]);
        $aberta = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => 1010, 'valor' => 220, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-10', 'competencia' => '2026-11']);

        // o mesmo cliente aprovado no sistema atual (código 1009 lá): entrou como 1011, sem CPF e sem placa (repetidos)
        $imp = $this->criarCliente($a, 'MATHEUS', null);
        NumeracaoClientes::renumerar($imp->cliente->id, 1011);
        Cliente::whereKey(1011)->update(['legado_id' => 1009, 'documento' => null]);
        Veiculo::whereKey($imp->veiculo->id)->update(['placa' => null, 'identificacao' => 'CUV-1996', 'legado_id' => 77]);
        MensalidadeContrato::create($base + ['cliente_id' => 1011, 'veiculo_id' => $imp->veiculo->id, 'valor' => 220, 'legado_id' => 55]);

        $r = NumeracaoClientes::corrigir();

        // o duplicado (criado no ERP) foi excluído com veículo, mensalidade e conta em aberto: o 1010 ficou livre
        $this->assertSame([[1010, 1011]], $r['excluidos']);
        $this->assertNull(Cliente::find(1010));
        $this->assertNull(Veiculo::find($erp->veiculo->id));
        $this->assertNull(OrdemServico::find($aberta->id));
        $this->assertSame(0, MensalidadeContrato::where('cliente_id', 1010)->count());

        // o teste saiu do 1009 para o próximo número livre, com veículo e conta junto
        $this->assertSame([[1009, 1012]], $r['liberados']);
        $this->assertSame('CAUE', Cliente::find(1012)->nome);
        $this->assertSame(1012, Veiculo::find($teste->veiculo->id)->cliente_id);
        $this->assertSame(1012, OrdemServico::find($o->id)->cliente_id);

        // o importado voltou ao código do sistema atual, com CPF e placa
        $m = Cliente::find(1009);
        $this->assertSame('MATHEUS', $m->nome);
        $this->assertSame(1009, $m->legado_id);
        $this->assertSame('52998224725', $m->documento);
        $this->assertSame('CUV1996', Veiculo::find($imp->veiculo->id)->placa);
        $this->assertNull(Cliente::find(1011));
        $this->assertSame(3, \App\Models\LogAtividade::where('acao', 'numeracao_clientes')->count());

        // a numeração continua normal (depois do maior código); rodar de novo não muda nada
        $novo = $this->criarCliente($a, 'NOVO', 'NNN1N11')->cliente;
        $this->assertSame(1013, $novo->id);
        $this->assertSame(['excluidos' => [], 'liberados' => [], 'codigo_de_la' => [], 'avisos' => []], NumeracaoClientes::corrigir());
    }

    public function test_duplicado_com_pagamento_nao_e_excluido(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        Auth::login($a->admin);
        $erp = $this->criarCliente($a, 'ANA', 'AAA1A11');
        Cliente::whereKey($erp->cliente->id)->update(['documento' => '52998224725']);
        $o = Ordens::lancar(['tipo' => 'avulso', 'cliente_id' => $erp->cliente->id, 'valor' => 50, 'descricao' => 'Lavagem', 'data' => '2026-11-01', 'vencimento' => '2026-11-01']);
        $o->update(['situacao' => 'paga', 'pago_em' => '2026-11-01']);
        $imp = $this->criarCliente($a, 'ANA', 'BBB2B22');
        Cliente::whereKey($imp->cliente->id)->update(['legado_id' => $imp->cliente->id]);
        Cliente::whereKey($imp->cliente->id)->update(['documento' => null]);
        Veiculo::whereKey($imp->veiculo->id)->update(['placa' => null, 'identificacao' => 'AAA-1A11']);

        $r = NumeracaoClientes::corrigir();
        $this->assertSame([], $r['excluidos']);
        $this->assertCount(1, $r['avisos']);
        $this->assertNotNull(Cliente::find($erp->cliente->id));
    }

    public function test_ficha_do_sistema_atual_nao_se_aprova_no_erp(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $p = CadastroPendente::create(['nome' => 'X', 'placa' => 'ABC1D23', 'recebido_em' => now(), 'consentimento_em' => now(), 'legado_id' => 9]);
        $this->actingAs($a->admin)->post("/pendentes/{$p->id}/aprovar", ['modo' => 'novo', 'placa' => 'ABC1D23', 'tipo_veiculo_id' => $a->tipo->id])
            ->assertSessionHasErrors('modo');
        $this->actingAs($a->admin)->post("/pendentes/{$p->id}/recusar")->assertSessionHasErrors('modo');
        $this->assertSame(0, Cliente::where('nome', 'X')->count());
        $this->assertNotNull(CadastroPendente::find($p->id));
    }
}
