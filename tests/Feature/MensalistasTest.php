<?php

namespace Tests\Feature;

use App\Models\MensalidadeContrato;
use App\Models\Nivel;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Clientes › Mensalistas: resumo por grupo e período, lista por tipo, receita só com faturamento. */
class MensalistasTest extends TestCase
{
    use RefreshDatabase;

    public function test_resumo_lista_filtro_e_permissoes(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $carro = $this->criarCliente($a, 'ANA', 'AAA1A11');
        $moto = $this->criarCliente($a, 'BRUNO', 'BBB2B22');
        $moto->veiculo->update(['tipo_veiculo_id' => $a->moto->id]);
        $saiu = $this->criarCliente($a, 'CARLA', 'CCC3C33');
        $base = ['unidade_id' => $a->unidade->id, 'inicio' => '2026-01-01'];
        MensalidadeContrato::create($base + ['cliente_id' => $carro->cliente->id, 'veiculo_id' => $carro->veiculo->id, 'periodo' => 'Diurno', 'valor' => 200, 'legado_id' => 501]);
        MensalidadeContrato::create($base + ['cliente_id' => $moto->cliente->id, 'veiculo_id' => $moto->veiculo->id, 'periodo' => 'Noturno', 'valor' => 90]);
        MensalidadeContrato::create($base + ['cliente_id' => $saiu->cliente->id, 'veiculo_id' => $saiu->veiculo->id, 'periodo' => 'Diurno', 'valor' => 150]);
        $saiu->cliente->update(['ativo' => false]);     // excluído: não conta

        $adm = $this->actingAs($a->admin);
        $adm->get('/clientes/mensalistas')->assertOk()->assertInertia(fn ($p) => $p->component('Clientes/Mensalistas')
            ->where('controle.carro.Diurno.qtd', 1)->where('controle.carro.Diurno.valor', 200)
            ->where('controle.moto.Noturno.qtd', 1)->where('receita', true)->has('lista', 2)
            // a moto foi cadastrada só no ERP: marcada e somada à parte (explica a diferença com o sistema atual)
            ->where('so_erp.qtd', 1)->where('so_erp.valor', 90)->where('lista.0.so_erp', false)->where('lista.1.so_erp', true));
        $adm->get('/clientes/mensalistas?tipos[]='.$a->moto->id)->assertInertia(fn ($p) => $p->has('lista', 1)->where('lista.0.cliente', 'BRUNO'));
        $this->assertStringContainsString('ANA', $adm->get('/clientes/mensalistas?csv=1')->streamedContent());

        // sem faturamento: quantidades sim, receita não
        $nivel = Nivel::create(['nome' => 'Relatórios', 'permissoes' => ['rel_mensalistas']]);
        $a->operador->update(['nivel_id' => $nivel->id]);
        $this->actingAs($a->operador->fresh())->get('/clientes/mensalistas')->assertInertia(fn ($p) => $p->where('receita', false)
            ->where('controle.carro.Diurno.qtd', 1)->where('controle.carro.Diurno.valor', 0)->where('lista.0.valor', null));
        // sem a permissão: não abre
        $this->actingAs($a->gerente)->get('/clientes/mensalistas')->assertForbidden();
    }
}
