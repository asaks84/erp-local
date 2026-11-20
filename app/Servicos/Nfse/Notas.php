<?php

namespace App\Servicos\Nfse;

use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\Integracao;
use App\Models\Nfse;
use App\Models\OrdemServico;
use App\Support\Atividades;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Emissão da NFS-e pelo Sistema Nacional, a partir dos serviços (ordens) sem nota.
 * - Cada serviço guarda a nota em que entrou (ordens_servico.nfse_id). Rejeitada ou cancelada, os serviços voltam a
 *   ficar sem nota. Pago ou não, não importa: a nota pode sair antes do pagamento (clientes que fecham o mês).
 * - emitir(): monta a DPS, assina com o A1, envia. Emitida guarda a chave, o número e o XML da nota.
 *   Sem resposta (a conexão caiu): fica "enviando" e conferir() pergunta ao Sistema Nacional pela DPS.
 * - cancelar(): evento de cancelamento, assinado.
 */
class Notas
{
    /** tipos de serviço que entram em nota (crédito e débito são ajustes, não serviço) */
    public const TIPOS = ['mensalidade', 'servico', 'estadia'];

    /** serviços sem nota: não cancelados, com valor */
    public static function semNota(): Builder
    {
        return OrdemServico::query()->whereNull('nfse_id')->where('situacao', '!=', 'cancelada')
            ->whereIn('tipo', self::TIPOS)->where('valor', '>', 0);
    }

    /** período padrão da lista de clientes: do início do mês anterior ao fim do mês atual */
    public static function periodoPadrao(?Carbon $agora = null): array
    {
        $agora = ($agora ?? now())->copy()->setTimezone('America/Sao_Paulo');

        return [$agora->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), $agora->copy()->endOfMonth()->toDateString()];
    }

    /** serviços sem nota dos clientes no período (pela data do serviço) */
    public static function semNotaDosClientes(string $de, string $ate): Builder
    {
        return self::semNota()->whereNotNull('cliente_id')->whereBetween('data', [$de, $ate]);
    }

    /**
     * @param  Collection<int, OrdemServico>|array<int>  $ordens  serviços da nota (do mesmo cliente, ou avulsos sem cliente)
     * @param  array  $op  identificar (dados do cliente na nota, padrão sim), retido (ISS retido pelo tomador),
     *                     retem_federal (o cliente retém os tributos federais: tipo e alíquotas da configuração),
     *                     avulso (['documento' => , 'nome' => ]: tomador digitado, para quem não tem cadastro)
     */
    public static function emitir(Collection|array $ordens, array $op = []): Nfse
    {
        $empresa = Empresa::atual();
        $cfg = ConfigNfse::ler($empresa);
        if ($f = ConfigNfse::faltando($empresa)) {
            throw ValidationException::withMessages(['nfse' => 'Falta configurar: '.implode(', ', $f).' (Configurações › Nota fiscal).']);
        }
        if ($cfg['ibs_cbs']) {
            // o grupo IBS/CBS da DPS entra quando for conferido no leiaute oficial em vigor (docs/16-nfse.md)
            throw ValidationException::withMessages(['nfse' => 'Com "Preencher as informações IBS/CBS" em Sim, a emissão por aqui ainda não está pronta. Emita pelo Emissor Nacional ou volte para Não.']);
        }
        $ids = collect($ordens)->map(fn ($o) => $o instanceof OrdemServico ? $o->id : (int) $o)->unique()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['nfse' => 'Escolha ao menos um serviço.']);
        }
        $competencia = Dps::competencia();
        $aliq = ConfigNfse::aliquotas($competencia, $empresa);
        if (! $aliq) {
            throw ValidationException::withMessages(['nfse' => 'Falta informar as alíquotas de '.Carbon::parse($competencia.'-01')->format('m/Y').' (do e-mail da contabilidade), no alto da página Notas fiscais.']);
        }
        $retido = (bool) ($op['retido'] ?? false);
        $identificar = (bool) ($op['identificar'] ?? true);
        // retenção federal: o cliente diz se retém; o tipo e as alíquotas são os da configuração da empresa
        $fed = ! empty($op['retem_federal']) ? ConfigNfse::retencaoConfigurada($cfg) : ['tipo' => '0', 'irrf' => false, 'cp' => false];
        $i = ConfigNfse::integracao();
        $api = new SefinNacional($i);
        $serie = (string) (int) $cfg['serie'];

        $nota = DB::transaction(function () use ($ids, $op, $empresa, $cfg, $api, $serie, $i, $aliq, $retido, $identificar, $fed, $competencia) {
            $lista = self::semNota()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($lista->count() !== $ids->count()) {
                throw ValidationException::withMessages(['nfse' => 'Algum serviço já tem nota ou foi cancelado. Atualize a página.']);
            }
            if ($lista->pluck('cliente_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['nfse' => 'Uma nota é de um cliente só.']);
            }
            $cliente = $lista->first()->cliente_id ? Cliente::find($lista->first()->cliente_id) : null;
            $numero = (int) Nfse::where('serie', $serie)->lockForUpdate()->max('numero_dps') + 1;
            $dps = Dps::montar($cliente, $lista, $empresa, $cfg, $api->ambiente(), $numero, $aliq,
                ['retido' => $retido, 'identificar' => $identificar, 'fed' => $fed, 'avulso' => $op['avulso'] ?? []]);
            $xml = AssinaturaXml::assinar($dps['xml'], $i->credencial('certificado'), $i->credencial('chave'));

            $nota = Nfse::create([
                'ambiente' => $api->ambiente(), 'serie' => $serie, 'numero_dps' => $numero, 'id_dps' => $dps['id'],
                'valor' => $dps['valor'], 'tomador_documento' => $dps['tomador_documento'], 'tomador_nome' => $dps['tomador_nome'],
                'descricao' => $dps['descricao'], 'xml_dps' => $xml, 'criado_por' => Auth::id(), 'competencia' => $competencia,
                'cliente_id' => $cliente?->id, 'tomador_identificado' => $identificar,
                'iss_retido' => $retido, 'aliquota_iss' => $retido ? $aliq['iss'] : null, 'valor_iss' => $dps['valor_iss'], 'p_tot_trib' => $aliq['tributos'],
                'ret_federal' => $fed['tipo'], 'ret_irrf' => $fed['irrf'], 'ret_cp' => $fed['cp'], 'valor_ret_federal' => $dps['valor_ret_federal'] ?: null,
            ]);
            OrdemServico::whereIn('id', $ids)->update(['nfse_id' => $nota->id]);

            return $nota;
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
        OrdemServico::where('nfse_id', $nota->id)->update(['nfse_id' => null]);   // os serviços voltam a ficar sem nota
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
        OrdemServico::where('nfse_id', $nota->id)->update(['nfse_id' => null]);   // os serviços voltam para a lista
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
