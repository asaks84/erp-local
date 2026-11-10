<?php

namespace Tests\Feature;

use App\Models\CadastroPendente;
use App\Models\Cliente;
use App\Models\MensalidadeContrato;
use App\Models\Veiculo;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Formulário público → fila de espera → aprovação. */
class CadastroPublicoTest extends TestCase
{
    use RefreshDatabase;

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'ana souza', 'documento' => '529.982.247-25', 'celular' => '13999990000', 'cep' => '11090-190',
            'logradouro' => 'rua x', 'numero' => '10', 'bairro' => 'centro', 'cidade' => 'santos', 'uf' => 'sp',
            'placa' => 'abc1d23', 'modelo' => 'onix', 'aceite' => true,
        ], $extra);
    }

    private function empresaComLink(): object
    {
        $a = $this->criarEmpresa('a');
        $a->empresa->update(['codigo_publico' => 'abc12345']);

        return $a;
    }

    public function test_envio_publico_entra_na_fila(): void
    {
        $a = $this->empresaComLink();
        $this->get('/cadastro/abc12345')->assertOk()->assertInertia(fn ($p) => $p->component('Cadastro/Publico'));
        $this->post('/cadastro/abc12345', $this->dados())->assertRedirect('/cadastro/abc12345/enviado');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $p = CadastroPendente::firstOrFail();
        $this->assertSame('ANA SOUZA', $p->nome);
        $this->assertSame('ABC1D23', $p->placa);
        $this->assertNotNull($p->consentimento_em);
    }

    public function test_sem_aceite_nao_envia_e_robo_e_ignorado(): void
    {
        $a = $this->empresaComLink();
        $this->post('/cadastro/abc12345', $this->dados(['aceite' => false]))->assertSessionHasErrors('aceite');
        $this->post('/cadastro/abc12345', $this->dados(['site' => 'spam']))->assertRedirect('/cadastro/abc12345/enviado');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame(0, CadastroPendente::count());
    }

    public function test_codigo_errado_e_pagina_nao_encontrada(): void
    {
        $this->empresaComLink();
        $this->get('/cadastro/outro')->assertNotFound();
        $this->get('/cadastro')->assertRedirect('/cadastro/abc12345');
    }

    public function test_aprovar_cria_cliente_veiculo_e_mensalidade_e_apaga_da_fila(): void
    {
        $a = $this->empresaComLink();
        $this->post('/cadastro/abc12345', $this->dados());
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $p = CadastroPendente::firstOrFail();

        $this->actingAs($a->operador)->get("/pendentes/{$p->id}")->assertForbidden(); // operador não aprova
        $this->actingAs($a->admin)->get('/')->assertInertia(fn ($x) => $x->where('fila', 1));
        $this->actingAs($a->admin)->post("/pendentes/{$p->id}/aprovar", array_merge($this->dados(), [
            'modo' => 'novo', 'dia_vencimento' => 5, 'tipo_veiculo_id' => $a->tipo->id,
            'mensalista' => true, 'periodo' => 'Diurno', 'valor' => 150, 'inicio' => '2026-11-10', 'unidade_id' => $a->unidade->id,
        ]))->assertRedirect();

        $c = Cliente::firstOrFail();
        $this->assertSame('cadastro_publico', $c->origem);
        $this->assertSame('ABC1D23', Veiculo::firstOrFail()->placa);
        $this->assertSame('150.00', MensalidadeContrato::firstOrFail()->valor);
        $this->assertSame(0, CadastroPendente::count());
    }

    public function test_vincular_a_cliente_existente(): void
    {
        $a = $this->empresaComLink();
        app(EmpresaAtual::class)->como($a->empresa->id, fn () => Cliente::create(['nome' => 'ANA', 'documento' => '52998224725', 'dia_vencimento' => 5]));
        $this->post('/cadastro/abc12345', $this->dados(['placa' => 'XYZ9A99']));
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $p = CadastroPendente::firstOrFail();
        $this->actingAs($a->admin)->get('/pendentes')->assertInertia(fn ($x) => $x->where('pendentes.0.cliente_existente', 'ANA'));
        $this->actingAs($a->admin)->post("/pendentes/{$p->id}/aprovar", [
            'modo' => 'vincular', 'documento' => '529.982.247-25', 'placa' => 'XYZ9A99', 'tipo_veiculo_id' => $a->tipo->id, 'mensalista' => false,
        ])->assertRedirect();
        $this->assertSame(1, Cliente::count());
        $this->assertSame(Cliente::first()->id, Veiculo::first()->cliente_id);
    }

    public function test_recusar_apaga(): void
    {
        $a = $this->empresaComLink();
        $this->post('/cadastro/abc12345', $this->dados());
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->actingAs($a->admin)->post('/pendentes/'.CadastroPendente::first()->id.'/recusar')->assertRedirect('/pendentes');
        $this->assertSame(0, CadastroPendente::count());
    }
}
