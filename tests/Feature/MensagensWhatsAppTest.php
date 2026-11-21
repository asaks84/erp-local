<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Cobranca;
use App\Models\Integracao;
use App\Models\ModeloMensagem;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Ordens;
use App\Servicos\WhatsApp\Modelos;
use App\Servicos\WhatsApp\Regua;
use App\Servicos\WhatsApp\WhatsAppCliente;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MensagensWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    /** @var Request[] */
    private array $pedidos = [];

    private array $naMeta = [];

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

    private function cobranca(string $vencimento = '2026-11-20'): Cobranca
    {
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 250, 'descricao' => 'Mensalidade',
            'data' => '2026-11-01', 'vencimento' => $vencimento, 'competencia' => '2026-11']);

        return Cobrancas::emitir($this->x->cliente, '2026-11', $vencimento);
    }

    /** WhatsApp ligado, com a Meta simulada: $naMeta = modelos que existem lá */
    /** a Meta assina o corpo com a chave secreta do aplicativo */
    private function webhook(array $corpo, ?string $segredo = 'segredo-do-app-123456')
    {
        $json = json_encode($corpo);
        $h = $segredo ? ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $json, $segredo)] : [];

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $this->transformHeadersToServerVars($h + ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json']), $json);
    }

    private function ligar(array $naMeta = []): void
    {
        Integracao::create(['tipo' => 'whatsapp', 'ativo' => true, 'credenciais' => ['token' => str_repeat('t', 60), 'phone_number_id' => '111222333', 'waba_id' => '444555666', 'verify_token' => 'v', 'app_secret' => 'segredo-do-app-123456']]);
        $this->naMeta = $naMeta;
        Http::fake(function (Request $r) {
            $this->pedidos[] = $r;
            if (str_contains($r->url(), '/message_templates') && $r->method() === 'GET') {
                return Http::response(['data' => array_map(fn ($m) => $m + ['language' => 'pt_BR', 'category' => 'UTILITY', 'id' => 'id-'.$m['name']], $this->naMeta)]);
            }
            if (str_contains($r->url(), '/message_templates') && $r->method() === 'DELETE') {
                return str_contains($r->url(), 'name=recusar_meta')
                    ? Http::response(['error' => ['message' => 'Erro da Meta', 'code' => 100]], 400)
                    : Http::response(['success' => true]);
            }
            if (str_contains($r->url(), '/message_templates')) {
                return Http::response(['id' => '987', 'status' => 'PENDING', 'category' => 'UTILITY']);
            }

            return Http::response(['messages' => [['id' => 'wamid.'.count($this->pedidos)]]]);
        });
    }

    private function grupo(string $nome): int
    {
        return (int) ModeloMensagem::where('nome', $nome)->value('grupo');
    }

    private function ultimoEnvio(): Request
    {
        return collect($this->pedidos)->last(fn ($r) => str_ends_with($r->url(), '/messages'));
    }

    public function test_tela_cria_as_tres_padrao_e_rascunho_novo_com_validacao(): void
    {
        $this->actingAs($this->a->operador)->get('/configuracoes/whatsapp/mensagens')->assertForbidden();
        $this->actingAs($this->a->admin)->get('/configuracoes/whatsapp/mensagens')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Configuracoes/WhatsAppMensagens')->has('grupos', 3)->where('grupos.0.versoes.0.situacao', 'rascunho')->where('api', false));

        // rascunho pode ter problema; enviar para a Meta, não
        $this->actingAs($this->a->admin)->post('/configuracoes/whatsapp/mensagens', ['rotulo' => 'Aviso de reajuste', 'finalidade' => 'cobranca', 'texto' => '{nome}, sua mensalidade muda', 'botao' => false])
            ->assertSessionHasNoErrors();
        $m = ModeloMensagem::where('rotulo', 'Aviso de reajuste')->first();
        $this->assertSame(['aviso_de_reajuste', 'rascunho', 1, $m->id], [$m->nome, $m->situacao, $m->versao, $m->grupo]);
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$m->id}", ['rotulo' => 'Aviso de reajuste', 'finalidade' => 'cobranca', 'texto' => '{nome}, sua mensalidade muda', 'enviar' => true])
            ->assertSessionHasErrors('texto');

        $this->assertSame([], Modelos::problemas('Olá, {nome}! Sua cobrança de {valor} vence em {vencimento}.'));
        $this->assertNotEmpty(Modelos::problemas('Olá {nome}{valor} vence.'));             // duas seguidas
        $this->assertNotEmpty(Modelos::problemas('Olá, sua cobrança vence em {vencimento}'));  // termina com variável
        $this->assertNotEmpty(Modelos::problemas('Olá, {cpf}! Tudo bem por aí hoje?'));       // desconhecida
        $this->assertNotEmpty(Modelos::problemas('Olá, {nome}! Promoção de {valor} só hoje aqui.', 'propaganda'));
        $this->assertSame([], Modelos::problemas('Olá, {nome}! Lavagem completa com 20% de desconto nesta semana.', 'propaganda'));

        // descartar: a mensagem nova some; a da régua não
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$m->id}")->assertSessionHas('sucesso');
        $this->assertNull(ModeloMensagem::find($m->id));
        $lembrete = ModeloMensagem::where('nome', 'cobranca_lembrete')->first();
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$lembrete->id}")->assertSessionHas('erro');
    }

    public function test_enviar_para_a_meta_e_situacao_pelo_webhook(): void
    {
        $this->ligar([['name' => 'cobranca_lembrete', 'status' => 'APPROVED']]);
        $this->actingAs($this->a->admin)->get('/configuracoes/whatsapp/mensagens')->assertOk();
        $this->assertSame('APPROVED', ModeloMensagem::where('nome', 'cobranca_lembrete')->value('situacao'));
        $this->assertSame('rascunho', ModeloMensagem::where('nome', 'cobranca_vence_hoje')->value('situacao')); // não existe na Meta

        $m = ModeloMensagem::where('nome', 'cobranca_vence_hoje')->first();
        $texto = 'Olá, {nome}! Hoje vence sua cobrança de {valor}. Pagando até {desconto_ate}, fica {valor_com_desconto}. Toque no botão abaixo.';
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$m->id}", ['rotulo' => 'Vence hoje', 'finalidade' => 'cobranca', 'texto' => $texto, 'botao' => true, 'enviar' => true])
            ->assertSessionHas('sucesso');
        $r = collect($this->pedidos)->last(fn ($r) => $r->method() === 'POST');
        $this->assertSame('https://graph.facebook.com/v25.0/444555666/message_templates', $r->url());
        $this->assertSame(['cobranca_vence_hoje', 'UTILITY', 'pt_BR'], [$r['name'], $r['category'], $r['language']]);
        $this->assertSame('Olá, {{1}}! Hoje vence sua cobrança de {{2}}. Pagando até {{3}}, fica {{4}}. Toque no botão abaixo.', $r['components'][0]['text']);
        $this->assertSame(['Maria', 'R$ 250,00', '10/12/2026', 'R$ 230,00'], $r['components'][0]['example']['body_text'][0]);
        $this->assertSame(url('/c/{{1}}'), $r['components'][1]['buttons'][0]['url']);
        $this->assertSame(['PENDING', '987'], [$m->refresh()->situacao, $m->meta_id]);

        // travada: não edita mais
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$m->id}", ['rotulo' => 'X', 'finalidade' => 'cobranca', 'texto' => 'Olá, tudo certo por aqui.'])->assertSessionHas('erro');

        // a Meta avisa pelo webhook: recusada, com o motivo
        $corpo = fn ($ev, $motivo = null) => ['entry' => [['id' => '444555666', 'changes' => [['field' => 'message_template_status_update',
            'value' => ['event' => $ev, 'message_template_name' => 'cobranca_vence_hoje', 'message_template_language' => 'pt_BR', 'reason' => $motivo]]]]]];
        $this->webhook($corpo('REJECTED', 'PROMOTIONAL'))->assertOk();
        $this->assertSame('REJECTED', $m->refresh()->situacao);
        $this->assertStringContainsString('promocional', $m->motivo);
        $this->webhook($corpo('APPROVED'))->assertOk();
        $this->assertSame(['APPROVED', null], [$m->refresh()->situacao, $m->motivo]);
    }

    public function test_nova_versao_vale_no_manual_e_a_aprovada_continua_na_api(): void
    {
        $this->ligar([['name' => 'cobranca_lembrete', 'status' => 'APPROVED']]);
        $c = $this->cobranca(); // hoje é 3 dias antes: passo "lembrete"
        Modelos::sincronizar(new WhatsAppCliente(Integracao::whatsapp()));
        $g = $this->grupo('cobranca_lembrete');

        // editar a aprovada cria a versão 2 (rascunho)
        $this->actingAs($this->a->admin)->post("/configuracoes/whatsapp/mensagens/grupo/{$g}/versao")->assertRedirect("/configuracoes/whatsapp/mensagens?grupo={$g}");
        $v2 = Modelos::ultima($g);
        $this->assertSame([2, 'cobranca_lembrete_v2', 'rascunho'], [$v2->versao, $v2->nome, $v2->situacao]);
        $this->actingAs($this->a->admin)->post("/configuracoes/whatsapp/mensagens/grupo/{$g}/versao"); // de novo: abre o mesmo rascunho
        $this->assertSame(2, ModeloMensagem::where('grupo', $g)->count());
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$v2->id}", ['rotulo' => 'Lembrete', 'finalidade' => 'cobranca', 'botao' => true,
            'texto' => 'Oi, {nome}! Sua mensalidade do veículo {placa} vence em {vencimento}: {valor}. Toque no botão abaixo.'])->assertSessionHas('sucesso');

        // envio manual: o rascunho já vale
        Regua::gerar();
        $a = Aviso::first();
        $this->assertStringStartsWith('Oi, Maria! Sua mensalidade do veículo', $a->texto);
        $this->assertStringContainsString('Acesse: '.url('/c/'.$c->refresh()->token), $a->texto);
        $this->actingAs($this->a->admin)->get('/avisos')->assertInertia(fn ($p) => $p->where('pendentes.0.rotulo', 'Lembrete'));

        // API: sai a versão 1 (aprovada), com as informações dela
        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/enviar")->assertSessionHas('sucesso');
        $r = $this->ultimoEnvio();
        $this->assertSame('cobranca_lembrete', $r['template']['name']);
        $this->assertSame(['Maria', $this->a->empresa->nome, 'R$ 250,00', '20/11/2026'], array_column($r['template']['components'][0]['parameters'], 'text'));

        // a versão 2 vai para a Meta e é aprovada: passa a sair ela, na ordem das informações dela
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$v2->id}", ['rotulo' => 'Lembrete', 'finalidade' => 'cobranca', 'botao' => true, 'enviar' => true,
            'texto' => $v2->refresh()->texto])->assertSessionHas('sucesso');
        Modelos::atualizarSituacao('cobranca_lembrete_v2', 'APPROVED');
        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/enviar")->assertSessionHas('sucesso'); // reenvio
        $r = $this->ultimoEnvio();
        $this->assertSame('cobranca_lembrete_v2', $r['template']['name']);
        $placa = $this->x->veiculo->placa;
        $this->assertSame(['Maria', $placa, '20/11/2026', 'R$ 250,00'], array_column($r['template']['components'][0]['parameters'], 'text'));
    }

    public function test_passo_da_regua_com_mensagem_escolhida_e_sem_aprovacao_espera(): void
    {
        $this->ligar([]);
        $this->a->empresa->update(['configuracoes' => ['whatsapp_automatico' => true, 'whatsapp_hora' => '09:00']]);
        $this->actingAs($this->a->admin)->post('/configuracoes/whatsapp/mensagens', ['rotulo' => 'Lembrete curto', 'finalidade' => 'cobranca', 'botao' => true,
            'texto' => 'Olá, {nome}! Lembrete: {valor} vence em {vencimento}. Qualquer dúvida, é só responder.']);
        $g = ModeloMensagem::where('rotulo', 'Lembrete curto')->value('grupo');
        $this->actingAs($this->a->admin)->put('/configuracoes/whatsapp/regua', ['passos' => [['dias' => -3, 'ativo' => true, 'grupo' => $g], ['dias' => 0, 'ativo' => true]], 'hora' => '09:00', 'automatico' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame($g, Modelos::doPasso(-3));

        $this->cobranca();
        // a mensagem escolhida ainda não foi aprovada: o aviso é criado com o texto dela, mas a rotina não envia
        $this->assertSame([1, 0, 0], Regua::rotina());
        $a = Aviso::first();
        $this->assertSame('pendente', $a->situacao);
        $this->assertStringStartsWith('Olá, Maria! Lembrete: R$ 250,00 vence em 20/11/2026.', $a->texto);
        $this->assertStringEndsWith('Ver cobrança: '.url('/c/'.Cobranca::first()->token), $a->texto);
        // envio na mão pela API explica o motivo
        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/enviar")->assertSessionHas('erro');
        $this->assertStringContainsString('ainda não foi enviada para aprovação', $a->refresh()->erro);

        // propaganda não entra na régua
        $this->actingAs($this->a->admin)->post('/configuracoes/whatsapp/mensagens', ['rotulo' => 'Promo lavagem', 'finalidade' => 'propaganda', 'texto' => 'Olá, {nome}! Lavagem com desconto nesta semana.']);
        $p = ModeloMensagem::where('rotulo', 'Promo lavagem')->first();
        $this->assertSame(['MARKETING', false], [$p->categoria, $p->botao]);
        $this->actingAs($this->a->admin)->put('/configuracoes/whatsapp/regua', ['passos' => [['dias' => -3, 'ativo' => true, 'grupo' => $p->grupo]], 'hora' => '09:00'])
            ->assertSessionHasErrors('passos.0.grupo');
    }

    public function test_cabecalho_e_rodape_na_meta_no_envio_e_no_manual(): void
    {
        $this->ligar([]);
        $this->actingAs($this->a->admin)->get('/configuracoes/whatsapp/mensagens');
        $m = ModeloMensagem::where('nome', 'cobranca_lembrete')->first();
        $base = ['rotulo' => 'Lembrete', 'finalidade' => 'cobranca', 'botao' => true, 'texto' => $m->texto];

        // o que a Meta recusa no cabeçalho e no rodapé
        $this->assertNotEmpty(Modelos::problemasCabecalho('Oi {nome}, {valor}'));
        $this->assertNotEmpty(Modelos::problemasCabecalho('*Lembrete*'));
        $this->assertNotEmpty(Modelos::problemasCabecalho('Lembrete 🚗'));
        $this->assertNotEmpty(Modelos::problemasRodape('Até {vencimento}'));
        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$m->id}", $base + ['cabecalho' => 'Oi {nome}, {valor}', 'enviar' => true])
            ->assertSessionHasErrors('cabecalho');

        $this->actingAs($this->a->admin)->put("/configuracoes/whatsapp/mensagens/{$m->id}", $base + ['cabecalho' => 'Lembrete para {nome}', 'rodape' => 'Local Estacionamento · Santos', 'enviar' => true])
            ->assertSessionHas('sucesso');
        $r = collect($this->pedidos)->last(fn ($r) => $r->method() === 'POST');
        $c = $r['components'];
        $this->assertSame(['HEADER', 'BODY', 'FOOTER', 'BUTTONS'], array_column($c, 'type'));
        $this->assertSame(['TEXT', 'Lembrete para {{1}}', ['Maria']], [$c[0]['format'], $c[0]['text'], $c[0]['example']['header_text']]);
        $this->assertStringStartsWith('Olá, {{1}}! Aqui é do {{2}}.', $c[1]['text']); // o corpo numera de novo a partir do 1
        $this->assertSame('Local Estacionamento · Santos', $c[2]['text']);

        // envio: o cabeçalho leva o valor dele; no manual vira negrito no começo e o rodapé vai no fim
        Modelos::atualizarSituacao('cobranca_lembrete', 'APPROVED');
        $this->cobranca();
        Regua::gerar();
        $a = Aviso::first();
        $this->assertStringStartsWith("*Lembrete para Maria*\n\nOlá, Maria!", $a->texto);
        $this->assertStringEndsWith("\n\nLocal Estacionamento · Santos", $a->texto);
        $this->actingAs($this->a->admin)->post("/avisos/{$a->id}/enviar")->assertSessionHas('sucesso');
        $env = $this->ultimoEnvio()['template']['components'];
        $this->assertSame(['header', 'body', 'button'], array_column($env, 'type'));
        $this->assertSame('Maria', $env[0]['parameters'][0]['text']);

        // a versão seguinte leva cabeçalho e rodapé junto
        $this->actingAs($this->a->admin)->post("/configuracoes/whatsapp/mensagens/grupo/{$m->grupo}/versao");
        $this->assertSame(['Lembrete para {nome}', 'Local Estacionamento · Santos'], [Modelos::ultima($m->grupo)->cabecalho, Modelos::ultima($m->grupo)->rodape]);
    }

    public function test_excluir_versao_enviada_na_meta_e_aqui_sem_deixar_a_regua_sem_aprovada(): void
    {
        $this->ligar([['name' => 'cobranca_lembrete', 'status' => 'APPROVED'], ['name' => 'recusar_meta', 'status' => 'PENDING']]);
        Modelos::sincronizar(new WhatsAppCliente(Integracao::whatsapp()));
        $g = $this->grupo('cobranca_lembrete');
        $v1 = Modelos::ultima($g);
        $v2 = ModeloMensagem::create(['grupo' => $g, 'versao' => 2, 'rotulo' => $v1->rotulo, 'nome' => 'cobranca_lembrete_v2', 'texto' => $v1->texto, 'situacao' => 'PENDING', 'meta_id' => '555']);

        // a v1 é a única aprovada e a mensagem está na régua: não exclui
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$v1->id}")->assertSessionHas('erro');
        $this->assertNotNull($v1->fresh());

        // a v2 (em análise) sai da Meta e daqui
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$v2->id}")->assertSessionHas('sucesso');
        $this->assertNull($v2->fresh());
        $del = collect($this->pedidos)->last(fn ($r) => $r->method() === 'DELETE');
        $this->assertStringContainsString('name=cobranca_lembrete_v2', $del->url());
        $this->assertStringContainsString('hsm_id=555', $del->url());

        // mensagem fora da régua: exclui mesmo sendo a única; se a Meta recusa (e ainda tem lá), fica
        $outra = ModeloMensagem::create(['rotulo' => 'Teste', 'nome' => 'recusar_meta', 'texto' => 'Olá, {nome}!', 'situacao' => 'PENDING']);
        $outra->update(['grupo' => $outra->id]);
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$outra->id}")->assertSessionHas('erro');
        $this->assertNotNull($outra->fresh());
        $this->naMeta = [['name' => 'cobranca_lembrete', 'status' => 'APPROVED']]; // já apagada lá: conta como excluída
        $this->actingAs($this->a->admin)->delete("/configuracoes/whatsapp/mensagens/{$outra->id}")->assertSessionHas('sucesso');
        $this->assertNull($outra->fresh());
    }
}
