<?php

namespace App\Servicos\Nfse;

use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\Nfse;
use App\Models\Pagamento;
use App\Support\Atividades;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Emissão da NFS-e pelo Sistema Nacional: uma nota por recebimento.
 * - emitir(): monta a DPS, assina com o A1, envia. Emitida guarda a chave, o número e o XML da nota.
 *   Rejeitada guarda o motivo (dá para corrigir a configuração e emitir de novo, com outro número de DPS).
 *   Sem resposta (a conexão caiu): fica "enviando" e conferir() pergunta ao Sistema Nacional pela DPS.
 * - cancelar(): evento de cancelamento, assinado.
 */
class Notas
{
    /** recebimento que pode ter nota: confirmado, com valor e sem nota emitida ou em andamento */
    public static function podeEmitir(Pagamento $p): ?string
    {
        if ($p->situacao !== 'confirmado') {
            return 'Recebimento estornado não tem nota.';
        }
        if ((float) $p->valor <= 0) {
            return 'Recebimento sem valor.';
        }
        if (Nfse::where('pagamento_id', $p->id)->whereIn('situacao', ['enviando', 'emitida'])->exists()) {
            return 'Este recebimento já tem nota.';
        }

        return null;
    }

    /**
     * @param  bool  $retido  ISS retido pelo tomador (cliente que recolhe o ISS no lugar do Local)
     * @param  bool  $identificar  declarar os dados do cliente na nota (false: nota sem tomador)
     * @param  array{tipo?: string, irrf?: bool, cp?: bool}  $fed  retenção federal do cliente
     */
    public static function emitir(Pagamento $p, bool $retido = false, bool $identificar = true, array $fed = []): Nfse
    {
        $empresa = Empresa::atual();
        $cfg = ConfigNfse::ler($empresa);
        if ($f = ConfigNfse::faltando($empresa)) {
            throw ValidationException::withMessages(['nfse' => 'Falta configurar: '.implode(', ', $f).' (Configurações › Nota fiscal).']);
        }
        if ($motivo = self::podeEmitir($p)) {
            throw ValidationException::withMessages(['nfse' => $motivo]);
        }
        if ($cfg['ibs_cbs']) {
            // o grupo IBS/CBS da DPS entra quando for conferido no leiaute oficial em vigor (docs/16-nfse.md)
            throw ValidationException::withMessages(['nfse' => 'Com "Preencher as informações IBS/CBS" em Sim, a emissão por aqui ainda não está pronta. Emita pelo Emissor Nacional ou volte para Não.']);
        }
        $fed = ['tipo' => (string) ($fed['tipo'] ?? '0'), 'irrf' => (bool) ($fed['irrf'] ?? false), 'cp' => (bool) ($fed['cp'] ?? false)];
        $competencia = Dps::competencia($p);
        $aliq = ConfigNfse::aliquotas($competencia, $empresa);
        if (! $aliq) {
            throw ValidationException::withMessages(['nfse' => 'Falta informar as alíquotas de '.Carbon::parse($competencia.'-01')->format('m/Y').' (do e-mail da contabilidade), no alto da página Notas fiscais.']);
        }
        $i = ConfigNfse::integracao();
        $api = new SefinNacional($i);
        $serie = (string) (int) $cfg['serie'];

        $nota = DB::transaction(function () use ($p, $empresa, $cfg, $api, $serie, $i, $aliq, $retido, $identificar, $fed, $competencia) {
            $numero = (int) Nfse::where('serie', $serie)->lockForUpdate()->max('numero_dps') + 1;
            $dps = Dps::montar($p, $empresa, $cfg, $api->ambiente(), $numero, $aliq, $retido, $identificar, $fed);
            $xml = AssinaturaXml::assinar($dps['xml'], $i->credencial('certificado'), $i->credencial('chave'));

            return Nfse::create([
                'pagamento_id' => $p->id, 'ambiente' => $api->ambiente(), 'serie' => $serie, 'numero_dps' => $numero, 'id_dps' => $dps['id'],
                'valor' => $p->valor, 'tomador_documento' => $dps['tomador_documento'], 'tomador_nome' => $dps['tomador_nome'],
                'descricao' => $dps['descricao'], 'xml_dps' => $xml, 'criado_por' => Auth::id(), 'competencia' => $competencia,
                'cliente_id' => $p->cliente_id, 'tomador_identificado' => $identificar,
                'iss_retido' => $retido, 'aliquota_iss' => $retido ? $aliq['iss'] : null, 'valor_iss' => $dps['valor_iss'], 'p_tot_trib' => $aliq['tributos'],
                'ret_federal' => $fed['tipo'], 'ret_irrf' => $fed['irrf'], 'ret_cp' => $fed['cp'], 'valor_ret_federal' => $dps['valor_ret_federal'] ?: null,
            ]);
        });

        try {
            $r = $api->enviar($nota->xml_dps);
        } catch (SemResposta $e) {
            $nota->update(['mensagem' => 'Sem resposta do Sistema Nacional. Use "Conferir" para saber se a nota saiu.']);

            return $nota;
        }
        self::resultado($nota, $r);

        return $nota->fresh();
    }

