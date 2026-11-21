<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\LogAtividade;
use App\Models\Usuario;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Etapa 8.1: aparelhos do PDV (pareamento por código, token), PIN de cada pessoa e a tela de aparelhos. */
class PdvBaseTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** cadastra o aparelho pela gestão e devolve o código mostrado na tela */
    private function novoAparelho(string $nome = 'Celular do balcão'): array
    {
        $this->actingAs($this->a->admin)->post('/configuracoes/aparelhos', ['nome' => $nome])->assertRedirect('/configuracoes/aparelhos');
        $codigo = session('pdv_codigo')['codigo'];
        app(EmpresaAtual::class)->definir($this->a->empresa->id);

        return [Aparelho::where('nome', $nome)->first(), $codigo];
    }

    private function parear(): string
    {
        [, $codigo] = $this->novoAparelho();

        return $this->postJson('/api/pdv/parear', ['codigo' => $codigo, 'versao' => '0.1.0'])->assertOk()->json('token');
    }

    private function comToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_parear_com_codigo_de_uso_unico(): void
    {
        $this->actingAs($this->a->operador)->get('/configuracoes/aparelhos')->assertForbidden();
        [$ap, $codigo] = $this->novoAparelho();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigo);
        $this->assertSame('aguardando', $ap->situacao());
        $this->assertNotSame($codigo, $ap->getRawOriginal('codigo_hash')); // só o hash fica no banco

        $this->postJson('/api/pdv/parear', ['codigo' => '000000'])->assertStatus(422);
        $r = $this->postJson('/api/pdv/parear', ['codigo' => $codigo])->assertOk();
        $token = $r->json('token');
        $this->assertSame(64, strlen($token));
        $this->assertSame('pareado', $ap->fresh()->situacao());
        // o código não vale de novo
        $this->postJson('/api/pdv/parear', ['codigo' => $codigo])->assertStatus(422);

        $this->comToken($token)->getJson('/api/pdv/eu')->assertOk()
            ->assertJsonPath('aparelho.nome', 'Celular do balcão')->assertJsonPath('empresa.nome', $this->a->empresa->nome)->assertJsonPath('pin_memoria_min', 0);
        $this->flushHeaders()->getJson('/api/pdv/eu')->assertStatus(401)->assertJsonPath('parear', true);
        $this->withHeader('Authorization', 'Bearer '.str_repeat('x', 64))->getJson('/api/pdv/eu')->assertStatus(401);
    }

    public function test_codigo_vence_em_15_minutos(): void
    {
        [, $codigo] = $this->novoAparelho();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->postJson('/api/pdv/parear', ['codigo' => $codigo])->assertStatus(422);
    }

    public function test_desativar_e_parear_de_novo_cortam_o_token(): void
    {
        $token = $this->parear();
        $ap = Aparelho::first();
        // "Parear de novo": novo código, o token antigo para de valer
        $this->actingAs($this->a->admin)->post("/configuracoes/aparelhos/{$ap->id}/codigo")->assertRedirect();
        $this->comToken($token)->getJson('/api/pdv/eu')->assertStatus(401);
        $novo = $this->postJson('/api/pdv/parear', ['codigo' => session('pdv_codigo')['codigo']])->json('token');
        $this->comToken($novo)->getJson('/api/pdv/eu')->assertOk();

        $this->actingAs($this->a->admin)->post("/configuracoes/aparelhos/{$ap->id}/desativar")->assertSessionHas('sucesso');
        $this->comToken($novo)->getJson('/api/pdv/eu')->assertStatus(401);
        $this->assertSame('desativado', $ap->fresh()->situacao());
    }

    public function test_cadastrar_pin_regras(): void
    {
        $token = $this->parear();
        $cad = fn ($login, $senha, $pin) => $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', compact('login', 'senha', 'pin'));

        $cad('a-operador', 'errada', '4831')->assertStatus(422)->assertJsonValidationErrors('login');
        foreach (['1234', '0000', '9876', '12a4', '123'] as $ruim) {
            $cad('a-operador', 'segredo1', $ruim)->assertStatus(422)->assertJsonValidationErrors('pin');
        }
        $cad('a-operador', 'segredo1', '4831')->assertOk();
        $op = Usuario::where('login', 'a-operador')->first();
        $this->assertNotNull($op->pin_hash);
        $this->assertNotSame('4831', $op->pin_hash);

        // outra pessoa escolhe o mesmo PIN: "indisponível" e o PIN do operador é cancelado (foi descoberto)
        $cad('a-admin', 'segredo1', '4831')->assertStatus(422)->assertJsonPath('errors.pin.0', 'PIN indisponível, escolha outro.');
        $this->assertNull($op->fresh()->pin_hash);
        $this->assertTrue(LogAtividade::where('acao', 'pdv_pin_cancelado')->exists());
        $cad('a-admin', 'segredo1', '4831')->assertOk(); // agora está livre

        // trocar o próprio PIN
        $cad('a-admin', 'segredo1', '5072')->assertOk();
        $this->assertSame(Pins::hash($this->a->empresa->id, '5072'), Usuario::where('login', 'a-admin')->value('pin_hash'));
    }

    public function test_cadastro_de_pin_trava_depois_de_5_senhas_erradas_como_o_login(): void
    {
        $token = $this->parear();
        $cad = fn ($senha) => $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', ['login' => 'a-operador', 'senha' => $senha, 'pin' => '4831']);
        foreach (range(1, 5) as $_) {
            $cad('errada')->assertStatus(422)->assertJsonPath('errors.login.0', 'Login ou senha incorretos.');
        }
        // a 6ª, mesmo com a senha certa, espera
        $cad('segredo1')->assertStatus(422)->assertJsonPath('errors.login.0', fn ($m) => str_starts_with($m, 'Muitas tentativas'));
        $this->assertNull(Usuario::where('login', 'a-operador')->value('pin_hash'));
        // outro login no mesmo aparelho não é afetado
        $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', ['login' => 'a-admin', 'senha' => 'segredo1', 'pin' => '5072'])->assertOk();
        // passado o minuto, entra
        $this->travel(61)->seconds();
        $cad('segredo1')->assertOk();
    }

    public function test_conferir_pin_e_trava_depois_de_5_erros(): void
    {
        $token = $this->parear();
        $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', ['login' => 'a-operador', 'senha' => 'segredo1', 'pin' => '4831'])->assertOk();

        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertOk()->assertJsonPath('usuario.nome', 'Operador a');
        for ($i = 1; $i <= 4; $i++) {
            $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '1111'])->assertStatus(422)->assertJsonPath('errors.pin.0', 'PIN incorreto.');
        }
        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '1111'])->assertStatus(422)->assertJsonPath('errors.pin.0', 'Muitos PINs errados. O aparelho ficou travado por 1 minuto.');
        // travado: nem o PIN certo passa
        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertStatus(422);
        $log = LogAtividade::where('acao', 'pdv_pin_travado')->first();
        $this->assertStringContainsString('PDV: Celular do balcão', $log->detalhe);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertOk();

        // usuário desativado: o PIN deixa de valer
        Usuario::where('login', 'a-operador')->update(['ativo' => false]);
        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertStatus(422);
    }

    public function test_gestao_apaga_pin_e_configura_memoria(): void
    {
        $token = $this->parear();
        $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', ['login' => 'a-operador', 'senha' => 'segredo1', 'pin' => '4831'])->assertOk();
        $op = Usuario::where('login', 'a-operador')->first();
        $this->actingAs($this->a->admin)->get('/usuarios')->assertInertia(fn ($p) => $p->where('usuarios', fn ($l) => collect($l)->firstWhere('login', 'a-operador')['pin'] === true));
        $this->actingAs($this->a->admin)->post("/usuarios/{$op->id}/apagar-pin")->assertSessionHas('sucesso');
        $this->assertNull($op->fresh()->pin_hash);

        $this->actingAs($this->a->admin)->put('/configuracoes/aparelhos/config', ['pin_memoria_min' => 7])->assertSessionHasErrors('pin_memoria_min');
        $this->actingAs($this->a->admin)->put('/configuracoes/aparelhos/config', ['pin_memoria_min' => 5])->assertSessionHas('sucesso');
        $this->comToken($token)->getJson('/api/pdv/eu')->assertJsonPath('pin_memoria_min', 5);
        $this->actingAs($this->a->admin)->get('/configuracoes/aparelhos')->assertOk()->assertInertia(fn ($p) => $p->has('aparelhos', 1)->where('pin_memoria_min', 5));
    }

    public function test_aparelho_de_outra_empresa_nao_ve_esta(): void
    {
        $b = $this->criarEmpresa('b');
        $token = $this->parear();
        // o token define a empresa: o PIN de alguém da empresa b não vale aqui
        app(EmpresaAtual::class)->definir($b->empresa->id);
        $ub = Usuario::where('login', 'b-operador')->first();
        $ub->forceFill(['pin_hash' => Pins::hash($b->empresa->id, '4831')])->save();
        $this->comToken($token)->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertStatus(422);
        $this->comToken($token)->postJson('/api/pdv/pin/cadastrar', ['login' => 'b-operador', 'senha' => 'segredo1', 'pin' => '5072'])->assertStatus(422);
    }

    public function test_tela_do_pdv_abre_sem_login(): void
    {
        $this->get('/pdv')->assertOk()->assertSee('id="pdv"', false);
        $this->get('/pdv/qualquer/coisa')->assertOk();
        $this->assertFileExists(public_path('pdv.webmanifest'));
    }

    public function test_pin_no_cadastro_do_usuario_pela_gestao(): void
    {
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        $nivel = Usuario::where('login', 'a-operador')->value('nivel_id');
        $base = ['nome' => 'Vigia', 'login' => 'vigia', 'senha' => 'segredo1', 'senha_confirmation' => 'segredo1', 'nivel_id' => $nivel];
        $this->actingAs($this->a->admin)->post('/usuarios', $base + ['pin' => '4831', 'pin_confirmation' => '4830'])->assertSessionHasErrors('pin');
        $this->assertFalse(Usuario::where('login', 'vigia')->exists());
        $this->actingAs($this->a->admin)->post('/usuarios', $base + ['pin' => '4831', 'pin_confirmation' => '4831'])->assertRedirect('/usuarios');
        $vigia = Usuario::where('login', 'vigia')->first();
        $this->assertSame(Pins::hash($this->a->empresa->id, '4831'), $vigia->pin_hash);

        // PIN fácil: usuário criado, PIN recusado e volta para a edição
        $r = $this->actingAs($this->a->admin)->post('/usuarios', ['login' => 'outro', 'nome' => 'Outro'] + $base + ['pin' => '1111', 'pin_confirmation' => '1111']);
        $outro = Usuario::where('login', 'outro')->first();
        $r->assertRedirect("/usuarios/{$outro->id}")->assertSessionHasErrors('pin');
        $this->assertNull($outro->pin_hash);
        // trocar na edição; mesmo PIN do vigia: indisponível e o do vigia é cancelado
        $edita = ['nome' => 'Outro', 'login' => 'outro', 'nivel_id' => $nivel, 'ativo' => true];
        $this->actingAs($this->a->admin)->put("/usuarios/{$outro->id}", $edita + ['pin' => '4831', 'pin_confirmation' => '4831'])->assertSessionHasErrors('pin');
        $this->assertNull($vigia->fresh()->pin_hash);
        $this->actingAs($this->a->admin)->put("/usuarios/{$outro->id}", $edita + ['pin' => '5072', 'pin_confirmation' => '5072'])->assertRedirect('/usuarios');
        // sem PIN: não mexe no que já existe
        $this->actingAs($this->a->admin)->put("/usuarios/{$outro->id}", $edita)->assertRedirect('/usuarios');
        $this->assertNotNull($outro->fresh()->pin_hash);
        $this->actingAs($this->a->admin)->get("/usuarios/{$outro->id}")->assertInertia(fn ($p) => $p->where('usuario.pin', true));
    }
}
