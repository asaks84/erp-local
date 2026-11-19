<?php

namespace Tests\Feature;

use App\Models\Aparelho;
use App\Models\Estadia;
use App\Models\FormaPagamento;
use App\Models\Pagamento;
use App\Models\PdvAcao;
use App\Models\Tarifa;
use App\Models\TarifaFaixa;
use App\Models\TarifaPacote;
use App\Servicos\FormasPagamento;
use App\Servicos\Pdv\Aparelhos;
use App\Servicos\Pdv\Pins;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Etapa 8.7: o que o celular fez sem internet sobe sem duplicar, na hora em que aconteceu, e o que não fecha vira pendência. */
class PdvSemInternetTest extends TestCase
{
    use RefreshDatabase;

    private object $a;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 18:00:00');
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        FormasPagamento::garantirPadrao();
        $t = Tarifa::create(['nome' => 'Carros', 'tolerancia_min' => 10, 'fracao_min' => 0, 'restante' => 'faixas', 'vigencia_inicio' => '2000-01-01']);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 0, 'duracao_min' => 60, 'modo' => 'fixo', 'bloco_min' => 60, 'valor' => 15]);
        TarifaFaixa::create(['tarifa_id' => $t->id, 'ordem' => 1, 'duracao_min' => null, 'modo' => 'bloco', 'bloco_min' => 60, 'valor' => 5]);
        TarifaPacote::create(['tarifa_id' => $t->id, 'nome' => 'Diária 12h', 'duracao_min' => 720, 'valor' => 40]);
        $t->tipos()->attach($this->a->tipo->id);
        Pins::definir($this->a->operador, '4831');
        $ap = Aparelhos::novoCodigo(Aparelho::create(['nome' => 'Balcão']));
        $this->token = $this->postJson('/api/pdv/parear', ['codigo' => $ap])->assertOk()->json('token');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
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

    private function acao(string $tipo, string $quando, array $dados, ?string $uuid = null): array
    {
        return ['uuid' => $uuid ?? (string) Str::uuid(), 'tipo' => $tipo, 'feita_em' => Carbon::parse($quando)->toIso8601String(),
            'usuario_id' => $this->a->operador->id, 'dados' => $dados];
    }

    private function subir(array $acoes): array
    {
        return $this->pdv()->postJson('/api/pdv/sincronizar', ['acoes' => $acoes])->assertOk()->json('resultados');
    }

    public function test_pacote_tem_o_que_o_celular_precisa(): void
    {
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'pat1a11', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $j = $this->pdv()->getJson('/api/pdv/pacote')->assertOk()->json();
        $this->assertSame(10, $j['tabelas'][$this->a->tipo->id]['regras']['tolerancia']);
        $this->assertSame('PAT1A11', $j['patio'][0]['placa']);
        $this->assertSame($this->a->tipo->id, $j['patio'][0]['tipo_veiculo_id']);
        $this->assertSame(1, $j['ultimo_numero']);
        $this->assertContains($this->a->operador->id, array_column($j['usuarios'], 'id'));
        // PIN nunca vai no pacote
        $this->assertStringNotContainsString('pin_hash', json_encode($j));
        // conferir o PIN devolve o que o celular guarda para conferir sem internet
        $this->pdv()->postJson('/api/pdv/pin/conferir', ['pin' => '4831'])->assertOk()->assertJsonPath('usuario.gerente', false);
    }

    public function test_dia_sem_internet_sobe_na_hora_certa_e_sem_duplicar(): void
    {
        $dinheiro = FormaPagamento::where('tipo', 'dinheiro')->value('id');
        $entrada = $this->acao('estadia_entrada', '2026-11-10 09:00', ['placa' => 'off1a23', 'modelo' => 'Gol', 'tipo_veiculo_id' => $this->a->tipo->id,
            'codigo' => 'abcdefgh12345678', 'numero' => 7]);
        // 09:00 a 11:30 = 2h30: R$ 15 + 2 × R$ 5 = R$ 25
        $saida = $this->acao('estadia_saida', '2026-11-10 11:30', ['codigo' => 'abcdefgh12345678', 'destino' => 'pago', 'forma_pagamento_id' => $dinheiro,
            'valor_recebido' => 30, 'cobrado' => 25, 'rotulo' => 'OFF1A23']);

        $r = $this->subir([$entrada, $saida]);
        $this->assertSame(['ok', 'ok'], array_column($r, 'situacao'));

        $e = Estadia::where('codigo', 'abcdefgh12345678')->first();
        $this->assertSame('2026-11-10 09:00', $e->entrada->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 11:30', $e->saida->format('Y-m-d H:i'));
        $this->assertSame('finalizada', $e->situacao);
        $this->assertSame(1, $e->numero);
        $this->assertSame(7, $e->numero_impresso); // o papel diz 7: dá para achar pelo número impresso
        $this->assertNotNull($e->aparelho_id);
        $p = Pagamento::where('situacao', 'confirmado')->first();
        $this->assertSame(25.0, (float) $p->valor);
        $this->assertSame('2026-11-10 11:30', $p->recebido_em->format('Y-m-d H:i'));
        $this->assertSame('pdv', $p->origem);
        $this->assertNotNull($p->caixa_id);

        // mandou de novo (resposta não chegou): nada duplica, mesma resposta
        $r2 = $this->subir([$entrada, $saida]);
        $this->assertSame(['ok', 'ok'], array_column($r2, 'situacao'));
        $this->assertSame(1, Estadia::count());
        $this->assertSame(1, Pagamento::count());
        $this->assertSame(0, PdvAcao::where('situacao', 'pendente')->count());
    }

    public function test_entrada_com_internet_e_codigo_da_acao_nao_duplica_pela_fila(): void
    {
        $uuid = (string) Str::uuid();
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'dup1a23', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831', 'acao_uuid' => $uuid])->assertOk();
        // a resposta não chegou: o celular guardou na fila e mandou de novo
        $r = $this->subir([$this->acao('estadia_entrada', '2026-11-10 17:59', ['placa' => 'dup1a23', 'tipo_veiculo_id' => $this->a->tipo->id], $uuid)]);
        $this->assertSame('ok', $r[0]['situacao']);
        $this->assertSame(1, Estadia::count());
        // e a mesma chamada de novo pela API também não duplica
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'dup1a23', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831', 'acao_uuid' => $uuid])->assertOk();
        $this->assertSame(1, Estadia::count());
    }

    public function test_o_que_nao_fecha_vira_pendencia_e_a_fila_segue(): void
    {
        $dinheiro = FormaPagamento::where('tipo', 'dinheiro')->value('id');
        // a gestão finalizou o carro enquanto o celular estava sem internet
        $this->pdv()->postJson('/api/pdv/estadias', ['placa' => 'ges1a23', 'tipo_veiculo_id' => $this->a->tipo->id, 'pin' => '4831'])->assertOk();
        $e = Estadia::first();
        $e->update(['situacao' => 'cancelada', 'motivo_cancelamento' => 'teste']);

        $r = $this->subir([
            $this->acao('estadia_saida', '2026-11-10 17:30', ['codigo' => $e->codigo, 'destino' => 'pago', 'forma_pagamento_id' => $dinheiro, 'cobrado' => 15, 'forma_nome' => 'Dinheiro']),
            $this->acao('estadia_saida', '2026-11-10 17:31', ['codigo' => 'zzzzzzzzzzzzzzzz', 'destino' => 'pago', 'cobrado' => 0, 'rotulo' => 'NAO1A23']),
            $this->acao('estadia_entrada', '2026-11-10 17:40', ['placa' => 'seg1a23', 'tipo_veiculo_id' => $this->a->tipo->id]),
        ]);
        $this->assertSame(['pendente', 'pendente', 'ok'], array_column($r, 'situacao'));
        $this->assertStringContainsString('já estava cancelado', $r[0]['mensagem']);
        $this->assertStringContainsString('R$ 15,00 no Dinheiro', $r[0]['mensagem']);
        $this->assertStringContainsString('não encontrado', $r[1]['mensagem']);
        $this->assertSame(1, Estadia::where('placa', 'SEG1A23')->count());
        $this->assertSame(2, PdvAcao::where('situacao', 'pendente')->count());
    }

    public function test_vale_o_que_o_celular_cobrou_e_a_diferenca_vira_pendencia(): void
    {
        $dinheiro = FormaPagamento::where('tipo', 'dinheiro')->value('id');
        $r = $this->subir([
            $this->acao('estadia_entrada', '2026-11-10 10:00', ['placa' => 'dif1a23', 'tipo_veiculo_id' => $this->a->tipo->id, 'codigo' => 'difdifdifdif0001']),
            // 1h30 dá R$ 20 aqui; o celular cobrou R$ 15
            $this->acao('estadia_saida', '2026-11-10 11:30', ['codigo' => 'difdifdifdif0001', 'destino' => 'pago', 'forma_pagamento_id' => $dinheiro, 'cobrado' => 15]),
        ]);
        $this->assertSame(['ok', 'pendente'], array_column($r, 'situacao'));
        $this->assertStringContainsString('cobrou R$ 15,00 e a gestão calcula R$ 20,00', $r[1]['mensagem']);
        $this->assertSame(15.0, (float) Pagamento::first()->valor);
        $this->assertSame('finalizada', Estadia::first()->situacao);
    }

    public function test_hora_absurda_ou_pessoa_inativa_vira_pendencia(): void
    {
        $futuro = $this->acao('estadia_entrada', '2026-11-11 09:00', ['placa' => 'fut1a23', 'tipo_veiculo_id' => $this->a->tipo->id]);
        $velha = $this->acao('estadia_entrada', '2026-10-01 09:00', ['placa' => 'vel1a23', 'tipo_veiculo_id' => $this->a->tipo->id]);
        $this->a->operador->update(['ativo' => false]);
        $inativo = $this->acao('estadia_entrada', '2026-11-10 17:00', ['placa' => 'ina1a23', 'tipo_veiculo_id' => $this->a->tipo->id]);
        $this->a->operador->update(['ativo' => true]);
        $r = $this->subir([$futuro, $velha]);
        $this->assertSame(['pendente', 'pendente'], array_column($r, 'situacao'));
        $this->assertStringContainsString('futuro', $r[0]['mensagem']);
        $this->assertStringContainsString('mais de 7 dias', $r[1]['mensagem']);
        $this->a->operador->update(['ativo' => false]);
        $this->assertSame('pendente', $this->subir([$inativo])[0]['situacao']);
        $this->assertSame(0, Estadia::count());
    }

    public function test_gerente_ve_e_resolve_a_pendencia_na_gestao(): void
    {
        $dinheiro = FormaPagamento::where('tipo', 'dinheiro')->value('id');
        $this->subir([$this->acao('estadia_saida', '2026-11-10 17:31', ['codigo' => 'zzzzzzzzzzzzzzzz', 'destino' => 'pago', 'forma_pagamento_id' => $dinheiro,
            'cobrado' => 12, 'forma_nome' => 'Dinheiro', 'rotulo' => 'SUM1A23'])]);
        $p = PdvAcao::where('situacao', 'pendente')->first();

        $adm = $this->actingAs($this->a->admin);
        $adm->get('/patio/pendencias')->assertInertia(fn ($pg) => $pg->component('Patio/Pendencias')->has('pendentes', 1)
            ->where('pendentes.0.placa', 'SUM1A23')->where('pendentes.0.cobrado', 'R$ 12,00 · Dinheiro')->where('pdv_pendencias', 1));
        // operador sem "Corrigir no pátio" não resolve
        $this->actingAs($this->a->operador)->post("/patio/pendencias/{$p->id}/resolver", ['observacao' => 'x'])->assertForbidden();
        $adm = $this->actingAs($this->a->admin);
        $adm->post("/patio/pendencias/{$p->id}/resolver", [])->assertSessionHasErrors('observacao');
        $adm->post("/patio/pendencias/{$p->id}/resolver", ['observacao' => 'Recebimento lançado à mão'])->assertSessionHas('sucesso');
        $this->assertSame('resolvida', $p->fresh()->situacao);
        $this->assertSame($this->a->admin->id, $p->fresh()->resolvida_por);
        $adm->get('/patio/pendencias')->assertInertia(fn ($pg) => $pg->has('pendentes', 0)->has('resolvidas', 1)->where('pdv_pendencias', 0));
    }
}
