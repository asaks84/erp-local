<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Models\Nfse;
use App\Models\OrdemServico;
use App\Models\TipoVeiculo;
use App\Models\Veiculo;
use App\Servicos\Baixa;
use App\Servicos\Cobrancas;
use App\Servicos\FormasPagamento;
use App\Servicos\Nfse\AssinaturaXml;
use App\Servicos\Nfse\ConfigNfse;
use App\Servicos\Nfse\Dps;
use App\Servicos\Nfse\Notas;
use App\Servicos\Ordens;
use App\Support\EmpresaAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * NFS-e pelo Sistema Nacional, com a API simulada e um certificado de teste (gerado aqui, autoassinado): monta a
 * DPS, assina, envia, guarda a nota; rejeição, envio sem resposta + conferir, cancelamento e o certificado.
 */
class NfseTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11222333000181';

    private object $a;

    private string $cert;

    private string $chave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-19 12:00:00', 'America/Sao_Paulo'));   // competência = mês da emissão: 11/2026
        $this->a = $this->criarEmpresa('a');
        app(EmpresaAtual::class)->definir($this->a->empresa->id);
        $this->a->empresa->update(['documento' => self::CNPJ, 'razao_social' => 'LOCAL ESTACIONAMENTO LTDA']);
        FormasPagamento::garantirPadrao();
        [$this->cert, $this->chave] = self::certificado('LOCAL ESTACIONAMENTO LTDA:'.self::CNPJ);
    }

    /** certificado de teste (autoassinado) com o CNPJ no nome, como o e-CNPJ */
    private static function certificado(string $cn): array
    {
        $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['countryName' => 'BR', 'organizationName' => 'ICP-Brasil', 'commonName' => $cn], $k, ['digest_alg' => 'sha256']);
        $x = openssl_csr_sign($csr, null, $k, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($x, $cert);
        openssl_pkey_export($k, $chave);

        return [$cert, $chave];
    }

    private function configurar(): void
    {
        ConfigNfse::gravar(['im' => '123456', 'serie' => '7']);
        ConfigNfse::gravarAliquotas('2026-11', '2.01', '17.62');
        Notas::guardarCertificado($this->cert, $this->chave);
    }

    /** mensalidade de um cliente (paga), sem nota */
    private function servico(?string $documento = '52998224725'): OrdemServico
    {
        $c = Cliente::create(['nome' => 'Maria da Silva', 'documento' => $documento, 'cidade' => 'Santos', 'cep' => '11010000', 'logradouro' => 'Rua XV de Novembro', 'numero' => '10', 'bairro' => 'Centro']);
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $c->id, 'descricao' => 'Mensalidade Diurno', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'competencia' => '2026-11', 'valor' => 180]);
        $o->update(['placa' => 'ABC1D23']);
        Baixa::receberNaHora([$o], FormaPagamento::where('tipo', 'pix')->value('id'));

        return $o->fresh();
    }

    private function emitir(OrdemServico|array $ordens, array $op = []): TestResponse
    {
        $ids = is_array($ordens) ? array_map(fn ($o) => $o->id, $ordens) : [$ordens->id];

        return $this->actingAs($this->a->admin)->postJson('/notas-fiscais/emitir', ['ordens' => $ids] + $op);
    }

    /** cobrança extra (novembro) com as contas em aberto do cliente e a tela de conferir dela */
    private function conferirCobranca(int $clienteId): TestResponse
    {
        $cob = Cobrancas::emitir(Cliente::find($clienteId), '2026-11', '2026-11-20', true);

        return $this->actingAs($this->a->admin)->get('/notas-fiscais/conferir?cobrancas='.$cob->id);
    }

    private function nfseFalsa(string $chave = '35485002211222333000181000000000000126110000000001'): array
    {
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS'.$chave.'"><nNFSe>42</nNFSe></infNFSe></NFSe>';

        return ['chaveAcesso' => $chave, 'nfseXmlGZipB64' => base64_encode(gzencode($xml)), 'dataHoraProcessamento' => '2026-11-19T21:00:00-03:00'];
    }

    public function test_falta_configurar_antes_de_emitir(): void
    {
        $p = $this->servico();
        $this->emitir($p)->assertStatus(422);
        $this->assertSame(0, Nfse::count());
    }

    public function test_emite_com_dps_assinada_e_guarda_a_nota(): void
    {
        $this->configurar();
        $p = $this->servico();
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $r->url());
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });

        $this->emitir($p)->assertJson(['situacao' => 'emitida']);

        $n = Nfse::first();
        $this->assertSame('emitida', $n->situacao);
        $this->assertSame('42', $n->numero_nfse);
        $this->assertSame('restrita', $n->ambiente);
        $this->assertSame('DPS'.'3548500'.'2'.self::CNPJ.'00007'.str_pad('1', 15, '0', STR_PAD_LEFT), $n->id_dps);
        $this->assertTrue(AssinaturaXml::confere($enviado, $this->cert));

        $x = new \SimpleXMLElement($enviado);
        $x->registerXPathNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $v = fn (string $c) => (string) ($x->xpath('//n:'.$c)[0] ?? '');
        $this->assertSame('2', $v('tpAmb'));
        $this->assertSame('11222333000181', $v('prest/n:CNPJ'));
        $this->assertSame('3', $v('opSimpNac'));
        $this->assertSame('1', $v('regApTribSN'));
        $this->assertSame('52998224725', $v('toma/n:CPF'));
        $this->assertSame('3548500', $v('cMun'));
        $this->assertSame('110101', $v('cTribNac'));
        $this->assertSame('180.00', $v('vServ'));
        $this->assertSame('17.62', $v('pTotTribSN'));
        $this->assertSame('004', $v('cTribMun'));
        $this->assertSame('106043000', $v('cNBS'));
        $this->assertSame('1', $v('tpRetISSQN'));
        $this->assertStringNotContainsString('<pAliq>', $enviado);   // Simples sem retenção não informa alíquota
        $this->assertStringContainsString('</tribMun><tribFed><piscofins><CST>00</CST><tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed><totTrib>', $enviado);
        $this->assertSame('2026-11', $n->competencia);
        $this->assertSame('2026-11-19', $v('dCompet'));   // competência = dia da emissão
        $this->assertSame('ABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00', $v('xDescServ'));

        // o mesmo recebimento não ganha segunda nota
        $this->emitir($p)->assertStatus(422);
        $this->assertSame(1, Nfse::count());
    }

    public function test_sem_aliquotas_do_mes_nao_emite(): void
    {
        $this->configurar();
        $p = $this->servico();
        $this->travel(13)->days();   // emissão em 02/12: competência 12/2026
        Http::fake();
        $this->emitir($p)->assertStatus(422)->assertJson(['mensagem' => 'Falta informar as alíquotas de 12/2026 (do e-mail da contabilidade), no alto da página Notas fiscais.']);
        Http::assertNothingSent();

        $this->actingAs($this->a->admin)->put('/notas-fiscais/aliquotas', ['competencia' => '2026-12', 'iss' => '6', 'tributos' => 'x'])->assertSessionHasErrors(['iss', 'tributos']);
        $this->actingAs($this->a->admin)->put('/notas-fiscais/aliquotas', ['competencia' => '2026-12', 'iss' => '2,5', 'tributos' => '17,5'])->assertSessionHas('sucesso');
        $this->assertSame(['iss' => '2.50', 'tributos' => '17.50'], ConfigNfse::aliquotas('2026-12'));
        $this->assertSame(['iss' => '2.01', 'tributos' => '17.62'], ConfigNfse::aliquotas('2026-11'));
    }

    public function test_iss_retido_pelo_tomador(): void
    {
        $this->configurar();
        $p = $this->servico('11444777000161');
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        $this->emitir($p, ['retido' => true])->assertJson(['situacao' => 'emitida']);
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN><pAliq>2.01</pAliq>', $enviado);
        $this->assertStringContainsString('<cMun>3548500</cMun>', $enviado);   // endereço do tomador no município
        $n = Nfse::first();
        $this->assertTrue($n->iss_retido);
        $this->assertSame('3.62', $n->valor_iss);   // 180 x 2,01%

        // o padrão vem do cadastro do cliente
        $p->cliente->update(['nf_tomador' => true, 'nf_iss_retido' => true]);
        $p2 = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $p->cliente_id, 'descricao' => 'Mensalidade', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 180]);
        $this->conferirCobranca($p->cliente_id)->assertInertia(fn ($pg) => $pg->component('NotasFiscais/Conferir')->where('clientes.0.servicos.0.id', $p2->id)->where('clientes.0.retido', true)->where('clientes.0.pode_reter', true));

        // sem endereço completo no município: não deixa reter
        $p2->cliente->update(['cidade' => 'Campinas']);
        $this->emitir($p2, ['retido' => true])->assertStatus(422);
    }

    public function test_descricao_especificada_ou_nao_e_nota_sem_dados_do_cliente(): void
    {
        $this->configurar();
        $c = Cliente::create(['nome' => 'Empresa X', 'documento' => '11444777000161']);
        $ordens = [
            Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $c->id, 'descricao' => 'Mensalidade Diurno', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'competencia' => '2026-11', 'valor' => 180]),
            Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $c->id, 'descricao' => 'Estadia avulsa', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'valor' => 25]),
            Ordens::lancar(['tipo' => 'servico', 'cliente_id' => $c->id, 'descricao' => 'Lavagem simples', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'valor' => 40]),
        ];
        foreach ($ordens as $o) {
            $o->update(['placa' => 'ABC1D23']);
        }
        $v = Veiculo::create(['cliente_id' => $c->id, 'tipo_veiculo_id' => TipoVeiculo::value('id'), 'placa' => 'ABC1D23', 'marca' => 'VW', 'modelo' => 'POLO']);
        foreach ($ordens as $o) {
            $o->update(['veiculo_id' => $v->id]);
        }
        $lista = collect($ordens)->map->fresh();
        // carro fora do cadastro (o da esposa) lançado na conta do cliente: modelo digitado e placa da ordem
        $esposa = Ordens::lancar(['tipo' => 'servico', 'cliente_id' => $c->id, 'descricao' => 'Lavagem', 'data' => '2026-11-12', 'vencimento' => '2026-11-12', 'valor' => 40]);
        $esposa->update(['placa' => 'XYZ9A88', 'veiculo_descricao' => 'HB20']);
        $this->assertSame('12/11 - HB20 - XYZ9A88 - R$ 40,00', Dps::descricao(collect([$esposa->fresh()]), ConfigNfse::ler()));
        $esposa->update(['veiculo_descricao' => null, 'tipo_veiculo_id' => TipoVeiculo::value('id')]);
        $this->assertSame('12/11 - '.TipoVeiculo::value('nome').' - XYZ9A88 - R$ 40,00', Dps::descricao(collect([$esposa->fresh()]), ConfigNfse::ler()));
        $esposa->delete();

        $this->assertSame("POLO - ABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00\n05/11 - POLO - ABC1D23 - R$ 25,00\n05/11 - POLO - ABC1D23 - R$ 40,00", Dps::descricao($lista, ConfigNfse::ler()));
        ConfigNfse::gravar(['especificar_servicos' => true, 'descricao' => 'Serviço de estacionamento']);
        $this->assertSame("Serviço de estacionamento\nPOLO - ABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00\n05/11 - POLO - ABC1D23 - Estadia - R$ 25,00\n05/11 - POLO - ABC1D23 - Lavagem simples - R$ 40,00", Dps::descricao($lista, ConfigNfse::ler()));

        // sem os dados do cliente na nota (mesmo tendo CNPJ)
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        $this->emitir($ordens, ['identificar' => false])->assertJson(['situacao' => 'emitida']);
        $this->assertStringNotContainsString('<toma>', $enviado);
        $this->assertSame('245.00', Nfse::first()->valor);   // uma nota com os três serviços
        $n = Nfse::first();
        $this->assertFalse($n->tomador_identificado);
        $this->assertSame($c->id, $n->cliente_id);
        // padrão do cadastro: tudo desligado (sem dados do tomador)
        $p2 = Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $c->id, 'descricao' => 'Estadia', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 20]);
        $this->conferirCobranca($c->id)->assertInertia(fn ($pg) => $pg->where('clientes.0.servicos.0.id', $p2->id)->where('clientes.0.identificar', false)->where('clientes.0.retido', false));
        // ISS retido sem os dados do cliente na nota: não pode
        $this->emitir($p2, ['identificar' => false, 'retido' => true])->assertStatus(422);
    }

    public function test_tributacao_federal_de_outro_regime_com_retencao_na_configuracao(): void
    {
        $this->configurar();
        $adm = $this->actingAs($this->a->admin);
        $base = ['municipio_ibge' => '3548500', 'op_simples' => '1', 'reg_ap_trib_sn' => '1', 'reg_esp_trib' => '0', 'c_trib_nac' => '110101', 'c_trib_mun' => '004',
            'c_nbs' => '106043000', 'serie' => '7', 'descricao' => 'Estacionamento', 'pis_cofins_cst' => '01', 'tp_ret_pis_cofins' => '3'];
        $adm->put('/configuracoes/nfse', $base)->assertSessionHasErrors(['p_aliq_pis', 'p_aliq_cofins', 'p_ret_csll']);
        $adm->put('/configuracoes/nfse', $base + ['p_aliq_pis' => '0,65', 'p_aliq_cofins' => '3', 'p_ret_csll' => '1', 'p_ret_irrf' => '1,5'])->assertSessionHas('sucesso');   // aceita vírgula
        $this->assertSame(6.15, ConfigNfse::retencaoFederal(ConfigNfse::ler()));

        $p = $this->servico('11444777000161');   // R$ 180
        $enviado = null;
        $k = 0;
        Http::fake(function (Request $r) use (&$enviado, &$k) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa('3548500221122233300018100000000000012611000000000'.++$k), 201);
        });
        // o cliente retém: precisa dos dados dele na nota
        $this->emitir($p, ['identificar' => false, 'retem_federal' => true])->assertStatus(422);
        $this->emitir($p, ['retem_federal' => true])->assertJson(['situacao' => 'emitida']);
        $this->assertStringContainsString('<tribFed><piscofins><CST>01</CST><vBCPisCofins>180.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins>'
            .'<vPis>1.17</vPis><vCofins>5.40</vCofins><tpRetPisCofins>3</tpRetPisCofins></piscofins><vRetIRRF>2.70</vRetIRRF><vRetCSLL>8.37</vRetCSLL></tribFed>', $enviado);
        $n = Nfse::where('situacao', 'emitida')->first();
        $this->assertSame(['3', true, false, '11.07'], [$n->ret_federal, $n->ret_irrf, $n->ret_cp, $n->valor_ret_federal]);

        // padrão do cadastro do cliente; cliente que não retém: só CST, sem retenção
        $p->cliente->update(['nf_tomador' => true, 'nf_retem_federal' => true]);
        $p2 = Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $p->cliente_id, 'descricao' => 'Estadia', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 20]);
        $this->conferirCobranca($p->cliente_id)->assertInertia(fn ($pg) => $pg->where('clientes.0.servicos.0.id', $p2->id)->where('clientes.0.federal', true));
        $this->emitir($p2)->assertJson(['situacao' => 'emitida']);
        $this->assertStringContainsString('<tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>', $enviado);
    }

    public function test_ibs_cbs_sim_guarda_os_codigos_mas_nao_emite_ainda(): void
    {
        $this->configurar();
        $base = ['municipio_ibge' => '3548500', 'op_simples' => '3', 'reg_ap_trib_sn' => '1', 'reg_esp_trib' => '0', 'c_trib_nac' => '110101', 'c_trib_mun' => '004',
            'c_nbs' => '106043000', 'serie' => '7', 'descricao' => 'Estacionamento', 'pis_cofins_cst' => '00', 'tp_ret_pis_cofins' => '0', 'ibs_cbs' => true];
        $this->actingAs($this->a->admin)->put('/configuracoes/nfse', $base)->assertSessionHasErrors(['ibs_cbs_cst', 'ibs_cbs_class']);
        $this->actingAs($this->a->admin)->put('/configuracoes/nfse', $base + ['ibs_cbs_cst' => '000', 'ibs_cbs_class' => '000001'])->assertSessionHas('sucesso');
        $p = $this->servico();
        Http::fake();
        $this->emitir($p)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_lista_de_cobrancas_do_mes_conferir_e_avulsos(): void
    {
        $this->configurar();
        $adm = $this->actingAs($this->a->admin);
        $pago = $this->servico();                                    // Maria: mensalidade 05/11 paga, sem cobrança
        $c = Cliente::create(['nome' => 'Ana Empresa', 'documento' => '11444777000161']);
        $aberta = Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $c->id, 'descricao' => 'Estadia', 'data' => '2026-10-25', 'vencimento' => '2026-10-25', 'valor' => 30]);
        $velha = Ordens::lancar(['tipo' => 'servico', 'cliente_id' => $c->id, 'descricao' => 'Lavagem', 'data' => '2026-09-10', 'vencimento' => '2026-09-10', 'valor' => 50]);
        $outubro = Cobrancas::emitir($c, '2026-10', '2026-10-30');
        // novembro leva o saldo de outubro (que fica "levada") e soma a estadia nova; o crédito é ajuste, não entra
        $nova = Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $c->id, 'descricao' => 'Estadia', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'valor' => 40]);
        Ordens::lancar(['tipo' => 'credito', 'cliente_id' => $c->id, 'descricao' => 'Crédito', 'data' => '2026-11-01', 'valor' => 10]);
        $novembro = Cobrancas::emitir($c, '2026-11', '2026-11-20');
        $this->assertSame('levada', $outubro->fresh()->situacao);
        // cancelada e de outro mês não entram
        $b = Cliente::create(['nome' => 'Bruno']);
        Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $b->id, 'descricao' => 'Estadia', 'data' => '2026-11-02', 'vencimento' => '2026-11-02', 'valor' => 25]);
        Cobrancas::cancelar(Cobrancas::emitir($b, '2026-11', '2026-11-10'), 'errada');
        Cobrancas::emitir($b, '2026-12', '2026-12-10');
        $sem = Ordens::lancar(['tipo' => 'estadia', 'descricao' => 'Estadia avulsa', 'data' => '2026-11-10', 'vencimento' => '2026-11-10', 'valor' => 15]);
        $sem->update(['placa' => 'XYZ9A88']);

        // padrão: cobranças vencendo no mês atual, com o valor dos serviços sem nota
        $adm->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->where('aba', 'cobrancas')->where('filtros.mes', '2026-11')->has('cobrancas', 1)
            ->where('cobrancas.0.id', $novembro->id)->where('cobrancas.0.qtd', 3)->where('cobrancas.0.valor', 120)->where('cobrancas.0.total', 110));
        $adm->get('/notas-fiscais?mes=2026-10')->assertInertia(fn ($pg) => $pg->has('cobrancas', 0));          // outubro foi levada
        $adm->get('/notas-fiscais?mes=2026-12')->assertInertia(fn ($pg) => $pg->has('cobrancas', 1)->where('cobrancas.0.nome', 'Bruno'));

        // conferir: os serviços sem nota da cobrança, saldo anterior incluído; cobrança de outra empresa ou nenhuma volta
        $adm->get('/notas-fiscais/conferir?cobrancas='.$novembro->id.'&mes=2026-11')->assertInertia(fn ($pg) => $pg->where('filtros.mes', '2026-11')
            ->where('cobrancas', [$novembro->numero])->has('clientes', 1)->has('clientes.0.servicos', 3)->where('clientes.0.servicos.0.id', $velha->id));
        $adm->get('/notas-fiscais/conferir?cobrancas=999999')->assertRedirect('/notas-fiscais');
        $adm->get('/cobrancas/'.$novembro->id)->assertInertia(fn ($pg) => $pg->where('sem_nota', 3));

        // emitir serviço em aberto (antes do pagamento) e rejeição devolve os serviços
        Http::fakeSequence()->push(['erros' => [['codigo' => 'E1', 'descricao' => 'Recusada.']]], 400)->push($this->nfseFalsa(), 201)
            ->push($this->nfseFalsa('35485002211222333000181000000000000126110000000010'), 201)
            ->push($this->nfseFalsa('35485002211222333000181000000000000126110000000009'), 201);
        $this->emitir($aberta)->assertJson(['situacao' => 'rejeitada']);
        $this->assertNull($aberta->fresh()->nfse_id);
        $this->emitir($aberta)->assertJson(['situacao' => 'emitida']);
        $this->assertNotNull($aberta->fresh()->nfse_id);
        $this->emitir($aberta)->assertStatus(422);                    // já tem nota
        $this->emitir([$pago, $velha])->assertStatus(422)->assertJson(['mensagem' => 'Uma nota é de um cliente só.']);
        $adm->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->where('cobrancas.0.qtd', 2)->where('cobrancas.0.valor', 90));   // o que já tem nota sai
        $this->emitir([$velha, $nova])->assertJson(['situacao' => 'emitida']);
        $adm->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->has('cobrancas', 0)->where('comNota', 1));
        $adm->get('/notas-fiscais/conferir?cobrancas='.$novembro->id)->assertRedirect('/notas-fiscais?mes=2026-11');
        $adm->get('/cobrancas/'.$novembro->id)->assertInertia(fn ($pg) => $pg->where('sem_nota', 0));

        // avulsos: pela OS, pela placa; sem cadastro com tomador digitado
        $adm->get('/notas-fiscais?aba=avulsos&q='.$pago->numero)->assertInertia(fn ($pg) => $pg->has('avulsos', 1)->where('avulsos.0.id', $pago->id));
        $adm->get('/notas-fiscais?aba=avulsos&q=xyz9a88')->assertInertia(fn ($pg) => $pg->has('avulsos', 1)->where('avulsos.0.cliente', null));
        $this->emitir($sem, ['avulso_documento' => '529.982.247-25', 'avulso_nome' => ''])->assertStatus(422);
        $this->emitir($sem, ['avulso_documento' => '529.982.247-25', 'avulso_nome' => 'João Balcão'])->assertJson(['situacao' => 'emitida']);
        $enviado = gzdecode(base64_decode(Http::recorded()->last()[0]['dpsXmlGZipB64']));
        $this->assertStringContainsString('<toma><CPF>52998224725</CPF><xNome>João Balcão</xNome></toma>', $enviado);
    }

    public function test_cadastro_do_cliente_guarda_as_opcoes_de_nota(): void
    {
        $c = Cliente::create(['nome' => 'Cliente Novo', 'documento' => '11444777000161', 'dia_vencimento' => 10]);
        $this->assertFalse($c->fresh()->nf_tomador);   // tudo desligado por padrão
        $dados = ['nome' => 'Cliente Novo', 'documento' => '11.444.777/0001-61', 'dia_vencimento' => 10];
        $this->actingAs($this->a->admin)->put("/clientes/{$c->id}", $dados + ['nf_tomador' => true, 'nf_iss_retido' => true, 'nf_retem_federal' => true])->assertSessionHas('sucesso');
        $this->assertSame([true, true, true], [$c->fresh()->nf_tomador, $c->fresh()->nf_iss_retido, $c->fresh()->nf_retem_federal]);
        // sem dados do tomador não há retenção
        $this->actingAs($this->a->admin)->put("/clientes/{$c->id}", $dados + ['nf_tomador' => false, 'nf_iss_retido' => true])->assertSessionHas('sucesso');
        $this->assertSame([false, false, false], [$c->fresh()->nf_tomador, $c->fresh()->nf_iss_retido, $c->fresh()->nf_retem_federal]);
        // tela que não mostra as opções não mexe nelas
        $c->update(['nf_tomador' => true]);
        $this->actingAs($this->a->admin)->put("/clientes/{$c->id}", $dados)->assertSessionHas('sucesso');
        $this->assertTrue($c->fresh()->nf_tomador);
        $this->actingAs($this->a->admin)->get("/clientes/{$c->id}/editar")->assertInertia(fn ($pg) => $pg->where('cliente.nf_tomador', true));
    }

    /**
     * Compara a DPS montada aqui com a de uma nota real emitida pelo Emissor Nacional (mesmo caso: tomador CNPJ em
     * Santos, ISS retido a 2,01%, Simples): mesmos elementos, na mesma ordem (tirando os opcionais que o Emissor manda
     * a mais: fone, email e IM). O XML real fica só na memória do teste: os dados são reproduzidos aqui, sem a nota.
     */
    public function test_dps_tem_a_mesma_estrutura_da_nota_real_do_emissor(): void
    {
        $this->configurar();
        $c = Cliente::create(['nome' => 'TOMADOR TESTE', 'documento' => '11444777000161', 'cidade' => 'Santos', 'cep' => '11013203', 'logradouro' => 'SAO FRANCISCO', 'numero' => '276', 'bairro' => 'CENTRO']);
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $c->id, 'descricao' => 'Mensalidade', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'competencia' => '2026-11', 'valor' => 640]);
        $dps = Dps::montar($c, collect([$o]), $this->a->empresa->fresh(), ConfigNfse::ler(), 'producao', 2, ['iss' => '2.01', 'tributos' => '17.62'], ['retido' => true]);
        $caminhos = function (string $xml): array {
            $d = new \DOMDocument;
            $d->loadXML($xml);
            $r = [];
            $andar = function (\DOMElement $e, string $p) use (&$andar, &$r) {
                $r[] = $p.$e->localName;
                foreach ($e->childNodes as $f) {
                    if ($f instanceof \DOMElement) {
                        $andar($f, $p.$e->localName.'/');
                    }
                }
            };
            $andar($d->documentElement, '');

            return $r;
        };
        // estrutura da DPS da nota real (infDPS), sem fone/email/IM, que são opcionais
        $real = ['DPS', 'DPS/infDPS', 'DPS/infDPS/tpAmb', 'DPS/infDPS/dhEmi', 'DPS/infDPS/verAplic', 'DPS/infDPS/serie', 'DPS/infDPS/nDPS', 'DPS/infDPS/dCompet',
            'DPS/infDPS/tpEmit', 'DPS/infDPS/cLocEmi', 'DPS/infDPS/prest', 'DPS/infDPS/prest/CNPJ', 'DPS/infDPS/prest/regTrib', 'DPS/infDPS/prest/regTrib/opSimpNac',
            'DPS/infDPS/prest/regTrib/regApTribSN', 'DPS/infDPS/prest/regTrib/regEspTrib', 'DPS/infDPS/toma', 'DPS/infDPS/toma/CNPJ', 'DPS/infDPS/toma/xNome',
            'DPS/infDPS/toma/end', 'DPS/infDPS/toma/end/endNac', 'DPS/infDPS/toma/end/endNac/cMun', 'DPS/infDPS/toma/end/endNac/CEP', 'DPS/infDPS/toma/end/xLgr',
            'DPS/infDPS/toma/end/nro', 'DPS/infDPS/toma/end/xBairro', 'DPS/infDPS/serv', 'DPS/infDPS/serv/locPrest', 'DPS/infDPS/serv/locPrest/cLocPrestacao',
            'DPS/infDPS/serv/cServ', 'DPS/infDPS/serv/cServ/cTribNac', 'DPS/infDPS/serv/cServ/cTribMun', 'DPS/infDPS/serv/cServ/xDescServ', 'DPS/infDPS/serv/cServ/cNBS',
            'DPS/infDPS/valores', 'DPS/infDPS/valores/vServPrest', 'DPS/infDPS/valores/vServPrest/vServ', 'DPS/infDPS/valores/trib', 'DPS/infDPS/valores/trib/tribMun',
            'DPS/infDPS/valores/trib/tribMun/tribISSQN', 'DPS/infDPS/valores/trib/tribMun/tpRetISSQN', 'DPS/infDPS/valores/trib/tribMun/pAliq',
            'DPS/infDPS/valores/trib/tribFed', 'DPS/infDPS/valores/trib/tribFed/piscofins', 'DPS/infDPS/valores/trib/tribFed/piscofins/CST',
            'DPS/infDPS/valores/trib/tribFed/piscofins/tpRetPisCofins', 'DPS/infDPS/valores/trib/totTrib', 'DPS/infDPS/valores/trib/totTrib/pTotTribSN'];
        $this->assertSame($real, $caminhos($dps['xml']));
        $this->assertStringContainsString('<tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>2</tpRetISSQN><pAliq>2.01</pAliq></tribMun>', $dps['xml']);
    }

    public function test_cidade_de_fora_da_lista_pelo_cep(): void
    {
        $this->configurar();
        $c = Cliente::create(['nome' => 'EMPRESA DE CAMPINAS', 'documento' => '11444777000161', 'cidade' => 'Campinas', 'cep' => '13010000', 'logradouro' => 'RUA X', 'numero' => '1', 'bairro' => 'CENTRO']);
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $c->id, 'descricao' => 'Mensalidade', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'competencia' => '2026-11', 'valor' => 100]);
        $consultas = 0;
        Http::fake(function (Request $r) use (&$consultas) {
            if (str_contains($r->url(), 'viacep.com.br')) {
                $consultas++;

                return Http::response(['ibge' => str_contains($r->url(), '13010000') ? '3509502' : '3550308']);
            }

            return Http::response($this->nfseFalsa(), 201);
        });
        $this->emitir($o)->assertJson(['situacao' => 'emitida']);
        $dps = Http::recorded()->first(fn ($x) => str_contains($x[0]->url(), 'SefinNacional'))[0];
        $this->assertStringContainsString('<endNac><cMun>3509502</cMun><CEP>13010000</CEP></endNac>', gzdecode(base64_decode($dps['dpsXmlGZipB64'])));
        $this->assertSame(['3509502', '13010000'], [$c->fresh()->cidade_ibge, $c->fresh()->cidade_ibge_cep]);
        // já guardado: não consulta de novo; CEP mudou: consulta
        $this->assertSame('3509502', Dps::municipioDoCliente($c->fresh()));
        $this->assertSame(1, $consultas);
        $c->update(['cep' => '01001000']);
        $this->assertSame('3550308', Dps::municipioDoCliente($c->fresh()));
        $this->assertSame(2, $consultas);
    }

    public function test_configuracao_aceita_codigo_como_o_emissor_mostra(): void
    {
        $this->actingAs($this->a->admin)->put('/configuracoes/nfse', [
            'municipio_ibge' => '3548500', 'op_simples' => '3', 'reg_ap_trib_sn' => '1', 'reg_esp_trib' => '0',
            'c_trib_nac' => '11.01.01', 'c_trib_mun' => '11.01.01.004', 'c_nbs' => '1.0604.30.00', 'serie' => '7', 'descricao' => 'Estacionamento',
            'pis_cofins_cst' => '00', 'tp_ret_pis_cofins' => '0',
        ])->assertSessionHas('sucesso');
        $c = ConfigNfse::ler();
        $this->assertSame(['110101', '004', '106043000'], [$c['c_trib_nac'], $c['c_trib_mun'], $c['c_nbs']]);
    }

    public function test_regime_hibrido_e_consumidor_sem_cpf(): void
    {
        $this->configurar();
        ConfigNfse::gravar(['reg_ap_trib_sn' => '2']);
        $p = $this->servico(null);
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        Notas::emitir([$p]);
        $this->assertStringContainsString('<regApTribSN>2</regApTribSN>', $enviado);
        $this->assertStringNotContainsString('<toma>', $enviado);
    }

    public function test_rejeitada_guarda_o_motivo_e_deixa_emitir_de_novo(): void
    {
        $this->configurar();
        $p = $this->servico();
        Http::fakeSequence()->push(['erros' => [['codigo' => 'E0312', 'descricao' => 'Código de tributação nacional inexistente.']]], 400)->push($this->nfseFalsa(), 201);

        $this->emitir($p)->assertJson(['situacao' => 'rejeitada']);
        $n = Nfse::first();
        $this->assertSame('rejeitada', $n->situacao);
        $this->assertSame('E0312: Código de tributação nacional inexistente.', $n->mensagem);

        // corrigiu e emitiu de novo: outro número de DPS
        $this->emitir($p)->assertJson(['situacao' => 'emitida']);
        $this->assertSame([1, 2], Nfse::orderBy('id')->pluck('numero_dps')->all());
    }

    public function test_sem_resposta_fica_enviando_e_conferir_acha_a_nota(): void
    {
        $this->configurar();
        $p = $this->servico();
        $caiu = true;
        Http::fake(function (Request $r) use (&$caiu) {
            if ($caiu) {
                throw new ConnectionException('timeout');
            }

            return str_contains($r->url(), '/dps/')
                ? Http::response(['chaveAcesso' => '35485002211222333000181000000000000126110000000001'], 200)
                : Http::response(['nfseXmlGZipB64' => $this->nfseFalsa()['nfseXmlGZipB64']], 200);
        });
        $this->emitir($p)->assertJson(['situacao' => 'enviando']);
        $n = Nfse::first();
        $this->assertSame('enviando', $n->situacao);

        $caiu = false;
        $this->actingAs($this->a->admin)->post("/notas-fiscais/{$n->id}/conferir")->assertSessionHas('sucesso');
        $this->assertSame('emitida', $n->fresh()->situacao);
        $this->assertSame('42', $n->fresh()->numero_nfse);
    }

    public function test_cancela_com_evento_assinado(): void
    {
        $this->configurar();
        $p = $this->servico();
        Http::fake(['*/SefinNacional/nfse' => Http::response($this->nfseFalsa(), 201)]);
        $n = Notas::emitir([$p]);

        $evento = null;
        Http::fake(function (Request $r) use (&$evento) {
            $this->assertStringEndsWith('/nfse/'.'35485002211222333000181000000000000126110000000001/eventos', $r->url());
            $evento = gzdecode(base64_decode($r['pedidoRegistroEventoXmlGZipB64']));

            return Http::response(['eventoXmlGZipB64' => 'x'], 201);
        });
        $this->actingAs($this->a->admin)->post("/notas-fiscais/{$n->id}/cancelar", ['motivo' => 'curto', 'codigo' => 1])->assertSessionHasErrors('motivo');
        $this->actingAs($this->a->admin)->post("/notas-fiscais/{$n->id}/cancelar", ['motivo' => 'Cliente pagou em duplicidade', 'codigo' => 1])->assertSessionHas('sucesso');
        $this->assertSame('cancelada', $n->fresh()->situacao);
        $this->assertTrue(AssinaturaXml::confere($evento, $this->cert, 'infPedReg'));
        $this->assertStringContainsString('<CNPJAutor>11222333000181</CNPJAutor>', $evento);
        $this->assertStringContainsString('Id="PRE35485002211222333000181000000000000126110000000001101101"', $evento);
        // cancelada: o serviço volta a ficar sem nota
        $this->assertNull($p->fresh()->nfse_id);
    }

    public function test_certificado_precisa_ser_do_cnpj_e_a_chave_dele(): void
    {
        [$outro, $outraChave] = self::certificado('OUTRA EMPRESA:99888777000166');
        $adm = $this->actingAs($this->a->admin);
        $adm->post('/configuracoes/nfse/certificado', ['certificado' => $outro, 'chave' => $outraChave])->assertSessionHasErrors('certificado');
        $adm->post('/configuracoes/nfse/certificado', ['certificado' => $this->cert, 'chave' => $outraChave])->assertSessionHasErrors('certificado');
        $adm->post('/configuracoes/nfse/certificado', ['certificado' => $this->cert, 'chave' => $this->chave])->assertSessionHas('sucesso');
        $i = ConfigNfse::integracao();
        $this->assertSame('restrita', $i->ambiente);
        // guardado criptografado: o texto do certificado não aparece no banco
        $this->assertStringNotContainsString('BEGIN', (string) \DB::table('integracoes')->where('tipo', 'nfse')->value('credenciais'));
        // operador sem a permissão não vê nada
        $this->actingAs($this->a->operador)->get('/notas-fiscais')->assertForbidden();
        $this->actingAs($this->a->admin)->get('/configuracoes/nfse')->assertInertia(fn ($pg) => $pg->component('Configuracoes/Nfse')->where('certificado.validade', Carbon::createFromTimestamp(openssl_x509_parse($this->cert)['validTo_time_t'], config('app.timezone'))->format('d/m/Y')));
    }
}