    /** grava o que o Sistema Nacional respondeu */
    private static function resultado(Nfse $nota, array $r): void
    {
        $chave = $r['dados']['chaveAcesso'] ?? null;
        if ($chave) {
            $xml = SefinNacional::descompactar($r['dados']['nfseXmlGZipB64'] ?? null);
            $numero = $xml && preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $m) ? $m[1] : ($r['dados']['nNFSe'] ?? null);
            $nota->update(['situacao' => 'emitida', 'chave_acesso' => $chave, 'numero_nfse' => $numero, 'xml_nfse' => $xml,
                'emitida_em' => isset($r['dados']['dataHoraProcessamento']) ? Carbon::parse($r['dados']['dataHoraProcessamento']) : now(), 'mensagem' => null]);
            Atividades::registrar('nfse_emitida', 'NFS-e '.($numero ?? $chave).' de '.number_format((float) $nota->valor, 2, ',', '.').($nota->ambiente === 'restrita' ? ' (teste)' : ''));

            return;
        }
        $msg = $r['erros'] ? implode(' · ', $r['erros']) : 'Resposta sem a chave da nota (HTTP '.$r['status'].').';
        $nota->update(['situacao' => 'rejeitada', 'mensagem' => mb_substr($msg, 0, 1000)]);
        Atividades::registrar('nfse_rejeitada', 'DPS '.$nota->numero_dps.': '.mb_substr($msg, 0, 300));
    }

    /** nota "enviando": pergunta pela DPS se a nota saiu */
    public static function conferir(Nfse $nota): Nfse
    {
        if ($nota->situacao !== 'enviando') {
            return $nota;
        }
        $api = new SefinNacional(ConfigNfse::integracao() ?? throw new RuntimeException('Certificado não cadastrado.'));
        $r = $api->consultarDps($nota->id_dps);
        if (! empty($r['dados']['chaveAcesso'])) {
            $nfse = $api->consultarNfse($r['dados']['chaveAcesso']);
            $r['dados']['nfseXmlGZipB64'] ??= $nfse['dados']['nfseXmlGZipB64'] ?? null;
            self::resultado($nota, $r);
        } elseif ($r['status'] === 404) {
            // não chegou: dá para enviar de novo a mesma DPS (mesmo número)
            try {
                self::resultado($nota, $api->enviar($nota->xml_dps));
            } catch (SemResposta) {
                // segue enviando
            }
        }

        return $nota->fresh();
    }

    public static function cancelar(Nfse $nota, string $motivo, int $codigo = 1): Nfse
    {
        if ($nota->situacao !== 'emitida' || ! $nota->chave_acesso) {
            throw ValidationException::withMessages(['motivo' => 'Só nota emitida pode ser cancelada.']);
        }
        $i = ConfigNfse::integracao() ?? throw new RuntimeException('Certificado não cadastrado.');
        $api = new SefinNacional($i);
        $cnpj = preg_replace('/\D/', '', (string) Empresa::atual()->documento);
        $xml = AssinaturaXml::assinar(self::eventoCancelamento($nota, $cnpj, $motivo, $codigo), $i->credencial('certificado'), $i->credencial('chave'), 'infPedReg');
        $r = $api->evento($nota->chave_acesso, $xml);
        if (! $r['ok']) {
            throw ValidationException::withMessages(['motivo' => 'O Sistema Nacional recusou o cancelamento: '.(implode(' · ', $r['erros']) ?: 'HTTP '.$r['status'])]);
        }
        $nota->update(['situacao' => 'cancelada', 'cancelada_em' => now(), 'mensagem' => mb_substr('Cancelada: '.$motivo, 0, 1000)]);
        Atividades::registrar('nfse_cancelada', 'NFS-e '.($nota->numero_nfse ?? $nota->chave_acesso).': '.$motivo);

        return $nota;
    }

    /** pedido de registro de evento: cancelamento (e101101). Id = "PRE" + chave(50) + "101101" */
    public static function eventoCancelamento(Nfse $nota, string $cnpj, string $motivo, int $codigo = 1): string
    {
        $ns = Dps::NS;
        $amb = $nota->ambiente === 'producao' ? '1' : '2';
        $dh = now()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:sP');
        $m = htmlspecialchars(mb_substr(trim($motivo), 0, 255), ENT_XML1);

        return '<pedRegEvento xmlns="'.$ns.'" versao="1.00"><infPedReg Id="PRE'.$nota->chave_acesso.'101101">'
            .'<tpAmb>'.$amb.'</tpAmb><verAplic>ERP-Local-1.0</verAplic><dhEvento>'.$dh.'</dhEvento><CNPJAutor>'.$cnpj.'</CNPJAutor>'
            .'<chNFSe>'.$nota->chave_acesso.'</chNFSe><e101101><xDesc>Cancelamento de NFS-e</xDesc><cMotivo>'.$codigo.'</cMotivo><xMotivo>'.$m.'</xMotivo></e101101>'
            .'</infPedReg></pedRegEvento>';
    }

    /**
     * Guarda o certificado A1 (certificado e chave em PEM, tirados do .pfx no navegador: a senha do .pfx não sai do
     * computador). Confere se a chave é do certificado e se o certificado é do CNPJ da empresa.
     */
    public static function guardarCertificado(string $certPem, string $chavePem): Integracao
    {
        $x = @openssl_x509_read($certPem);
        if (! $x) {
            throw ValidationException::withMessages(['certificado' => 'Não deu para ler o certificado.']);
        }
        if (! @openssl_x509_check_private_key($x, $chavePem)) {
            throw ValidationException::withMessages(['certificado' => 'A chave não é deste certificado.']);
        }
        $info = openssl_x509_parse($x) ?: [];
        $validade = isset($info['validTo_time_t']) ? Carbon::createFromTimestamp($info['validTo_time_t'], config('app.timezone')) : null;
        if ($validade && $validade->isPast()) {
            throw ValidationException::withMessages(['certificado' => 'Este certificado venceu em '.$validade->format('d/m/Y').'.']);
        }
        $cnpj = preg_replace('/\D/', '', (string) Empresa::atual()->documento);
        $titular = json_encode($info['subject'] ?? [], JSON_UNESCAPED_UNICODE);
        if (strlen($cnpj) === 14 && ! str_contains((string) $titular, $cnpj)) {
            throw ValidationException::withMessages(['certificado' => 'Este certificado não é do CNPJ da empresa ('.$cnpj.'). Use o e-CNPJ A1 do Local.']);
        }
        $i = ConfigNfse::integracao() ?? new Integracao(['tipo' => 'nfse', 'ambiente' => 'restrita', 'ativo' => true]);
        $i->credenciais = ['certificado' => trim($certPem)."\n", 'chave' => trim($chavePem)."\n", 'titular' => $info['subject']['CN'] ?? null];
        $i->certificado_validade = $validade;
        $i->save();
        Atividades::registrar('nfse_certificado', 'certificado A1 da NFS-e cadastrado'.($validade ? ' (vale até '.$validade->format('d/m/Y').')' : ''));

        return $i;
    }
}
