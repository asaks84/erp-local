<?php

namespace Tests\Feature;

use App\Models\Boleto;
use App\Models\Despesa;
use App\Models\Estorno;
use App\Models\ExtratoLancamento;
use App\Models\Integracao;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Inter\ExtratoInter;
use App\Servicos\Ordens;
use App\Servicos\PlanoContas;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtratoInterTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private object $x;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:00');
        Cache::flush();
        $this->a = $this->criarEmpresa('a');
        $this->x = $this->criarCliente($this->a);
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        Auth::login($this->a->admin);
        FormasPagamento::garantirPadrao();
        PlanoContas::garantirPadrao();
        Integracao::create(['tipo' => 'inter', 'ativo' => true, 'credenciais' => ['client_id' => 'id', 'client_secret' => 'segredo',
            'certificado' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----", 'chave' => "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----"]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** transações como o extrato completo do Inter devolve */
    private function transacoes(): array
    {
        return [
            ['idTransacao' => 'T1', 'dataEntrada' => '2026-11-12', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '250.00', 'titulo' => 'Pix recebido',
                'descricao' => 'PIX RECEBIDO', 'detalhes' => ['nomePagador' => 'MARIA DA SILVA', 'cpfCnpjPagador' => '***.068.258-**', 'txId' => 'outro']],
            ['idTransacao' => 'T2', 'dataEntrada' => '2026-11-12', 'tipoTransacao' => 'BOLETO_COBRANCA', 'tipoOperacao' => 'C', 'valor' => '200.00', 'titulo' => 'Boleto recebido', 'detalhes' => []],
            ['idTransacao' => 'T3', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '180.00', 'titulo' => 'Pix recebido',
                'detalhes' => ['nomePagador' => 'JOAO', 'txId' => 'TXBOLETO']],
            ['idTransacao' => 'T4', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PAGAMENTO', 'tipoOperacao' => 'D', 'valor' => '89.90', 'titulo' => 'Pagamento de conta', 'detalhes' => ['nomeDestinatario' => 'CPFL']],
            ['idTransacao' => 'T5', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'D', 'valor' => '60.00', 'titulo' => 'Pix enviado', 'detalhes' => ['nomeRecebedor' => 'CLIENTE']],
            ['idTransacao' => 'T6', 'dataEntrada' => '2026-11-13', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'D', 'valor' => '15.00', 'titulo' => 'Pix enviado', 'detalhes' => ['nomeRecebedor' => 'PADARIA']],
        ];
    }

    private function preparar(): void
    {
        // boleto pago pelo Pix (txid conhecido), conta paga de 89,90 e estorno de 60
        Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $this->x->cliente->id, 'valor' => 180, 'descricao' => 'Mensalidade', 'data' => '2026-11-01', 'vencimento' => '2026-11-20', 'competencia' => '2026-11']);
        $cob = Cobrancas::emitir($this->x->cliente, '2026-11', '2026-11-20');
        Boleto::create(['cobranca_id' => $cob->id, 'cliente_id' => $this->x->cliente->id, 'seu_numero' => '1-1', 'codigo_solicitacao' => 'abc', 'txid' => 'TXBOLETO',
            'valor' => 180, 'vencimento' => '2026-11-20', 'situacao' => 'recebido']);
        Despesa::create(['conta_id' => PlanoContas::lancaveis()->first()['id'], 'descricao' => 'Energia', 'competencia' => '2026-11-01', 'vencimento' => '2026-11-12',
            'valor' => 89.90, 'situacao' => 'paga', 'pago_em' => '2026-11-12', 'valor_pago' => 89.90]);
        $outro = $this->criarCliente($this->a, 'Outro Cliente', 'BBB2B22')->cliente;
        Ordens::lancarNaConta($outro, 'credito', 60, 'Crédito', '2026-11-10', 'x');
        \App\Servicos\Estornos::estornar($outro, ['valor' => 60, 'estornado_em' => '2026-11-13', 'motivo' => 'Boleto pago depois do cancelamento']);
    }

    public function test_monta_o_lote_sem_boletos_e_concilia_as_saidas(): void
    {
        $this->preparar();
        $r = ExtratoInter::buscar('2026-11-07', '2026-11-14', $this->transacoes());

        $this->assertCount(1, $r['entradas']); // só o Pix da Maria; os dois do boleto ficam de fora
        $this->assertSame(2, $r['boletos']);
        $e = $r['entradas'][0];
        $this->assertSame(['T1', '2026-11-12', 250.0, 'MARIA DA SILVA', '068258'], [$e['id'], $e['data'], $e['valor'], $e['nome'], $e['documento']]);

        $this->assertSame(3, $r['saidas']);
        $s = collect($r['lista_saidas'])->keyBy('id');
        $this->assertSame('despesa', $s['T4']['registro']['tipo']);
        $this->assertSame('estorno', $s['T5']['registro']['tipo']);
        $this->assertNull($s['T6']['registro']);
    }

    public function test_busca_pela_api_e_abre_a_conferencia(): void
    {
        $this->preparar();
        $pedidos = [];
        Http::fake(function (Request $req) use (&$pedidos) {
            $pedidos[] = $req;
            $url = $req->url();
            if (str_ends_with($url, '/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok-'.$req['scope'], 'expires_in' => 3600]);
            }
            if (str_contains($url, '/banking/v2/extrato/completo')) {
                return Http::response(['transacoes' => $this->transacoes(), 'totalPaginas' => 1, 'ultimaPagina' => true]);
            }
            if (str_contains($url, '/banking/v2/saldo')) {
                return Http::response(['disponivel' => 1234.56]);
            }

            return Http::response([], 500);
        });

        $r = $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-11-07', 'ate' => '2026-11-14'])->assertRedirect();
        $this->actingAs($this->a->admin)->get($r->headers->get('Location'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Extrato/Conferir')->has('entradas', 1)->where('boletos', 2)->has('lista_saidas', 3)
                ->where('entradas.0.nome', 'MARIA DA SILVA'));
        // o token do extrato é pedido com a permissão de extrato, separado do boleto
        $this->assertTrue(collect($pedidos)->contains(fn ($q) => str_ends_with($q->url(), '/oauth/v2/token') && $q['scope'] === 'extrato.read'));
        $this->assertTrue(collect($pedidos)->contains(fn ($q) => str_contains($q->url(), '/extrato/completo') && $q->header('Authorization')[0] === 'Bearer tok-extrato.read'));
        $this->assertSame(1234.56, ExtratoInter::saldo());

        // futuro ou período grande: recusa
        $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-11-07', 'ate' => '2026-11-20'])->assertSessionHasErrors('ate');
        $this->actingAs($this->a->admin)->post('/extrato/inter', ['de' => '2026-07-01', 'ate' => '2026-11-14'])->assertSessionHasErrors('de');
    }

    private function pagamentoDoSistemaAtual(string $quando): int
    {
        return \Illuminate\Support\Facades\DB::table('pagamentos')->insertGetId([
            'empresa_id' => $this->a->empresa->id, 'unidade_id' => $this->a->unidade->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'cliente_id' => $this->x->cliente->id, 'forma_pagamento_id' => FormasPagamento::naoInformada()->id, 'parcelas' => 1,
            'valor' => 250, 'taxa' => 0, 'liquido' => 250, 'previsto_para' => substr($quando, 0, 10), 'recebido_em' => $quando,
            'situacao' => 'confirmado', 'origem' => 'importacao', 'legado_chave' => 'c|'.$quando, 'criado_em' => now(), 'atualizado_em' => now(),
        ]);
    }

    public function test_entrada_ja_baixada_no_sistema_atual_so_registra(): void
    {
        // a mensalidade foi baixada no sistema atual (dia 13); o Pix do dia 12 chega pelo extrato do Inter
        $id = $this->pagamentoDoSistemaAtual('2026-11-13 00:00:00');
        $pix = \App\Models\FormaPagamento::where('tipo', 'pix')->first();
        $token = 'tk';
        Cache::put('extrato:'.$this->a->empresa->id.':'.$this->a->admin->id.':'.$token, ExtratoInter::buscar('2026-11-07', '2026-11-14', [$this->transacoes()[0]]) + ['origem' => 'inter'], 600);

        // a conferência avisa: o cliente tem uma baixa de lá com o mesmo valor
        $r = $this->actingAs($this->a->admin)->getJson('/extrato/cliente/'.$this->x->cliente->id)->assertOk();
        $this->assertEquals([['data' => '2026-11-13', 'valor' => 250]], $r->json('importados'));

        $this->actingAs($this->a->admin)->post('/extrato/confirmar', ['token' => $token, 'forma_pagamento_id' => $pix->id,
            'linhas' => [['indice' => 0, 'cliente_id' => $this->x->cliente->id, 'so_registro' => true]]])->assertRedirect();

        // nenhum pagamento novo, nenhum crédito; a entrada ficou registrada e o pagamento de lá ganhou a forma
        $this->assertSame(1, \App\Models\Pagamento::count());
        $this->assertSame(0, \App\Models\OrdemServico::where('tipo', 'credito')->count());
        $l = ExtratoLancamento::first();
        $this->assertTrue((bool) $l->so_registro);
        $this->assertSame($id, $l->pagamento_id);
        $this->assertSame($pix->id, \App\Models\Pagamento::find($id)->forma_pagamento_id);
        $this->assertSame([], $this->actingAs($this->a->admin)->getJson('/extrato/cliente/'.$this->x->cliente->id)->json('importados'));

        // a importação refaz os pagamentos de lá: a forma e a ligação voltam
        \Illuminate\Support\Facades\DB::table('pagamentos')->delete();
        $novo = $this->pagamentoDoSistemaAtual('2026-11-13 00:00:00');
        $this->assertSame(1, \App\Servicos\Extrato::religarRegistros());
        $this->assertSame($pix->id, \App\Models\Pagamento::find($novo)->forma_pagamento_id);
        $this->assertSame($novo, $l->refresh()->pagamento_id);
    }

    public function test_pix_ja_baixado_pelo_csv_aparece_como_processado(): void
    {
        ExtratoLancamento::create(['identificador' => 'csv-123', 'data' => '2026-11-12', 'valor' => 250, 'nome' => 'MARIA DA SILVA', 'documento' => '068258',
            'cliente_id' => $this->x->cliente->id, 'processado_em' => now()]);
        $r = ExtratoInter::buscar('2026-11-07', '2026-11-14', $this->transacoes());
        $this->assertArrayHasKey('T1', ExtratoInter::parecidos($r['entradas']));
    }
}
