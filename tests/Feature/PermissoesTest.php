<?php

namespace Tests\Feature;

use App\Models\LogAtividade;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_operador_so_tem_o_dia_a_dia(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->get('/')->assertOk();
        foreach (['/usuarios', '/niveis', '/empresa', '/atividades', '/unidades/nova'] as $url) {
            $this->actingAs($a->operador)->get($url)->assertForbidden();
        }
    }

    public function test_acesso_negado_fica_registrado(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->get('/usuarios')->assertForbidden();
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $log = LogAtividade::where('acao', 'acesso_negado')->first();
        $this->assertNotNull($log);
        $this->assertSame($a->operador->id, $log->usuario_id);
        $this->assertStringContainsString('usuarios', $log->detalhe);
    }

    public function test_gerente_abre_o_que_o_nivel_permite(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->gerente)->get('/niveis')->assertOk();   // tem configuracoes
        $this->actingAs($a->gerente)->get('/usuarios')->assertForbidden(); // não tem usuarios
    }

    public function test_administrador_abre_tudo(): void
    {
        $a = $this->criarEmpresa('a');
        foreach (['/', '/usuarios', '/niveis', '/empresa', '/atividades', '/usuarios/novo', '/niveis/novo'] as $url) {
            $this->actingAs($a->admin)->get($url)->assertOk();
        }
    }

    public function test_mudanca_de_nivel_vale_na_hora(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->operador)->get('/empresa')->assertForbidden();
        $a->niveis->operador->forceFill(['permissoes' => ['configuracoes']])->save();
        $this->actingAs($a->operador->fresh())->get('/empresa')->assertOk();
    }

    public function test_telas_recebem_as_permissoes_do_usuario(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->gerente)->get('/')->assertInertia(fn ($p) => $p
            ->where('auth.acesso_total', false)
            ->where('auth.permissoes', ['configuracoes', 'desconto'])
            ->where('auth.usuario.nivel', 'Gerente'));
        $this->actingAs($a->admin)->get('/')->assertInertia(fn ($p) => $p->where('auth.acesso_total', true));
    }

    public function test_gate_usa_o_catalogo(): void
    {
        $a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertTrue($a->gerente->can('desconto'));
        $this->assertFalse($a->gerente->can('usuarios'));
        $this->assertTrue($a->admin->can('qualquer_coisa_do_catalogo'));
        $this->assertFalse($a->operador->can('desconto'));
    }
}
