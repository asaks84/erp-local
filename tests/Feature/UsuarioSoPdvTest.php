<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Usuario;
use App\Servicos\Pdv\Aparelhos;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Usuário só do PDV: sem login e sem senha; escolhe o PIN no balcão com o código de cadastro gerado na gestão. */
class UsuarioSoPdvTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        $codigo = Aparelhos::novoCodigo(Aparelho::create(['nome' => 'Balcão']));
        $this->token = $this->postJson('/api/pdv/parear', ['codigo' => $codigo])->assertOk()->json('token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pdv(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function criarSoPdv(): array
    {
        $this->actingAs($this->a->admin)->post('/usuarios', [
            'nome' => 'Zé do Balcão', 'acessa_gestao' => false, 'login' => 'ignorado', 'senha' => '', 'nivel_id' => $this->a->niveis->operador->id, 'ativo' => true,
        ])->assertRedirect('/usuarios')->assertSessionHas('codigo_pin');

        return [Usuario::where('nome', 'Zé do Balcão')->first(), session('codigo_pin')['codigo']];
    }

    public function test_so_pdv_nasce_sem_login_e_sem_senha_e_com_o_codigo_do_pin(): void
    {
        [$u, $codigo] = $this->criarSoPdv();
        $this->assertNull($u->login);
        $this->assertNull($u->senha);
        $this->assertFalse($u->acessa_gestao);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);
        $this->assertNotSame($codigo, $u->pin_codigo_hash); // guardado só como hash
        $this->actingAs($this->a->admin)->get('/usuarios')->assertInertia(fn ($p) => $p->where('usuarios', fn ($l) => collect($l)->firstWhere('nome', 'Zé do Balcão')['acessa_gestao'] === false));
    }

    public function test_escolhe_o_pin_no_pdv_com_o_codigo_uma_vez_so(): void
    {
        [$u, $codigo] = $this->criarSoPdv();
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => '000000', 'pin' => '4831'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        // PIN recusado (óbvio): o código continua valendo
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => $codigo, 'pin' => '1234'])->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => $codigo, 'pin' => '4831'])->assertOk()->assertJsonPath('mensagem', 'PIN de Zé do Balcão cadastrado.');
        $this->pdv()->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertOk()->assertJsonPath('usuario.id', $u->id);
        // o código não serve de novo
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => $codigo, 'pin' => '5927'])->assertStatus(422)->assertJsonValidationErrors('codigo');
        $this->assertNull($u->fresh()->pin_codigo_hash);
    }

    public function test_codigo_vence_em_24_horas(): void
    {
        [, $codigo] = $this->criarSoPdv();
        Carbon::setTestNow(now()->addHours(25));
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => $codigo, 'pin' => '4831'])->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_quem_tem_login_e_senha_continua_cadastrando_como_antes(): void
    {
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['login' => 'a-operador', 'senha' => 'segredo1', 'pin' => '4831'])->assertOk();
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['pin' => '5927'])->assertStatus(422)->assertJsonValidationErrors(['login', 'senha']);
    }

    public function test_virar_so_pdv_tira_a_senha_e_bloqueia_a_gestao(): void
    {
        $op = $this->a->operador;
        $this->actingAs($this->a->admin)->put("/usuarios/{$op->id}", [
            'nome' => $op->nome, 'acessa_gestao' => false, 'nivel_id' => $op->nivel_id, 'ativo' => true,
        ])->assertRedirect('/usuarios');
        $op->refresh();
        $this->assertNull($op->senha);
        $this->assertNull($op->login);
        auth()->logout();
        $this->post('/login', ['login' => 'a-operador', 'password' => 'segredo1'])->assertSessionHasErrors('login');

        // sessão aberta de quem virou só PDV: sai da gestão
        $this->actingAs($op->fresh())->get('/')->assertRedirect('/login');

        // voltar a acessar a gestão exige login e senha
        $this->actingAs($this->a->admin)->put("/usuarios/{$op->id}", [
            'nome' => $op->nome, 'acessa_gestao' => true, 'login' => 'a-operador', 'nivel_id' => $op->nivel_id, 'ativo' => true,
        ])->assertSessionHasErrors('senha');
        $this->actingAs($this->a->admin)->put("/usuarios/{$op->id}", [
            'nome' => $op->nome, 'acessa_gestao' => true, 'login' => 'a-operador', 'senha' => 'nova1234', 'senha_confirmation' => 'nova1234', 'nivel_id' => $op->nivel_id, 'ativo' => true,
        ])->assertRedirect('/usuarios');
        auth()->logout();
        $this->post('/login', ['login' => 'a-operador', 'password' => 'nova1234'])->assertRedirect('/');
    }

    public function test_ninguem_tira_o_proprio_acesso_e_sempre_sobra_um_administrador_na_gestao(): void
    {
        $adm = $this->a->admin;
        $this->actingAs($adm)->put("/usuarios/{$adm->id}", ['nome' => $adm->nome, 'acessa_gestao' => false, 'nivel_id' => $adm->nivel_id, 'ativo' => true])
            ->assertSessionHasErrors('acessa_gestao');
        $this->a->niveis->gerente->forceFill(['permissoes' => ['usuarios']])->save();
        $this->actingAs($this->a->gerente->fresh())->put("/usuarios/{$adm->id}", ['nome' => $adm->nome, 'acessa_gestao' => false, 'nivel_id' => $adm->nivel_id, 'ativo' => true])
            ->assertSessionHasErrors('nivel_id');
        $this->assertTrue($adm->fresh()->acessa_gestao);
    }

    public function test_gestao_gera_codigo_novo_para_quem_esqueceu(): void
    {
        [$u] = $this->criarSoPdv();
        $this->actingAs($this->a->admin)->post("/usuarios/{$u->id}/codigo-pin")->assertSessionHas('codigo_pin');
        $novo = session('codigo_pin')['codigo'];
        $this->pdv()->postJson('/api/pdv/pin/cadastrar', ['codigo' => $novo, 'pin' => '4831'])->assertOk();
    }
}
