<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\FormaPagamento;
use App\Models\Nfse;
use App\Models\Pagamento;
use App\Servicos\Baixa;
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

    private function recebimento(?string $documento = '52998224725'): Pagamento
    {
        $c = Cliente::create(['nome' => 'Maria da Silva', 'documento' => $documento, 'cidade' => 'Santos', 'cep' => '11010000', 'logradouro' => 'Rua XV de Novembro', 'numero' => '10', 'bairro' => 'Centro']);
        $o = Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $c->id, 'descricao' => 'Mensalidade Diurno', 'data' => '2026-11-05', 'vencimento' => '2026-11-05', 'competencia' => '2026-11', 'valor' => 180]);
        $o->update(['placa' => 'ABC1D23']);

        return Baixa::receberNaHora([$o], FormaPagamento::where('tipo', 'pix')->value('id'));
    }

    private function nfseFalsa(string $chave = '35485002211222333000181000000000000126110000000001'): array
    {
        $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe Id="NFS'.$chave.'"><nNFSe>42</nNFSe></infNFSe></NFSe>';

        return ['chaveAcesso' => $chave, 'nfseXmlGZipB64' => base64_encode(gzencode($xml)), 'dataHoraProcessamento' => '2026-11-19T21:00:00-03:00'];
    }

    public function test_falta_configurar_antes_de_emitir(): void
    {
        $p = $this->recebimento();
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHasErrors('nfse');
        $this->assertSame(0, Nfse::count());
    }

    public function test_emite_com_dps_assinada_e_guarda_a_nota(): void
    {
        $this->configurar();
        $p = $this->recebimento();
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/nfse', $r->url());
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });

        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHas('sucesso');

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
        $this->assertSame("Serviço de estacionamento\nABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00", $v('xDescServ'));

        // o mesmo recebimento não ganha segunda nota
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHasErrors('nfse');
        $this->assertSame(1, Nfse::count());
    }

    public function test_sem_aliquotas_do_mes_nao_emite(): void
    {
        $this->configurar();
        $p = $this->recebimento();
        $this->travel(13)->days();   // emissão em 02/12: competência 12/2026
        Http::fake();
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHasErrors(['nfse' => 'Falta informar as alíquotas de 12/2026 (do e-mail da contabilidade), no alto da página Notas fiscais.']);
        Http::assertNothingSent();

        $this->actingAs($this->a->admin)->put('/notas-fiscais/aliquotas', ['competencia' => '2026-12', 'iss' => '6', 'tributos' => 'x'])->assertSessionHasErrors(['iss', 'tributos']);
        $this->actingAs($this->a->admin)->put('/notas-fiscais/aliquotas', ['competencia' => '2026-12', 'iss' => '2,5', 'tributos' => '17,5'])->assertSessionHas('sucesso');
        $this->assertSame(['iss' => '2.50', 'tributos' => '17.50'], ConfigNfse::aliquotas('2026-12'));
        $this->assertSame(['iss' => '2.01', 'tributos' => '17.62'], ConfigNfse::aliquotas('2026-11'));
    }

    public function test_iss_retido_pelo_tomador(): void
    {
        $this->configurar();
        $p = $this->recebimento('11444777000161');
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}", ['retido' => true])->assertSessionHas('sucesso');
        $this->assertStringContainsString('<tpRetISSQN>2</tpRetISSQN><pAliq>2.01</pAliq>', $enviado);
        $this->assertStringContainsString('<cMun>3548500</cMun>', $enviado);   // endereço do tomador no município
        $n = Nfse::first();
        $this->assertTrue($n->iss_retido);
        $this->assertSame('3.62', $n->valor_iss);   // 180 x 2,01%

        // a próxima nota do mesmo cliente já vem marcada
        $p2 = Baixa::receberNaHora([Ordens::lancar(['tipo' => 'mensalidade', 'cliente_id' => $p->cliente_id, 'descricao' => 'Mensalidade', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 180])], FormaPagamento::where('tipo', 'pix')->value('id'));
        $this->actingAs($this->a->admin)->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->where('aEmitir.0.id', $p2->id)->where('aEmitir.0.retido_antes', true)->where('aEmitir.0.pode_reter', true));

        // sem endereço completo no município: não deixa reter
        $p2->cliente->update(['cidade' => 'Campinas']);
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p2->id}", ['retido' => true])->assertSessionHasErrors('nfse');
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
        $p = Baixa::receberNaHora($ordens, FormaPagamento::where('tipo', 'pix')->value('id'));

        $this->assertSame("Serviço de estacionamento\nABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00\nABC1D23 - R$ 25,00\nABC1D23 - R$ 40,00", Dps::descricao($p, ConfigNfse::ler()));
        ConfigNfse::gravar(['especificar_servicos' => true]);
        $this->assertSame("Serviço de estacionamento\nABC1D23 - Mensalidade Diurno 11/2026 - R$ 180,00\nABC1D23 - Estadia - R$ 25,00\nABC1D23 - Lavagem simples - R$ 40,00", Dps::descricao($p, ConfigNfse::ler()));

        // sem os dados do cliente na nota (mesmo tendo CNPJ)
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}", ['identificar' => false])->assertSessionHas('sucesso');
        $this->assertStringNotContainsString('<toma>', $enviado);
        $n = Nfse::first();
        $this->assertFalse($n->tomador_identificado);
        $this->assertSame($c->id, $n->cliente_id);
        // a próxima nota do cliente já vem sem os dados dele
        $p2 = Baixa::receberNaHora([Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $c->id, 'descricao' => 'Estadia', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 20])], FormaPagamento::where('tipo', 'pix')->value('id'));
        $this->actingAs($this->a->admin)->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->where('aEmitir.0.id', $p2->id)->where('aEmitir.0.identificar_antes', false));
        // ISS retido sem os dados do cliente na nota: não pode
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p2->id}", ['identificar' => false, 'retido' => true])->assertSessionHasErrors('nfse');
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

        $p = $this->recebimento('11444777000161');   // R$ 180
        $enviado = null;
        $k = 0;
        Http::fake(function (Request $r) use (&$enviado, &$k) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa('3548500221122233300018100000000000012611000000000'.++$k), 201);
        });
        // o cliente retém: precisa dos dados dele na nota
        $adm->post("/notas-fiscais/emitir/{$p->id}", ['identificar' => false, 'retem_federal' => true])->assertSessionHasErrors('nfse');
        $adm->post("/notas-fiscais/emitir/{$p->id}", ['retem_federal' => true])->assertSessionHas('sucesso');
        $this->assertStringContainsString('<tribFed><piscofins><CST>01</CST><vBCPisCofins>180.00</vBCPisCofins><pAliqPis>0.65</pAliqPis><pAliqCofins>3.00</pAliqCofins>'
            .'<vPis>1.17</vPis><vCofins>5.40</vCofins><tpRetPisCofins>3</tpRetPisCofins></piscofins><vRetIRRF>2.70</vRetIRRF><vRetCSLL>8.37</vRetCSLL></tribFed>', $enviado);
        $n = Nfse::where('situacao', 'emitida')->first();
        $this->assertSame(['3', true, false, '11.07'], [$n->ret_federal, $n->ret_irrf, $n->ret_cp, $n->valor_ret_federal]);

        // a próxima nota do cliente já vem marcada; cliente que não retém: só CST, sem retenção
        $p2 = Baixa::receberNaHora([Ordens::lancar(['tipo' => 'estadia', 'cliente_id' => $p->cliente_id, 'descricao' => 'Estadia', 'data' => '2026-11-06', 'vencimento' => '2026-11-06', 'valor' => 20])], FormaPagamento::where('tipo', 'pix')->value('id'));
        $adm->get('/notas-fiscais')->assertInertia(fn ($pg) => $pg->where('aEmitir.0.id', $p2->id)->where('aEmitir.0.federal_antes', true));
        $adm->post("/notas-fiscais/emitir/{$p2->id}")->assertSessionHas('sucesso');
        $this->assertStringContainsString('<tpRetPisCofins>0</tpRetPisCofins></piscofins></tribFed>', $enviado);
    }

    public function test_ibs_cbs_sim_guarda_os_codigos_mas_nao_emite_ainda(): void
    {
        $this->configurar();
        $base = ['municipio_ibge' => '3548500', 'op_simples' => '3', 'reg_ap_trib_sn' => '1', 'reg_esp_trib' => '0', 'c_trib_nac' => '110101', 'c_trib_mun' => '004',
            'c_nbs' => '106043000', 'serie' => '7', 'descricao' => 'Estacionamento', 'pis_cofins_cst' => '00', 'tp_ret_pis_cofins' => '0', 'ibs_cbs' => true];
        $this->actingAs($this->a->admin)->put('/configuracoes/nfse', $base)->assertSessionHasErrors(['ibs_cbs_cst', 'ibs_cbs_class']);
        $this->actingAs($this->a->admin)->put('/configuracoes/nfse', $base + ['ibs_cbs_cst' => '000', 'ibs_cbs_class' => '000001'])->assertSessionHas('sucesso');
        $p = $this->recebimento();
        Http::fake();
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHasErrors('nfse');
        Http::assertNothingSent();
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
        $p = $this->recebimento(null);
        $enviado = null;
        Http::fake(function (Request $r) use (&$enviado) {
            $enviado = gzdecode(base64_decode($r['dpsXmlGZipB64']));

            return Http::response($this->nfseFalsa(), 201);
        });
        Notas::emitir($p);
        $this->assertStringContainsString('<regApTribSN>2</regApTribSN>', $enviado);
        $this->assertStringNotContainsString('<toma>', $enviado);
    }

    public function test_rejeitada_guarda_o_motivo_e_deixa_emitir_de_novo(): void
    {
        $this->configurar();
        $p = $this->recebimento();
        Http::fakeSequence()->push(['erros' => [['codigo' => 'E0312', 'descricao' => 'Código de tributação nacional inexistente.']]], 400)->push($this->nfseFalsa(), 201);

        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHas('erro');
        $n = Nfse::first();
        $this->assertSame('rejeitada', $n->situacao);
        $this->assertSame('E0312: Código de tributação nacional inexistente.', $n->mensagem);

        // corrigiu e emitiu de novo: outro número de DPS
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHas('sucesso');
        $this->assertSame([1, 2], Nfse::orderBy('id')->pluck('numero_dps')->all());
    }

    public function test_sem_resposta_fica_enviando_e_conferir_acha_a_nota(): void
    {
        $this->configurar();
        $p = $this->recebimento();
        $caiu = true;
        Http::fake(function (Request $r) use (&$caiu) {
            if ($caiu) {
                throw new ConnectionException('timeout');
            }

            return str_contains($r->url(), '/dps/')
                ? Http::response(['chaveAcesso' => '35485002211222333000181000000000000126110000000001'], 200)
                : Http::response(['nfseXmlGZipB64' => $this->nfseFalsa()['nfseXmlGZipB64']], 200);
        });
        $this->actingAs($this->a->admin)->post("/notas-fiscais/emitir/{$p->id}")->assertSessionHas('aviso');
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
        $p = $this->recebimento();
        Http::fake(['*/SefinNacional/nfse' => Http::response($this->nfseFalsa(), 201)]);
        $n = Notas::emitir($p);

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
        // cancelada libera emitir de novo
        $this->assertNull(Notas::podeEmitir($p->fresh()));
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
