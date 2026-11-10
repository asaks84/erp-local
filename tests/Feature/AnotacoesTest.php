<?php

namespace Tests\Feature;

use App\Models\Anotacao;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Anotações do Início: cada usuário vê e mexe só nas próprias. */
class AnotacoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_anotar_marcar_editar_e_apagar(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $eu = $this->actingAs($a->admin);

        $eu->post('/anotacoes', ['texto' => ''])->assertSessionHasErrors('texto');
        $eu->post('/anotacoes', ['texto' => 'Ligar para a contabilidade', 'para' => now()->toDateString()])->assertRedirect();
        $eu->post('/anotacoes', ['texto' => 'Cobrar o João'])->assertRedirect();
        $n = Anotacao::where('texto', 'Cobrar o João')->first();

        $eu->get('/')->assertOk()->assertInertia(fn ($p) => $p->has('anotacoes', 2)->where('anotacoes.0.texto', 'Ligar para a contabilidade'));
        $eu->post("/anotacoes/{$n->id}/alternar")->assertRedirect();
        $this->assertNotNull($n->fresh()->feita_em);
        $eu->post("/anotacoes/{$n->id}/alternar");
        $this->assertNull($n->fresh()->feita_em);
        $eu->put("/anotacoes/{$n->id}", ['texto' => 'Cobrar o João até sexta'])->assertRedirect();
        $this->assertSame('Cobrar o João até sexta', $n->fresh()->texto);

        // outro usuário não vê nem mexe
        $this->actingAs($a->operador)->get('/')->assertInertia(fn ($p) => $p->has('anotacoes', 0));
        $this->actingAs($a->operador)->delete("/anotacoes/{$n->id}")->assertNotFound();
        $this->actingAs($a->operador)->post("/anotacoes/{$n->id}/alternar")->assertNotFound();

        $this->actingAs($a->admin)->delete("/anotacoes/{$n->id}")->assertRedirect();
        $this->assertNull(Anotacao::find($n->id));
    }

    public function test_anotacao_de_outra_empresa_nao_aparece(): void
    {
        $a = $this->criarEmpresa('a');
        $b = $this->criarEmpresa('b');
        app(EmpresaAtual::class)->definir($b->empresa->id);
        $nb = Anotacao::create(['usuario_id' => $b->admin->id, 'texto' => 'da B']);
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->actingAs($a->admin)->delete("/anotacoes/{$nb->id}")->assertNotFound();
    }
}
