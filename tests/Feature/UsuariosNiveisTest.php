<?php

namespace Tests\Feature;

use App\Models\Nivel;
use App\Models\Usuario;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuariosNiveisTest extends TestCase
{
    use RefreshDatabase;

    private function dadosUsuario(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Fulano de Tal', 'login' => 'fulano', 'senha' => 'segredo1', 'senha_confirmation' => 'segredo1',
            'nivel_id' => null, 'unidade_id' => null, 'operador_caixa' => false, 'ativo' => true,
        ], $extra);
    }

    public function test_cria_usuario_que_consegue_entrar(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/usuarios', $this->dadosUsuario(['nivel_id' => $a->niveis->operador->id]))
            ->assertRedirect('/usuarios');
        auth()->logout();
        $this->post('/login', ['login' => 'fulano', 'password' => 'segredo1'])->assertRedirect('/');
    }

    public function test_login_unico_e_sem_espacos(): void
    {
        $a = $this->criarEmpresa('a');
        $this->criarEmpresa('b');
        $nivel = $a->niveis->operador->id;
        $this->actingAs($a->admin)->post('/usuarios', $this->dadosUsuario(['login' => 'b-admin', 'nivel_id' => $nivel]))->assertSessionHasErrors('login');
        $this->actingAs($a->admin)->post('/usuarios', $this->dadosUsuario(['login' => 'com espaco', 'nivel_id' => $nivel]))->assertSessionHasErrors('login');
    }

    public function test_nao_muda_o_proprio_nivel_nem_se_desativa(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->put("/usuarios/{$a->admin->id}", [
            'nome' => 'Admin', 'login' => 'a-admin', 'nivel_id' => $a->niveis->operador->id, 'ativo' => false,
        ])->assertRedirect('/usuarios');
        $admin = $a->admin->fresh();
        $this->assertSame($a->niveis->admin->id, $admin->nivel_id);
        $this->assertTrue($admin->ativo);
    }

    public function test_sempre_sobra_um_usuario_com_acesso_total(): void
    {
        $a = $this->criarEmpresa('a');
        // gerente com permissão de usuários tenta rebaixar o único administrador
        $a->niveis->gerente->forceFill(['permissoes' => ['usuarios']])->save();
        $this->actingAs($a->gerente->fresh())->put("/usuarios/{$a->admin->id}", [
            'nome' => 'Admin', 'login' => 'a-admin', 'nivel_id' => $a->niveis->operador->id, 'ativo' => true,
        ])->assertSessionHasErrors('nivel_id');
        $this->assertSame($a->niveis->admin->id, $a->admin->fresh()->nivel_id);
    }

    public function test_senha_vazia_nao_muda_a_senha(): void
    {
        $a = $this->criarEmpresa('a');
        $antes = $a->operador->senha;
        $this->actingAs($a->admin)->put("/usuarios/{$a->operador->id}", [
            'nome' => 'Operador novo nome', 'login' => 'a-operador', 'senha' => '', 'senha_confirmation' => '',
            'nivel_id' => $a->niveis->operador->id, 'ativo' => true,
        ])->assertRedirect('/usuarios');
        $this->assertSame($antes, $a->operador->fresh()->senha);
        $this->assertSame('Operador novo nome', $a->operador->fresh()->nome);
    }

    public function test_nivel_so_aceita_permissoes_do_catalogo(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/niveis', ['nome' => 'Caixa', 'permissoes' => ['desconto', 'inventada']])
            ->assertSessionHasErrors('permissoes.1');
        $this->actingAs($a->admin)->post('/niveis', ['nome' => 'Caixa', 'permissoes' => ['desconto', 'desconto']])
            ->assertRedirect('/niveis');
        app(EmpresaAtual::class)->definir($a->empresa->id);
        $this->assertSame(['desconto'], Nivel::where('nome', 'Caixa')->first()->permissoes);
    }

    public function test_nivel_fixo_nao_e_limitado_nem_excluido(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->put("/niveis/{$a->niveis->admin->id}", ['nome' => 'Dono', 'permissoes' => []])->assertRedirect('/niveis');
        $nivel = $a->niveis->admin->fresh();
        $this->assertSame('Dono', $nivel->nome);
        $this->assertTrue($nivel->fixo);
        $this->actingAs($a->admin)->delete("/niveis/{$nivel->id}");
        $this->assertNotNull($nivel->fresh());
    }

    public function test_nivel_com_usuarios_nao_e_excluido(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->delete("/niveis/{$a->niveis->operador->id}")->assertSessionHas('erro');
        $this->assertNotNull($a->niveis->operador->fresh());

        $vazio = app(EmpresaAtual::class)->como($a->empresa->id, fn () => Nivel::create(['nome' => 'Vazio', 'permissoes' => []]));
        $this->actingAs($a->admin)->delete("/niveis/{$vazio->id}")->assertRedirect('/niveis');
        $this->assertNull(Nivel::withoutGlobalScopes()->find($vazio->id));
    }

    public function test_unidade_ativa_sempre_sobra_uma(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->put("/unidades/{$a->unidade->id}", ['nome' => 'Principal', 'ativa' => false])
            ->assertSessionHasErrors('ativa');
        $this->assertTrue($a->unidade->fresh()->ativa);
    }

    public function test_salva_dados_da_empresa(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->put('/empresa', ['nome' => 'Local Estacionamento', 'documento' => '11.222.333/0001-81', 'telefone' => '(13) 3222-1111', 'email' => 'x@y.com'])
            ->assertSessionHas('sucesso');
        $this->assertSame('Local Estacionamento', $a->empresa->fresh()->nome);
        $this->assertSame('11222333000181', $a->empresa->fresh()->documento);
        $this->assertSame('1332221111', $a->empresa->fresh()->telefone);
        $this->actingAs($a->admin)->put('/empresa', ['nome' => 'X', 'documento' => '529.982.247-25'])->assertSessionHasErrors('documento'); // CPF não
        $this->actingAs($a->admin)->put('/empresa', ['nome' => 'X', 'documento' => '11.222.333/0001-82'])->assertSessionHasErrors('documento');
        $this->actingAs($a->admin)->put('/empresa', ['nome' => ''])->assertSessionHasErrors(['nome' => 'Preencha o nome.']);
    }

    public function test_usuario_criado_com_empresa_de_quem_criou(): void
    {
        $a = $this->criarEmpresa('a');
        $this->actingAs($a->admin)->post('/usuarios', $this->dadosUsuario(['nivel_id' => $a->niveis->operador->id, 'empresa_id' => 999]));
        $this->assertSame($a->empresa->id, Usuario::withoutGlobalScopes()->where('login', 'fulano')->value('empresa_id'));
    }
}
