<?php

namespace Tests\Feature;

use App\Models\LogAtividade;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_entra_com_login_e_senha(): void
    {
        $a = $this->criarEmpresa('a');
        $this->post('/login', ['login' => 'a-admin', 'password' => 'segredo1'])->assertRedirect('/');
        $this->assertAuthenticatedAs($a->admin);
        $this->assertNotNull($a->admin->fresh()->ultimo_acesso_em);
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertTrue(LogAtividade::where('acao', 'login')->exists());
    }

    public function test_login_nao_diferencia_maiusculas(): void
    {
        $this->criarEmpresa('a');
        $this->post('/login', ['login' => 'A-ADMIN', 'password' => 'segredo1'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_senha_errada_e_usuario_inexistente_tem_a_mesma_mensagem(): void
    {
        $this->criarEmpresa('a');
        $this->post('/login', ['login' => 'a-admin', 'password' => 'errada'])->assertSessionHasErrors(['login' => 'Login ou senha incorretos.']);
        $this->post('/login', ['login' => 'nao-existe', 'password' => 'errada'])->assertSessionHasErrors(['login' => 'Login ou senha incorretos.']);
        $this->assertGuest();
    }

    public function test_usuario_inativo_nao_entra_e_sessao_aberta_cai(): void
    {
        $a = $this->criarEmpresa('a');
        $a->operador->forceFill(['ativo' => false])->save();
        $this->post('/login', ['login' => 'a-operador', 'password' => 'segredo1'])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_senha_no_formato_antigo_entra_e_e_regravada(): void
    {
        $a = $this->criarEmpresa('a');
        $a->operador->forceFill(['senha' => sha1('antiga1')])->save();
        $this->post('/login', ['login' => 'a-operador', 'password' => 'antiga1'])->assertRedirect('/');
        $this->assertStringStartsWith('$2y$', $a->operador->fresh()->senha);
        $this->assertTrue(password_verify('antiga1', $a->operador->fresh()->senha));
    }

    public function test_limite_de_tentativas(): void
    {
        $this->criarEmpresa('a');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'a-admin', 'password' => 'errada']);
        }
        $this->post('/login', ['login' => 'a-admin', 'password' => 'segredo1'])
            ->assertSessionHasErrors(['login' => 'Muitas tentativas seguidas. Espere um minuto e tente de novo.']);
        $this->assertGuest();
    }

    public function test_sem_login_vai_para_a_tela_de_acesso(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/usuarios')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login'));
    }

    public function test_sair(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/sair')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_sair_por_inatividade_avisa_na_tela_de_acesso(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/sair', ['motivo' => 'inatividade'])->assertRedirect('/login?expirou=1');
        $this->get('/login?expirou=1')->assertInertia(fn ($p) => $p->where('aviso', fn ($v) => str_contains($v, 'falta de uso')));
    }

    public function test_ping_sem_sessao_responde_401(): void
    {
        $this->getJson('/sessao/ping')->assertUnauthorized();
    }
}
