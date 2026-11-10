<?php

namespace Tests\Feature;

use App\Models\LogAtividade;
use App\Models\Nivel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** Uma empresa nunca enxerga nem altera dados de outra. */
class IsolamentoEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultas_so_trazem_a_empresa_atual(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');

        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame(['a-admin', 'a-gerente', 'a-operador'], Usuario::orderBy('login')->pluck('login')->all());
        $this->assertSame(3, Nivel::count());
        $this->assertNull(Usuario::find($b->admin->id));
        $this->assertNull(Unidade::find($b->unidade->id));
    }

    public function test_sem_empresa_definida_nada_aparece(): void
    {
        $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir(null);
        $this->assertSame(0, Usuario::count());
        $this->assertSame(0, Unidade::count());
    }

    public function test_gravar_sem_empresa_e_erro(): void
    {
        app(EmpresaAtual::class)->definir(null);
        $this->expectException(LogicException::class);
        Unidade::create(['nome' => 'Solta']);
    }

    public function test_nao_troca_registro_de_empresa(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $unidade = Unidade::first();
        $unidade->empresa_id = $b->empresa->id;
        $this->expectException(LogicException::class);
        $unidade->save();
    }

    public function test_telas_nao_abrem_registro_de_outra_empresa(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');

        $this->actingAs($a->admin)->get("/usuarios/{$b->admin->id}")->assertNotFound();
        $this->actingAs($a->admin)->get("/niveis/{$b->niveis->gerente->id}")->assertNotFound();
        $this->actingAs($a->admin)->get("/unidades/{$b->unidade->id}")->assertNotFound();
        $this->actingAs($a->admin)->put("/usuarios/{$b->operador->id}", [
            'nome' => 'Invasor', 'login' => 'b-operador', 'nivel_id' => $a->niveis->admin->id, 'ativo' => true,
        ])->assertNotFound();
        $this->assertSame('Operador b', $b->operador->fresh()->nome);
    }

    public function test_lista_e_registro_mostram_so_a_propria_empresa(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');
        app(EmpresaAtual::class)->como($b->empresa->id, fn () => LogAtividade::create(['acao' => 'segredo_b', 'detalhe' => 'não pode vazar']));

        $this->actingAs($a->admin)->get('/usuarios')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Usuarios/Index')->has('usuarios', 3));
        $this->actingAs($a->admin)->get('/atividades?busca=segredo')
            ->assertInertia(fn ($p) => $p->has('atividades.data', 0));
    }

    public function test_nao_aceita_nivel_de_outra_empresa(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');
        $this->actingAs($a->admin)->post('/usuarios', [
            'nome' => 'Novo', 'login' => 'novo', 'senha' => 'segredo1', 'senha_confirmation' => 'segredo1',
            'nivel_id' => $b->niveis->admin->id,
        ])->assertSessionHasErrors('nivel_id');
    }
}
