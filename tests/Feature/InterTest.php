<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Boleto;
use App\Models\Cobranca;
use App\Models\Integracao;
use App\Models\OrdemServico;
use App\Models\Pagamento;
use App\Servicos\Baixa;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Inter\Boletos;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Banco Inter: emissão do boleto com Pix, consulta, baixa automática e cancelamento (API simulada). */
class InterTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    private const BASE = 'https://cdpj.partners.bancointer.com.br';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 10:00');
        Cache::flush();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        $this->x->cliente->update(['documento' => '123.456.789-09', 'cep' => '11010000', 'logradouro' => 'Rua A', 'numero' => '10',
            'bairro' => 'Centro', 'cidade' => 'Santos', 'uf' => 'SP', 'celular' => '(13) 99999-0000']);
        Integracao::create(['tipo' => 'inter', 'ativo' => true, 'credenciais' => ['client_id' => 'id', 'client_secret' => 'segredo',
            'certificado' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----", 'chave' => "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----"]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cobranca(float $valor = 200): Cobranca
    {
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => $valor, 'descricao' => 'Mensalidade',
            'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);

        return Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
    }

    /** Inter simulado: situação da consulta muda conforme $estado */
    private function fakeInter(array &$estado): void
    {
        Http::fake(function (Request $r) use (&$estado) {
            $url = $r->url();
            if (str_ends_with($url, '/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/cobranca/v3/cobrancas')) {
                $estado['emitido'] = $r->data();

                return Http::response(['codigoSolicitacao' => 'abc-123']);
            }
            if (str_ends_with($url, '/cancelar')) {
                $estado['cancelado'] = $r->data()['motivoCancelamento'] ?? '';
                $estado['situacao'] = 'CANCELADO';

                return Http::response(null, 202);
            }
            if (str_contains($url, '/cobranca/v3/cobrancas/abc-123')) {
                return Http::response([
                    'cobranca' => array_filter(['codigoSolicitacao' => 'abc-123', 'situacao' => $estado['situacao'], 'valorNominal' => '200.00',
                        'valorTotalRecebido' => $estado['recebido'] ?? null, 'dataSituacao' => $estado['data'] ?? '2026-11-13',
                        'origemRecebimento' => $estado['origem'] ?? null]),
                    'boleto' => ['nossoNumero' => '123', 'linhaDigitavel' => str_repeat('1', 47), 'codigoBarras' => str_repeat('2', 44)],
                    'pix' => ['pixCopiaECola' => '000201PIX', 'txid' => 'TX123'],
                ]);
            }

            return Http::response(['title' => 'não esperado'], 500);
        });
    }

    public function test_emite_boleto_com_regras_e_mensagem(): void
    {
        $this->a->empresa->update(['configuracoes' => ['boleto_multa_tipo' => 'percentual', 'boleto_multa_valor' => 2, 'boleto_juros_tipo' => 'percentual_mes',
            'boleto_juros_valor' => 1, 'boleto_desconto_tipo' => 'valor', 'boleto_desconto_valor' => 10, 'boleto_baixa_dias' => 15, 'boleto_prazo_dias' => 5,
            'cobranca_instrucoes' => 'Pix: chave do estacionamento']]);
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $b = Boletos::emitir($this->cobranca());

        $e = $estado['emitido'];
        $this->assertSame(200.0, (float) $e['valorNominal']);
        $this->assertSame('2026-11-25', $e['dataVencimento']); // 5 dias depois do vencimento do cliente
        $this->assertSame(15, $e['numDiasAgenda']);
        $this->assertSame(['codigo' => 'PERCENTUAL', 'taxa' => 2.0], $e['multa']);
        $this->assertSame(['codigo' => 'TAXAMENSAL', 'taxa' => 1.0], $e['mora']);
        $this->assertSame(['codigo' => 'VALORFIXODATAINFORMADA', 'valor' => 10.0, 'quantidadeDias' => 5], $e['desconto']);
        $this->assertSame('12345678909', $e['pagador']['cpfCnpj']);
        $this->assertSame('FISICA', $e['pagador']['tipoPessoa']);
        $this->assertSame('11010000', $e['pagador']['cep']);
        $this->assertSame('13', $e['pagador']['ddd']);
        $this->assertStringStartsWith('Cobrança nº', $e['mensagem']['linha1']);
        $this->assertSame('Pix: chave do estacionamento', $e['mensagem']['linha2']);

        $this->assertSame('a_receber', $b->situacao);
        $this->assertSame('abc-123', $b->codigo_solicitacao);
        $this->assertSame(str_repeat('1', 47), $b->linha_digitavel);
        $this->assertSame('000201PIX', $b->pix_copia_cola);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/v2/token') && $r['scope'] === 'boleto-cobranca.read boleto-cobranca.write');
    }

    public function test_cadastro_incompleto_e_boleto_repetido_sao_barrados(): void
    {
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $cob = $this->cobranca();
        $this->x->cliente->update(['documento' => null, 'cep' => null]);
        try {
            Boletos::emitir($cob);
            $this->fail('Emitiu sem CPF.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('CPF ou CNPJ, CEP', $e->errors()['boleto'][0]);
        }
        $this->x->cliente->update(['documento' => '12345678909', 'cep' => '11010000']);
        Boletos::emitir($cob->refresh());
        $this->expectException(ValidationException::class);
        Boletos::emitir($cob->refresh());
    }

    public function test_boleto_pago_da_baixa_com_juros_uma_vez_so(): void
    {
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $cob = $this->cobranca();
        $b = Boletos::emitir($cob);

        // pago com atraso: 200 + 4,50 de multa e juros, pelo Pix do boleto
        Carbon::setTestNow('2026-11-23 09:00');
        $estado = ['situacao' => 'RECEBIDO', 'recebido' => '204.50', 'data' => '2026-11-22', 'origem' => 'PIX'];
        $this->fakeInter($estado);
        $r = Boletos::rotina(null, true);
        $this->assertSame([], $r[3]);
        Boletos::rotina(null, true); // de novo: não paga duas vezes

        $b->refresh();
        $this->assertSame('recebido', $b->situacao);
        $this->assertSame(1, Pagamento::where('origem', 'inter')->count());
        $p = Pagamento::find($b->pagamento_id);
        $this->assertSame(204.5, (float) $p->valor);
        $this->assertSame('pix', $p->forma->tipo ?? \App\Models\FormaPagamento::find($p->forma_pagamento_id)->tipo);
        $this->assertSame('2026-11-22', $p->recebido_em->toDateString());
        $this->assertSame('paga', $cob->refresh()->situacao);
        $enc = OrdemServico::where('descricao', 'Multa e juros do boleto')->first();
        $this->assertSame(4.5, (float) $enc->valor);
        $this->assertSame('paga', $enc->situacao);
        $this->assertSame(0, OrdemServico::abertas()->where('cliente_id', $this->x->cliente->id)->count());

        // resultado: linha própria de multa e juros nos dois olhares; nada sobra em "outras receitas"
        foreach (['caixa', 'referencia'] as $regime) {
            $r = \App\Servicos\Resultado::mes('2026-11', $regime);
            $linhas = collect($r['receitas'])->keyBy('id');
            $this->assertSame(4.5, $linhas['multa_juros']['valor'], $regime);
            $this->assertSame(204.5, $r['total_receitas'], $regime);
            $this->assertFalse(collect($r['receitas'])->contains(fn ($l) => $l['nome'] === 'Outras receitas'), $regime);
        }
    }

    public function test_desconto_do_boleto_vira_desconto(): void
    {
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $b = Boletos::emitir($this->cobranca());
        Auth::logout(); // a rotina roda sem usuário (cron)
        $estado = ['situacao' => 'RECEBIDO', 'recebido' => '190.00', 'origem' => 'BOLETO'];
        $this->fakeInter($estado);
        Boletos::rotina(null, true);

        $this->assertNotNull($b->refresh()->pagamento_id);
        $this->assertSame(10.0, (float) Ajuste::where('tipo', 'desconto')->value('valor'));
        $this->assertSame('boleto', \App\Models\FormaPagamento::find(Pagamento::find($b->pagamento_id)->forma_pagamento_id)->tipo);

        // caixa: mensalidade 200 e "(−) Descontos dados" 10, sem "outras receitas" negativas
        $r = \App\Servicos\Resultado::mes('2026-11', 'caixa');
        $linhas = collect($r['receitas'])->keyBy('id');
        $this->assertSame(-10.0, $linhas['descontos']['valor']);
        $this->assertSame(190.0, $r['total_receitas']);
        $this->assertFalse(collect($r['receitas'])->contains(fn ($l) => $l['nome'] === 'Outras receitas'));
    }

    public function test_cobranca_paga_no_balcao_cancela_o_boleto_e_pago_depois_vira_credito(): void
    {
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $cob = $this->cobranca();
        $b = Boletos::emitir($cob);

        // pagou em dinheiro no balcão
        $dinheiro = \App\Models\FormaPagamento::where('tipo', 'dinheiro')->first();
        Baixa::receber($this->x->cliente, ['ordens' => OrdemServico::where('cobranca_id', $cob->id)->pluck('id')->all(), 'valor' => 200, 'forma_pagamento_id' => $dinheiro->id]);
        [$cancelados] = Boletos::cancelarResolvidos();
        $this->assertSame(1, $cancelados);
        $this->assertSame('Cobrança paga por outro meio', $estado['cancelado']);
        $this->assertSame('cancelado', $b->refresh()->situacao);

        // outro boleto da mesma cobrança que o cliente pagou antes do cancelamento: vira crédito
        $b2 = Boleto::create(['cobranca_id' => $cob->id, 'cliente_id' => $this->x->cliente->id, 'seu_numero' => 'X-2', 'codigo_solicitacao' => 'abc-123',
            'valor' => 200, 'vencimento' => '2026-11-20', 'situacao' => 'a_receber']);
        $estado = ['situacao' => 'RECEBIDO', 'recebido' => '200.00'];
        $this->fakeInter($estado);
        Boletos::atualizar($b2);
        $this->assertSame(200.0, (float) OrdemServico::abertas()->where('tipo', 'credito')->sum('valor'));
        $this->assertStringContainsString('virou crédito', $b2->refresh()->erro);
    }

    public function test_erro_do_inter_marca_falha_e_mostra_o_motivo(): void
    {
        Http::fake([
            '*/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/cobranca/v3/cobrancas' => Http::response(['title' => 'Requisição inválida', 'violacoes' => [['propriedade' => 'pagador.cep', 'razao' => 'CEP inválido']]], 400),
        ]);
        try {
            Boletos::emitir($this->cobranca());
            $this->fail('Não deu erro.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pagador.cep: CEP inválido', $e->errors()['boleto'][0]);
        }
        $this->assertSame('falha', Boleto::first()->situacao);
    }

    public function test_sem_inter_ligado_rotina_nao_faz_nada(): void
    {
        Integracao::query()->update(['ativo' => false]);
        Http::fake();
        $this->assertSame([0, 0, 0, []], Boletos::rotina());
        Http::assertNothingSent();
    }

    public function test_telas_de_configuracao_e_regras(): void
    {
        $this->actingAs($this->a->admin)->get('/configuracoes/inter')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Configuracoes/Inter')->where('integracao.client_id', '••••id')->where('integracao.ativo', true)
                ->where('regras.boleto_baixa_dias', 30)->missing('integracao.client_secret'));
        $this->actingAs($this->a->operador)->get('/configuracoes/inter')->assertForbidden();

        $this->actingAs($this->a->admin)->put('/configuracoes/inter/regras', ['boleto_multa_tipo' => 'valor', 'boleto_multa_valor' => '5', 'boleto_juros_tipo' => 'valor_dia',
            'boleto_juros_valor' => '0.5', 'boleto_desconto_tipo' => 'nenhum', 'boleto_baixa_dias' => 10])->assertSessionHasNoErrors();
        $r = Boletos::regras($this->a->empresa->refresh());
        $this->assertSame('valor', $r['boleto_multa_tipo']);
        $this->assertSame(10, (int) $r['boleto_baixa_dias']);

        // credenciais novas: guarda criptografado e desliga até testar
        $crt = \Illuminate\Http\UploadedFile::fake()->createWithContent('inter.crt', "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n");
        $key = \Illuminate\Http\UploadedFile::fake()->createWithContent('inter.key', "-----BEGIN PRIVATE KEY-----\nBBB\n-----END PRIVATE KEY-----\n");
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/credenciais', ['ambiente' => 'sandbox', 'client_id' => 'novo-id-1234', 'certificado' => $crt, 'chave' => $key])
            ->assertSessionHasNoErrors();
        $i = Integracao::inter();
        $this->assertFalse($i->ativo);
        $this->assertSame('novo-id-1234', $i->credencial('client_id'));
        $this->assertSame('segredo', $i->credencial('client_secret')); // vazio manteve
        $this->assertStringNotContainsString('novo-id-1234', (string) \Illuminate\Support\Facades\DB::table('integracoes')->value('credenciais'));
        $ruim = \Illuminate\Http\UploadedFile::fake()->createWithContent('x.crt', 'qualquer coisa');
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/credenciais', ['ambiente' => 'producao', 'certificado' => $ruim])->assertSessionHasErrors('certificado');

        // testar: liga
        Http::fake(['*/oauth/v2/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), '*/cobrancas/sumario*' => Http::response([])]);
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/testar')->assertSessionHas('sucesso');
        $this->assertTrue(Integracao::inter()->ativo);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://cdpj-sandbox.partners.uatinter.co/'));
    }

    public function test_emitir_cobranca_com_boleto_e_tela_da_cobranca(): void
    {
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 150, 'descricao' => 'Mensalidade',
            'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        $this->actingAs($this->a->operador)->post('/cobrancas/gerar', ['mes' => '2026-11', 'clientes' => [$this->x->cliente->id], 'boleto' => true])
            ->assertSessionHas('sucesso', '1 cobrança(s) emitida(s). 1 boleto(s) gerado(s).');
        $cob = Cobranca::first();
        $this->assertSame(150.0, (float) $estado['emitido']['valorNominal']);
        $this->actingAs($this->a->operador)->get('/cobrancas/'.$cob->id)->assertOk()
            ->assertInertia(fn ($p) => $p->where('inter', true)->where('boletos.0.situacao', 'a_receber')->where('boletos.0.linha_digitavel', str_repeat('1', 47)));
        $this->actingAs($this->a->operador)->get('/cobrancas?mes=2026-11')->assertInertia(fn ($p) => $p->where('cobrancas.data.0.boleto', 'a_receber'));

        // operador não cancela boleto; quem altera contas cancela
        $b = Boleto::first();
        $this->actingAs($this->a->operador)->post('/boletos/'.$b->id.'/cancelar', ['motivo' => 'teste'])->assertForbidden();
        $this->actingAs($this->a->admin)->post('/boletos/'.$b->id.'/cancelar', ['motivo' => 'Cliente pediu'])->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $b->refresh()->situacao);
        $this->assertSame('Cliente pediu', $estado['cancelado']);

        // cancelar a cobrança cancela o boleto novo dela
        $estado['situacao'] = 'A_RECEBER';
        $this->actingAs($this->a->admin)->post('/cobrancas/'.$cob->id.'/boleto')->assertSessionHasNoErrors();
        $novo = Boleto::where('situacao', 'a_receber')->first();
        $this->assertSame($cob->numero.'-2', $novo->seu_numero);
        $this->actingAs($this->a->admin)->post('/cobrancas/'.$cob->id.'/cancelar', ['motivo' => 'errada'])->assertSessionHasNoErrors();
        $this->assertSame('cancelado', $novo->refresh()->situacao);
        $this->assertSame('Cobrança cancelada', $estado['cancelado']);
    }

    public function test_regras_particulares_do_cliente_substituem_as_da_empresa(): void
    {
        $this->a->empresa->update(['configuracoes' => ['boleto_multa_tipo' => 'percentual', 'boleto_multa_valor' => 2, 'boleto_juros_tipo' => 'percentual_mes', 'boleto_juros_valor' => 1, 'boleto_baixa_dias' => 30]]);
        $base = ['boleto_multa_tipo' => 'nenhuma', 'boleto_multa_valor' => 0, 'boleto_juros_tipo' => 'percentual_mes', 'boleto_juros_valor' => 1,
            'boleto_desconto_tipo' => 'percentual', 'boleto_desconto_valor' => 5, 'boleto_desconto_dias' => 0, 'boleto_baixa_dias' => 30];
        // regras específicas (a tela manda todos os grupos): sem multa, 5% de desconto, juros 1%
        $todos = ['particular' => ['multa' => true, 'juros' => true, 'desconto' => true, 'baixa' => true]];
        $this->actingAs($this->a->operador)->put('/clientes/'.$this->x->cliente->id.'/regras-boleto', $base + $todos)->assertForbidden();
        $this->actingAs($this->a->admin)->put('/clientes/'.$this->x->cliente->id.'/regras-boleto', $base + $todos)->assertSessionHasNoErrors();
        $this->assertCount(7, $this->x->cliente->refresh()->regras_boleto);

        // a empresa muda os juros depois: o cliente com regras específicas não muda
        $this->a->empresa->update(['configuracoes' => array_merge($this->a->empresa->configuracoes, ['boleto_juros_valor' => 2])]);
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        Boletos::emitir($this->cobranca());
        $e = $estado['emitido'];
        $this->assertArrayNotHasKey('multa', $e);
        $this->assertSame(['codigo' => 'VALORFIXODATAINFORMADA', 'valor' => 10.0, 'quantidadeDias' => 0], $e['desconto']); // 5% de 200
        $this->assertSame(['codigo' => 'TAXAMENSAL', 'taxa' => 1.0], $e['mora']);

        $this->actingAs($this->a->admin)->get('/clientes/'.$this->x->cliente->id)->assertInertia(fn ($p) => $p->where('cliente.regras_boleto.boleto_multa_tipo', 'nenhuma')->where('regras_boleto_empresa.boleto_multa_tipo', 'percentual'));
        // nada marcado: volta ao padrão
        $this->actingAs($this->a->admin)->put('/clientes/'.$this->x->cliente->id.'/regras-boleto', $base + ['particular' => []])->assertSessionHasNoErrors();
        $this->assertNull($this->x->cliente->refresh()->regras_boleto);
    }

    public function test_primeira_mensalidade_sai_sem_regras_e_da_para_escolher(): void
    {
        $this->a->empresa->update(['configuracoes' => ['boleto_multa_tipo' => 'percentual', 'boleto_multa_valor' => 2, 'boleto_desconto_tipo' => 'valor', 'boleto_desconto_valor' => 10, 'boleto_baixa_dias' => 20]]);
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 120, 'descricao' => 'Mensalidade proporcional 13/11 a 30/11',
            'data' => '2026-11-13', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        $cob = Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
        $this->assertFalse(Boletos::aplicarRegrasSugerido($cob));
        $this->actingAs($this->a->admin)->get('/cobrancas/'.$cob->id)->assertInertia(fn ($p) => $p->where('regras_sugerido', false));

        // automático: sem multa e sem desconto; a baixa automática continua
        $b = Boletos::emitir($cob);
        $this->assertArrayNotHasKey('multa', $estado['emitido']);
        $this->assertArrayNotHasKey('desconto', $estado['emitido']);
        $this->assertSame(20, $estado['emitido']['numDiasAgenda']);
        $this->assertTrue($b->regras['sem_regras']);

        // escolhendo aplicar as regras mesmo assim
        Boletos::cancelar($b, 'teste');
        $estado['situacao'] = 'A_RECEBER';
        $this->actingAs($this->a->admin)->post('/cobrancas/'.$cob->id.'/boleto', ['regras' => true])->assertSessionHasNoErrors();
        $this->assertSame(['codigo' => 'PERCENTUAL', 'taxa' => 2.0], $estado['emitido']['multa']);
    }

    public function test_desconto_de_pontualidade_so_nas_mensalidades_do_mes(): void
    {
        $this->a->empresa->update(['configuracoes' => ['boleto_desconto_tipo' => 'percentual', 'boleto_desconto_valor' => 10, 'boleto_prazo_dias' => 5]]);
        $estado = ['situacao' => 'A_RECEBER'];
        $this->fakeInter($estado);
        $cli = $this->x->cliente->id;
        // mensalidade atrasada (saldo anterior), lavagem e a mensalidade do mês
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $cli, 'valor' => 250, 'descricao' => 'Mensalidade', 'data' => '2026-10-01', 'vencimento' => '2026-10-05', 'competencia' => '2026-10']);
        Ordens::lancar(['tipo' => 'debito', 'cliente_id' => $cli, 'valor' => 50, 'descricao' => 'Lavagem', 'data' => '2026-11-10', 'vencimento' => '2026-11-10']);
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $cli, 'valor' => 250, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        $cob = Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
        $b = Boletos::emitir($cob, null, true);

        $e = $estado['emitido'];
        $this->assertSame(550.0, (float) $e['valorNominal']);
        $this->assertSame('2026-11-25', $e['dataVencimento']);
        // 10% só da mensalidade do mês (250), válido até o dia 20 = 5 dias antes do vencimento do boleto
        $this->assertSame(['codigo' => 'VALORFIXODATAINFORMADA', 'valor' => 25.0, 'quantidadeDias' => 5], $e['desconto']);
        $this->assertSame('2026-11-25', $b->vencimento->toDateString());

        // R$ fixo: uma vez por mensalidade do mês
        Boletos::cancelar($b, 'teste');
        $estado['situacao'] = 'A_RECEBER';
        $this->a->empresa->update(['configuracoes' => array_merge($this->a->empresa->configuracoes, ['boleto_desconto_tipo' => 'valor', 'boleto_desconto_valor' => 30])]);
        Boletos::emitir($cob->refresh(), null, true);
        $this->assertSame(30.0, $estado['emitido']['desconto']['valor']);

        // configuração geral: prazo do boleto
        $this->actingAs($this->a->admin)->put('/empresa/cobranca', ['cobranca_instrucoes' => 'Pix', 'boleto_prazo_dias' => 3])->assertSessionHasNoErrors();
        $this->assertSame(3, Boletos::prazo($this->a->empresa->refresh()));
    }
}
