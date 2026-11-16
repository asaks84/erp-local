<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Cobranca;
use App\Models\Integracao;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\WhatsApp\Regua;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-17 10:00');
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a, 'MARIA DA SILVA');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        $this->x->cliente->update(['celular' => '13991234567']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cobranca(string $vencimento = '2026-11-20', float $valor = 250): Cobranca
    {
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => $valor, 'descricao' => 'Mensalidade',
            'data' => '2026-11-01', 'vencimento' => $vencimento, 'competencia' => '2026-11']);

        return Cobrancas::emitir($this->x->cliente, '2026-11', $vencimento);
    }

    private function ligar(): void
    {
        Integracao::create(['tipo' => 'whatsapp', 'ativo' => true, 'credenciais' => ['token' => str_repeat('t', 60), 'phone_number_id' => '111222333', 'waba_id' => '444555666', 'verify_token' => 'segredo-do-webhook']]);
    }

    public function test_regua_cria_um_aviso_por_passo_e_para_quando_paga(): void
    {
        $c = $this->cobranca(); // vence 20/11; hoje 17/11 = 3 dias antes
        $this->assertSame(1, Regua::gerar());
        $this->assertSame(0, Regua::gerar()); // não repete
        $a = Aviso::first();
        $this->assertSame([-3, '2026-11-17', '13991234567', 'pendente'], [$a->dias, $a->previsto_para->format('Y-m-d'), $a->celular, $a->situacao]);
        $this->assertStringContainsString('Olá, Maria! Aqui é do '.$this->a->empresa->nome.'. Sua cobrança de R$ 250,00 vence em 20/11/2026', $a->texto);
        $this->assertStringContainsString(url('/c/'.$c->refresh()->token), $a->texto);

        // no dia do vencimento sai o passo seguinte; 6 dias depois, só o passo "3 depois" já passou da tolerância
        Carbon::setTestNow('2026-11-20 10:00');
        $this->assertSame(1, Regua::gerar());
        $this->assertSame(0, Aviso::orderByDesc('id')->first()->dias);
        Carbon::setTestNow('2026-11-26 10:00');
        $this->assertSame(0, Regua::gerar());
        Carbon::setTestNow('2026-11-30 10:00'); // 10 dias depois
        $this->assertSame(1, Regua::gerar());
        $this->assertStringContainsString('venceu em 20/11/2026 e ainda está em aberto', Aviso::orderByDesc('id')->first()->texto);

        // cobrança cancelada: o aviso pendente é cancelado
        $c->update(['situacao' => 'cancelada']);
        $this->assertSame(3, Regua::cancelarResolvidos());
        $this->assertSame(0, Aviso::where('situacao', 'pendente')->count());
    }

    public function test_ligar_a_regua_tarde_nao_despeja_avisos_e_respeita_quem_nao_recebe(): void
    {
        $this->cobranca('2026-11-10'); // venceu há 7 dias: o passo "3 depois" já passou da tolerância
        $this->assertSame(0, Regua::gerar());
        $this->x->cliente->update(['nao_recebe_avisos' => true]);
        Carbon::setTestNow('2026-11-20 10:00'); // 10 dias depois, mas o cliente não recebe avisos
        $this->assertSame(0, Regua::gerar());
    }

    public function test_tela_de_avisos_e_envio_manual(): void
    {
        $this->cobranca();
        $this->actingAs($this->a->admin)->get('/avisos')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Avisos/Index')->has('pendentes', 1)->where('api', false)->where('pendentes.0.impedimento', null));
        $a = Aviso::first();
        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/manual")->assertRedirect();
        $this->assertSame(['enviado', 'manual'], [$a->refresh()->situacao, $a->canal]);
        $this->actingAs($this->a->admin)->get('/avisos')->assertInertia(fn ($p) => $p->has('pendentes', 0)->has('enviados', 1));

        // celular inválido aparece com o motivo
        $this->x->cliente->update(['celular' => '1332221111']);
        Carbon::setTestNow('2026-11-20 10:00');
        $this->actingAs($this->a->admin)->get('/avisos')->assertInertia(fn ($p) => $p->where('pendentes.0.impedimento', 'Celular: é telefone fixo.'));
    }

    public function test_envio_pela_api_usa_o_modelo_e_registra_a_falha(): void
    {
        $this->ligar();
        $c = $this->cobranca();
        Regua::gerar();
        $a = Aviso::first();
        $pedidos = [];
        $falhar = false;
        Http::fake(function (Request $r) use (&$pedidos, &$falhar) {
            $pedidos[] = $r;

            return $falhar ? Http::response(['error' => ['message' => 'Recipient phone number not in allowed list', 'code' => 131030]], 400)
                : Http::response(['messages' => [['id' => 'wamid.ABC']]]);
        });

        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/enviar")->assertSessionHas('sucesso');
        $r = $pedidos[0];
        $this->assertSame('https://graph.facebook.com/v25.0/111222333/messages', $r->url());
        $this->assertSame('Bearer '.str_repeat('t', 60), $r->header('Authorization')[0]);
        $this->assertSame('5513991234567', $r['to']);
        $this->assertSame(['cobranca_lembrete', 'pt_BR'], [$r['template']['name'], $r['template']['language']['code']]);
        $this->assertSame(['Maria', $this->a->empresa->nome, 'R$ 250,00', '20/11/2026'], array_column($r['template']['components'][0]['parameters'], 'text'));
        $this->assertSame($c->refresh()->token, $r['template']['components'][1]['parameters'][0]['text']);
        $this->assertSame(['enviado', 'api', 'wamid.ABC'], [$a->refresh()->situacao, $a->canal, $a->mensagem_id]);

        // webhook da Meta: entregue e lido
        $corpo = fn ($status) => ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '111222333'], 'statuses' => [['id' => 'wamid.ABC', 'status' => $status]]]]]]]];
        $this->postJson('/webhooks/whatsapp', $corpo('delivered'))->assertOk();
        $this->assertSame('entregue', $a->refresh()->situacao);
        $this->postJson('/webhooks/whatsapp', $corpo('read'))->assertOk();
        $this->postJson('/webhooks/whatsapp', $corpo('delivered'))->assertOk(); // fora de ordem: não volta
        $this->assertSame('lido', $a->refresh()->situacao);

        // falha da Meta fica no aviso, com a dica
        Carbon::setTestNow('2026-11-20 10:00');
        Regua::gerar();
        $b = Aviso::orderByDesc('id')->first();
        $falhar = true;
        $this->actingAs($this->a->admin)->post("/avisos/{$b->id}/enviar")->assertSessionHas('erro');
        $this->assertSame('falha', $b->refresh()->situacao);
        $this->assertStringContainsString('lista de números permitidos', $b->erro);
    }

    public function test_rotina_automatica_so_envia_depois_do_horario(): void
    {
        $this->ligar();
        $this->a->empresa->update(['configuracoes' => ['whatsapp_automatico' => true, 'whatsapp_hora' => '11:00']]);
        $this->cobranca();
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $this->assertSame([1, 0, 0], Regua::rotina()); // 10h: cria, não envia
        Carbon::setTestNow('2026-11-17 11:05');
        $this->assertSame([0, 1, 0], Regua::rotina());
        $this->assertSame([0, 0, 0], Regua::rotina());
        Http::assertSentCount(1);
    }

    public function test_configuracao_credenciais_teste_e_webhook(): void
    {
        $this->actingAs($this->a->operador)->get('/configuracoes/whatsapp')->assertForbidden();
        $this->actingAs($this->a->admin)->post('/configuracoes/whatsapp/credenciais', ['phone_number_id' => '111222333', 'waba_id' => '444555666', 'token' => str_repeat('k', 80)])->assertSessionHasNoErrors();
        $i = Integracao::whatsapp();
        $this->assertFalse($i->ativo);
        $this->assertStringNotContainsString(str_repeat('k', 80), (string) \Illuminate\Support\Facades\DB::table('integracoes')->value('credenciais')); // criptografado
        $this->actingAs($this->a->admin)->get('/configuracoes/whatsapp')->assertInertia(fn ($p) => $p->where('integracao.tem_token', true)->missing('integracao.token')
            ->where('integracao.webhook_token', $i->credencial('verify_token'))->has('modelos', 3));

        Http::fake(['*/111222333*' => Http::response(['display_phone_number' => '+55 13 99999-0000', 'verified_name' => 'Local Estacionamento']),
            '*/message_templates*' => Http::response(['data' => [['name' => 'cobranca_lembrete', 'status' => 'APPROVED', 'language' => 'pt_BR']]])]);
        $this->actingAs($this->a->admin)->post('/configuracoes/whatsapp/testar')->assertSessionHas('sucesso');
        $this->assertTrue(Integracao::whatsapp()->ativo);
        $this->actingAs($this->a->admin)->get('/configuracoes/whatsapp')->assertInertia(fn ($p) => $p->where('modelos.0.situacao', 'APPROVED')->where('modelos.1.situacao', null));

        // a Meta confirma o endereço do webhook com o código
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token='.$i->credencial('verify_token').'&hub_challenge=12345')->assertOk()->assertSee('12345');
        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=errado&hub_challenge=12345')->assertForbidden();

        // régua
        $this->actingAs($this->a->admin)->put('/configuracoes/whatsapp/regua', ['passos' => [['dias' => -2, 'ativo' => true], ['dias' => 5, 'ativo' => false]], 'hora' => '09:30', 'automatico' => true])->assertSessionHasNoErrors();
        $this->assertSame([['dias' => -2, 'ativo' => true], ['dias' => 5, 'ativo' => false]], Regua::passos($this->a->empresa->refresh()));
        $this->actingAs($this->a->admin)->put('/configuracoes/whatsapp/regua', ['passos' => [['dias' => 1, 'ativo' => true]], 'hora' => '23:00'])->assertSessionHasErrors('hora');
    }

    public function test_pagina_publica_da_cobranca(): void
    {
        $c = $this->cobranca();
        $link = Regua::link($c);
        Auth::logout();
        $this->get($link)->assertOk()->assertInertia(fn ($p) => $p->component('Cobrancas/Publica')->where('cobranca.a_pagar', 250)
            ->where('cobranca.primeiro_nome', 'Maria')->where('boleto', null)->has('cobranca.itens', 1));
        $this->get('/c/nao-existe')->assertNotFound();
        $this->get('/privacidade')->assertOk();
    }
}
