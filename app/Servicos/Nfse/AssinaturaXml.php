<?php

namespace App\Servicos\Nfse;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Assinatura digital XML (XMLDSig, envelopada) com o certificado A1, como o Sistema Nacional da NFS-e pede:
 * RSA-SHA256, digest SHA-256, canonicalização exclusiva, assinatura irmã do elemento assinado (infDPS ou
 * infPedReg), referenciando o Id dele.
 */
class AssinaturaXml
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    private const C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';

    public static function assinar(string $xml, string $certificadoPem, string $chavePem, string $elemento = 'infDPS'): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        if (! $doc->loadXML($xml)) {
            throw new RuntimeException('XML inválido para assinar.');
        }
        /** @var DOMElement|null $alvo */
        $alvo = $doc->getElementsByTagName($elemento)->item(0);
        if (! $alvo || ! $alvo->getAttribute('Id')) {
            throw new RuntimeException("Elemento {$elemento} com Id não encontrado.");
        }
        $id = $alvo->getAttribute('Id');
        $alvo->setIdAttribute('Id', true);

        $digest = base64_encode(hash('sha256', $alvo->C14N(true, true), true));

        $sig = $doc->createElementNS(self::DS, 'Signature');
        $alvo->parentNode->appendChild($sig);
        $si = $doc->createElementNS(self::DS, 'SignedInfo');
        $sig->appendChild($si);
        $si->appendChild(self::alg($doc, 'CanonicalizationMethod', self::C14N));
        $si->appendChild(self::alg($doc, 'SignatureMethod', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'));
        $ref = $doc->createElementNS(self::DS, 'Reference');
        $ref->setAttribute('URI', '#'.$id);
        $si->appendChild($ref);
        $tr = $doc->createElementNS(self::DS, 'Transforms');
        $ref->appendChild($tr);
        $tr->appendChild(self::alg($doc, 'Transform', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature'));
        $tr->appendChild(self::alg($doc, 'Transform', self::C14N));
        $ref->appendChild(self::alg($doc, 'DigestMethod', 'http://www.w3.org/2001/04/xmlenc#sha256'));
        $ref->appendChild($doc->createElementNS(self::DS, 'DigestValue', $digest));

        $chave = openssl_pkey_get_private($chavePem);
        if (! $chave || ! openssl_sign($si->C14N(true, true), $assinatura, $chave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não deu para assinar com a chave do certificado.');
        }
        $sig->appendChild($doc->createElementNS(self::DS, 'SignatureValue', base64_encode($assinatura)));
        $ki = $doc->createElementNS(self::DS, 'KeyInfo');
        $sig->appendChild($ki);
        $x509 = $doc->createElementNS(self::DS, 'X509Data');
        $ki->appendChild($x509);
        $x509->appendChild($doc->createElementNS(self::DS, 'X509Certificate', self::semCabecalho($certificadoPem)));

        return $doc->saveXML($doc->documentElement);
    }

    /** confere a assinatura (usado nos testes e para validar antes de enviar) */
    public static function confere(string $xmlAssinado, string $certificadoPem, string $elemento = 'infDPS'): bool
    {
        $doc = new DOMDocument;
        $doc->loadXML($xmlAssinado);
        $alvo = $doc->getElementsByTagName($elemento)->item(0);
        $si = $doc->getElementsByTagNameNS(self::DS, 'SignedInfo')->item(0);
        $digest = $doc->getElementsByTagNameNS(self::DS, 'DigestValue')->item(0)?->textContent;
        $valor = $doc->getElementsByTagNameNS(self::DS, 'SignatureValue')->item(0)?->textContent;
        if (! $alvo || ! $si || ! $digest || ! $valor) {
            return false;
        }
        if (base64_encode(hash('sha256', $alvo->C14N(true, true), true)) !== $digest) {
            return false;
        }

        return openssl_verify($si->C14N(true, true), base64_decode($valor), $certificadoPem, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function alg(DOMDocument $d, string $nome, string $algoritmo): DOMElement
    {
        $e = $d->createElementNS(self::DS, $nome);
        $e->setAttribute('Algorithm', $algoritmo);

        return $e;
    }

    private static function semCabecalho(string $pem): string
    {
        return preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
    }
}
