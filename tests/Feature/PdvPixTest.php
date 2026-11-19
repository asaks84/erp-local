<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Empresa;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\Integracao;
use App\Models\Pagamento;
use App\Models\PixCobranca;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Servicos\FormasPagamento;
use App\Servicos\Inter\ExtratoInter;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Etapa 8.5: Pix com QR do Inter no PDV (cob imediata simulada), vínculo com o pagamento e o extrato. */
class PdvPixTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    /** estado do Inter simulado: txid => status, valor pago */
    private array $inter = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00:00');
        Cache::flush();
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        $t->tipos()->attach($this->a->tipo->id);
        Pins::definir($this->a->operador, '4831');
        Integracao::create(['tipo' => 'inter', 'ativo' => true, 'credenciais' => ['client_id' => 'id', 'client_secret' => 'segredo',
            'certificado' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----", 'chave' => "-----BEGIN PRIVATE KEY-----\nY\n-----END PRIVATE KEY-----"]]);
        $e = Empresa::atual();
        $e->configuracoes = array_merge($e->configuracoes ?? [], ['pix_chave' => '12345678000199']);
        $e->save();
        $ap = Aparelhos::novoCodigo(Aparelho::create(['nome' => 'Balcão']));
        $this->token = $this->postJson('/api/pdv/parear', ['codigo' => $ap])->assertOk()->json('token');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);

        Http::fake(function (Request $r) {
            $url = $r->url();
            if (str_ends_with($url, '/oauth/v2/token')) {
                return Http::response(['access_token' => 'tok', 'expires_in' => 3600]);
            }
            if (preg_match('~/pix/v2/cob/([A-Za-z0-9]+)$~', $url, $m)) {
                $txid = $m[1];
                if ($r->method() === 'PUT') {
                    $this->inter[$txid] = ['status' => 'ATIVA', 'valor' => $r->data()['valor']['original'], 'chave' => $r->data()['chave']];

                    return Http::response(['txid' => $txid, 'status' => 'ATIVA', 'pixCopiaECola' => '00020101021226...'.$txid]);
                }
                if ($r->method() === 'PATCH') {
                    $this->inter[$txid]['status'] = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';

                    return Http::response(['txid' => $txid, 'status' => $this->inter[$txid]['status']]);
                }
                $s = $this->inter[$txid];

                return Http::response(['txid' => $txid, 'status' => $s['status'], 'pix' => $s['status'] === 'CONCLUIDA'
                    ? [['endToEndId' => 'E000'.$txid, 'valor' => $s['pago'] ?? $s['valor'], 'horario' => '2026-11-10T13:05:00.000Z', 'pagador' => ['nome' => 'MARIA SILVA']]] : []]);
            }

            return Http::response([], 404);
        });
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

    private function forma(string $tipo): int
    {
        return FormaPagamento::where('tipo', $tipo)->value('id');
    }

    private function pagar(int $pixId, ?string $valor = null): void
    {
        $txid = PixCobranca::find($pixId)->txid;
        $this->inter[$txid]['status'] = 'CONCLUIDA';
        if ($valor) {
            $this->inter[$txid]['pago'] = $valor;
        }
    }

    private function estadiaNoPatio(string $placa): Estadia
    {
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => $placa, 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();

        return Estadia::where('placa', $placa)->first();
    }

    public function test_pix_com_qr_paga_a_saida_e_fica_vinculado(): void
    {
        $this->pdv()->getJson('/api/pdv/eu')->assertJsonPath('pix_qr', true);
        $e = $this->estadiaNoPatio('AAA1A11');
        Carbon::setTestNow(now()->addHours(2));

        $pix = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 20, 'descricao' => 'Estacionamento AAA-1A11'])->assertOk()
            ->assertJsonPath('situacao', 'ativa')->assertJsonPath('segundos', 900)->json();
        $this->assertStringStartsWith('000201', $pix['copia_cola']);
        $p = PixCobranca::find($pix['id']);
        $this->assertSame(['20.00', '12345678000199'], [$this->inter[$p->txid]['valor'], $this->inter[$p->txid]['chave']]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{26,35}$/', $p->txid);

        // ainda não pago: a saída com o Pix é recusada
        $this->pdv()->getJson("/api/pdv/pix/{$p->id}")->assertJsonPath('situacao', 'ativa');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pix_id' => $p->id, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('pix');
        $this->assertSame('patio', $e->fresh()->situacao);

        // pagou: a consulta vê e a saída registra com o txid
        $this->pagar($p->id);
        Carbon::setTestNow(now()->addSeconds(3));
        $this->pdv()->getJson("/api/pdv/pix/{$p->id}")->assertJsonPath('situacao', 'paga')->assertJsonPath('pagador', 'MARIA SILVA')->assertJsonPath('copia_cola', null);
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pix_id' => $p->id, 'pin' => '4831'])->assertOk();
        $pg = Pagamento::latest('id')->first();
        $this->assertSame([$p->txid, '20.00', 'pdv'], [$pg->pix_txid, $pg->valor, $pg->origem]);
        $this->assertStringContainsString('Pix QR de MARIA SILVA', $pg->observacao);
        $this->assertSame($pg->id, $p->fresh()->pagamento_id);
        $this->assertSame('2026-11-10 10:05', $p->fresh()->pago_em->format('Y-m-d H:i'));

        // o mesmo Pix não paga outra coisa
        $e2 = $this->estadiaNoPatio('BBB1B22');
        Carbon::setTestNow(now()->addHours(2));
        $this->pdv()->postJson("/api/pdv/estadias/{$e2->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pix_id' => $p->id, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('pix');
        $this->assertSame('patio', $e2->fresh()->situacao);

        // extrato do Inter: o Pix do QR já baixado fica de fora; outro Pix entra para conferir
        $r = ExtratoInter::buscar('2026-11-10', '2026-11-10', [
            ['idTransacao' => 't1', 'dataEntrada' => '2026-11-10', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '20.00', 'titulo' => 'Pix recebido', 'detalhes' => ['txId' => $p->txid, 'nomePagador' => 'MARIA SILVA']],
            ['idTransacao' => 't2', 'dataEntrada' => '2026-11-10', 'tipoTransacao' => 'PIX', 'tipoOperacao' => 'C', 'valor' => '35.00', 'titulo' => 'Pix recebido', 'detalhes' => ['nomePagador' => 'JOSE']],
        ]);
        $this->assertSame(['t2'], array_column($r['entradas'], 'id'));
    }

    public function test_pix_com_valor_diferente_nao_registra_e_aparece_no_fechamento(): void
    {
        $this->a->niveis->gerente->update(['permissoes' => ['pdv_gerente']]);
        Pins::definir($this->a->gerente, '5072');
        $e = $this->estadiaNoPatio('AAA1A11');
        Carbon::setTestNow(now()->addHours(2));
        $id = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 15])->assertOk()->json('id');
        $this->pagar($id, '15.00');
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pix_id' => $id, 'pin' => '4831'])
            ->assertStatus(422)->assertJsonValidationErrors('pix');
        $this->assertSame(0, Pagamento::count());
        $this->assertNull(PixCobranca::find($id)->pagamento_id);

        $r = $this->pdv()->postJson('/api/pdv/caixa/resumo', ['pin' => '5072'])->assertOk()->json();
        $this->assertSame([[15, 'MARIA SILVA']], array_map(fn ($x) => [$x['valor'], $x['pagador']], $r['pix_sem_uso']));
    }

    public function test_pix_sem_qr_marca_para_conferir_e_cancelar_tira_do_ar(): void
    {
        $e = $this->estadiaNoPatio('AAA1A11');
        Carbon::setTestNow(now()->addHours(2));
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pin' => '4831'])->assertOk();
        $pg = Pagamento::first();
        $this->assertNull($pg->pix_txid);
        $this->assertSame('Pix sem QR: conferir no extrato', $pg->observacao);

        // desistiu: o QR sai do ar
        $id = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 10])->json('id');
        $this->pdv()->postJson("/api/pdv/pix/{$id}/cancelar")->assertOk()->assertJsonPath('situacao', 'cancelada');
        $this->assertSame('REMOVIDA_PELO_USUARIO_RECEBEDOR', $this->inter[PixCobranca::find($id)->txid]['status']);

        // pagou bem na hora de cancelar: fica pago
        $id2 = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 10])->json('id');
        $this->pagar($id2);
        $this->pdv()->postJson("/api/pdv/pix/{$id2}/cancelar")->assertOk()->assertJsonPath('situacao', 'paga');

        // QR venceu sem pagar
        $id3 = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 10])->json('id');
        Carbon::setTestNow(now()->addMinutes(17));
        $this->pdv()->getJson("/api/pdv/pix/{$id3}")->assertJsonPath('situacao', 'expirada');
    }

    public function test_saida_cobra_o_valor_da_hora_do_calculo(): void
    {
        $e = $this->estadiaNoPatio('AAA1A11');
        Carbon::setTestNow('2026-11-10 08:55:00');
        $calc = $this->pdv()->getJson("/api/pdv/estadias/{$e->id}/calculo")->json('calculo');
        $this->assertSame(15, $calc['a_pagar']);
        $id = $this->pdv()->postJson('/api/pdv/pix', ['valor' => 15])->json('id');
        // o cliente demorou: já passou de 1h, mas vale o valor da tela
        Carbon::setTestNow('2026-11-10 09:12:00');
        $this->pagar($id);
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pix_id' => $id,
            'saida_calculo' => $calc['saida'], 'pin' => '4831'])->assertOk();
        $this->assertSame(['15.00', '08:55'], [$e->fresh()->valor_calculado, $e->fresh()->saida->format('H:i')]);

        // cálculo velho demais (mais de 20 min) não vale: cobra a hora de agora
        $e2 = $this->estadiaNoPatio('BBB1B22');
        Carbon::setTestNow('2026-11-10 10:30:00');
        $this->pdv()->postJson("/api/pdv/estadias/{$e2->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('dinheiro'),
            'saida_calculo' => '2026-11-10 09:40', 'pin' => '4831'])->assertOk();
        $this->assertSame('10:30', $e2->fresh()->saida->format('H:i'));
    }

    public function test_configuracao_da_chave_e_pix_desligado(): void
    {
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/pix', ['pix_chave' => 'xyz'])->assertSessionHasErrors('pix_chave');
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/pix', ['pix_chave' => '12.345.678/0001-99'])->assertSessionHasNoErrors();
        $this->assertSame('12345678000199', Empresa::atual()->fresh()->config('pix_chave'));
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/testar-pix')->assertSessionHas('sucesso');
        $this->actingAs($this->a->admin)->post('/configuracoes/inter/pix', ['pix_chave' => ''])->assertSessionHasNoErrors();
        $this->pdv()->getJson('/api/pdv/eu')->assertJsonPath('pix_qr', false);
        $this->pdv()->postJson('/api/pdv/pix', ['valor' => 10])->assertStatus(422)->assertJsonValidationErrors('pix');
        // desligado: Pix sem pix_id não ganha observação
        $e = $this->estadiaNoPatio('AAA1A11');
        Carbon::setTestNow(now()->addHours(2));
        $this->pdv()->postJson("/api/pdv/estadias/{$e->id}/saida", ['destino' => 'pago', 'forma_pagamento_id' => $this->forma('pix'), 'pin' => '4831'])->assertOk();
        $this->assertNull(Pagamento::first()->observacao);
    }
}
