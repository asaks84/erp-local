<?php

namespace Tests\Feature;

use App\Models\AtualizacaoCadastral;
use App\Models\Veiculo;
use App\Servicos\Atualizacoes;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Atualização cadastral: problemas no cadastro, link com confirmação pela placa, revisão e aprovação. */
class AtualizacaoCadastralTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        $this->x->cliente->update(['documento' => '11111111111', 'celular' => '1332221111']);
    }

    public function test_problemas_de_cpf_e_celular(): void
    {
        $this->assertNull(Atualizacoes::problemaDocumento('529.982.247-25'));
        $this->assertSame('Em branco', Atualizacoes::problemaDocumento(null));
        $this->assertSame('Dígitos não conferem', Atualizacoes::problemaDocumento('11111111111'));
        $this->assertSame('Dígitos não conferem', Atualizacoes::problemaDocumento('52998224726'));
        $this->assertSame('Quantidade de números errada', Atualizacoes::problemaDocumento('1234'));
        $this->assertSame('Repetido em outro cliente', Atualizacoes::problemaDocumento('52998224725', ['52998224725']));

        $this->assertNull(Atualizacoes::problemaCelular('(13) 99876-5432'));
        $this->assertNull(Atualizacoes::problemaCelular('5513998765432'));
        $this->assertSame('Sem celular', Atualizacoes::problemaCelular(''));
        $this->assertSame('É telefone fixo', Atualizacoes::problemaCelular('1332221111'));
        $this->assertSame('Sem o 9 na frente', Atualizacoes::problemaCelular('1388765432'));
        $this->assertSame('Formato inválido', Atualizacoes::problemaCelular('13999'));
        $this->assertSame('Formato inválido', Atualizacoes::problemaCelular('99999999999'));
        $this->assertSame('Número suspeito', Atualizacoes::problemaCelular('(13) 99999-9999'));
        $this->assertSame('Número suspeito', Atualizacoes::problemaCelular('13988888888'));
    }

    public function test_tela_mostra_os_problemas_e_gera_o_link(): void
    {
        $this->actingAs($this->a->admin)->get('/atualizacao-cadastral')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Atualizacao/Index')->where('resumo.documento', 1)->where('resumo.celular', 1)
                ->where('linhas.0.problema_documento', 'Dígitos não conferem')->where('linhas.0.atualizacao', null));
        $this->actingAs($this->a->operador)->get('/atualizacao-cadastral')->assertForbidden();

        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/gerar', ['clientes' => [$this->x->cliente->id]])->assertSessionHasNoErrors();
        $a = AtualizacaoCadastral::first();
        $this->assertSame(40, strlen($a->token));
        $this->assertSame(now()->addDays(30)->toDateString(), $a->expira_em->toDateString());
        // gerar de novo não troca o link aberto; renovar troca e cancela o anterior
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/gerar', ['clientes' => [$this->x->cliente->id]]);
        $this->assertSame(1, AtualizacaoCadastral::count());
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/gerar', ['clientes' => [$this->x->cliente->id], 'renovar' => true]);
        $this->assertSame('cancelada', $a->refresh()->situacao);
        $this->assertSame(2, AtualizacaoCadastral::count());

        $novo = AtualizacaoCadastral::where('situacao', 'aberta')->first();
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/enviado', ['ids' => [$novo->id], 'canal' => 'cartao']);
        $this->assertSame('cartao', $novo->refresh()->canal);
        $this->actingAs($this->a->admin)->get('/atualizacao-cadastral/cartoes?ids='.$novo->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Atualizacao/Cartoes')->has('cartoes', 1)->where('cartoes.0.link', url('/atualizar/'.$novo->token)));
        // sem ids: todos os links abertos e válidos, para escolher na tela
        $this->actingAs($this->a->admin)->get('/atualizacao-cadastral/cartoes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Atualizacao/Cartoes')->where('cartoes', fn ($c) => collect($c)->pluck('id')->contains($novo->id) && collect($c)->every(fn ($x) => isset($x['nome']))));
    }

    public function test_cliente_confirma_a_placa_atualiza_e_a_equipe_aprova(): void
    {
        $a = Atualizacoes::gerar($this->x->cliente);
        $placa = Veiculo::where('cliente_id', $this->x->cliente->id)->value('placa');
        $url = '/atualizar/'.$a->token;

        // sem confirmar a placa, não vê os dados
        $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Atualizacao/Publico')->where('estado', 'placa')->missing('dados'));
        $this->post($url, ['nome' => 'X'])->assertForbidden();
        $this->post($url.'/placa', ['placa' => 'ZZZ9Z99'])->assertSessionHasErrors('placa');
        $this->post($url.'/placa', ['placa' => strtolower($placa)])->assertRedirect($url);
        $this->get($url)->assertInertia(fn ($p) => $p->where('estado', 'formulario')->where('dados.documento', '')->where('dados.nome', $this->x->cliente->nome));

        $dados = ['nome' => 'Nome Corrigido', 'documento' => '529.982.247-25', 'celular' => '(13) 99876-5432', 'telefone' => '', 'email' => 'A@B.COM',
            'cep' => '11010-000', 'logradouro' => 'Rua Nova', 'numero' => '10', 'complemento' => '', 'bairro' => 'Centro', 'cidade' => 'Santos', 'uf' => 'sp', 'aceite' => true];
        $this->post($url, ['documento' => '111.111.111-11', 'celular' => '1332221111'] + $dados)->assertSessionHasErrors(['documento', 'celular']);
        $this->post($url, $dados)->assertRedirect($url)->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame('respondida', $a->situacao);
        $this->assertSame('11111111111', $this->x->cliente->refresh()->documento); // nada muda antes da aprovação
        $this->get($url)->assertInertia(fn ($p) => $p->where('estado', 'recebida'));

        $this->actingAs($this->a->admin)->get('/atualizacao-cadastral/'.$a->id)->assertOk()
            ->assertInertia(fn ($p) => $p->where('documento_valido', true)->where('linhas.0.campo', 'documento')->where('linhas.0.mudou', true));
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/'.$a->id.'/aprovar')->assertSessionHasNoErrors();
        $c = $this->x->cliente->refresh();
        $this->assertSame('52998224725', $c->documento);
        $this->assertSame('13998765432', $c->celular);
        $this->assertSame('NOME CORRIGIDO', $c->nome);
        $this->assertSame('a@b.com', $c->email);
        $this->assertContains('documento', $c->campos_locais);
        $this->assertContains('celular', $c->campos_locais);
        $this->assertNotContains('telefone', $c->campos_locais); // o que não mudou continua vindo do sistema atual
        $this->assertSame('aprovada', $a->refresh()->situacao);
    }

    public function test_placa_errada_bloqueia_e_link_vencido_fecha(): void
    {
        $a = Atualizacoes::gerar($this->x->cliente);
        $url = '/atualizar/'.$a->token;
        for ($i = 0; $i < 5; $i++) {
            $this->post($url.'/placa', ['placa' => 'ZZZ9Z99']);
        }
        $this->assertNotNull($a->refresh()->bloqueado_ate);
        $placa = Veiculo::where('cliente_id', $this->x->cliente->id)->value('placa');
        $this->post($url.'/placa', ['placa' => $placa])->assertSessionHasErrors('placa'); // bloqueado mesmo com a certa

        $a->update(['expira_em' => now()->subDay(), 'bloqueado_ate' => null]);
        $this->get($url)->assertInertia(fn ($p) => $p->where('estado', 'fechada'));
        $this->get('/atualizar/naoexiste')->assertNotFound();
    }

    public function test_recusar_nao_muda_nada_e_cpf_de_outro_cliente_nao_aprova(): void
    {
        $outro = $this->criarCliente($this->a, 'OUTRO', 'ZZZ1Z11');
        $outro->cliente->update(['documento' => '52998224725']);
        $a = Atualizacoes::gerar($this->x->cliente);
        Atualizacoes::responder($a, array_merge(Atualizacoes::retrato($this->x->cliente), ['documento' => '52998224725']));
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/'.$a->id.'/aprovar')->assertSessionHasErrors('documento');
        $this->actingAs($this->a->admin)->post('/atualizacao-cadastral/'.$a->id.'/recusar', ['motivo' => 'CPF de outra pessoa'])->assertSessionHasNoErrors();
        $this->assertSame('recusada', $a->refresh()->situacao);
        $this->assertSame('11111111111', $this->x->cliente->refresh()->documento);
    }
}
