import { Head } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { data as fData, moeda } from '../../lib/formato'

function Copiar({ texto, rotulo }) {
    const [ok, setOk] = useState(false)
    return <button type="button" className="btn btn-primary w-100 py-2" onClick={() => navigator.clipboard?.writeText(texto).then(() => { setOk(true); setTimeout(() => setOk(false), 2000) })}>{ok ? 'Copiado!' : rotulo}</button>
}

/** Página pública da cobrança (link dos avisos do WhatsApp): o que deve e como pagar. */
export default function CobrancaPublica({ empresa_publica: empresa, cobranca: c, boleto, pontualidade = null }) {
    const [qr, setQr] = useState(null)
    useEffect(() => { if (boleto?.pix_copia_cola) QRCode.toDataURL(boleto.pix_copia_cola, { width: 440, margin: 1 }).then(setQr) }, [boleto?.pix_copia_cola])
    const aberta = c.situacao === 'aberta' || c.situacao === 'parcial'
    return (
        <div className="container py-4" style={{ maxWidth: 520 }}>
            <Head title={`Cobrança ${c.numero}`} />
            <img className="logo-claro d-block mx-auto mb-3" src="/imagens/logo-local.png" alt={empresa.nome} width="150" height="67" />
            <img className="logo-escuro d-block mx-auto mb-3" src="/imagens/logo-local-escuro.png" alt={empresa.nome} width="150" height="67" />
            <h1 className="fs-4 fw-bold text-center">{c.primeiro_nome ? `Olá, ${c.primeiro_nome}!` : `Cobrança ${c.numero}`}</h1>

            {!aberta && (
                <div className={`alert ${c.situacao === 'paga' ? 'alert-success' : 'aviso-neutro'} text-center`}>
                    {c.situacao === 'paga' ? 'Esta cobrança já está paga. Obrigado!' : `Esta cobrança não está mais em aberto (${c.rotulo_situacao.toLowerCase()}).`}
                </div>
            )}

            {aberta && (
                <div className="card mb-3">
                    <div className="card-body text-center">
                        {pontualidade ? (
                            <>
                                <div className="small text-muted">Pagando até {fData(pontualidade.ate)}</div>
                                <div className="display-6 fw-bold">{moeda(pontualidade.a_pagar)}</div>
                                <div className="small text-success-emphasis mb-1">Desconto de {moeda(pontualidade.valor)} por pagar em dia</div>
                                <div className="small text-muted">Depois de {fData(pontualidade.ate)}: {moeda(c.a_pagar)} · vencimento {fData(c.vencimento)}</div>
                            </>
                        ) : (
                            <>
                                <div className="small text-muted">Valor a pagar</div>
                                <div className="display-6 fw-bold">{moeda(c.a_pagar)}</div>
                                <div>Vencimento <strong>{fData(c.vencimento)}</strong></div>
                            </>
                        )}
                    </div>
                </div>
            )}

            {aberta && boleto?.pix_copia_cola && (
                <div className="card mb-3">
                    <div className="card-header">Pagar com Pix</div>
                    <div className="card-body text-center">
                        {qr && <img src={qr} alt="QR Code do Pix" width="220" height="220" className="mb-3 bg-white p-1 rounded" />}
                        <Copiar texto={boleto.pix_copia_cola} rotulo="Copiar código Pix (copia e cola)" />
                        <p className="small text-muted mt-2 mb-0">No app do seu banco: Pix › Pix copia e cola. A baixa é automática.</p>
                    </div>
                </div>
            )}

            {aberta && boleto?.linha_digitavel && (
                <div className="card mb-3">
                    <div className="card-header">Pagar com boleto</div>
                    <div className="card-body">
                        <code className="d-block text-break mb-2">{boleto.linha_digitavel}</code>
                        <Copiar texto={boleto.linha_digitavel.replace(/\D/g, '')} rotulo="Copiar linha digitável" />
                    </div>
                </div>
            )}

            {aberta && !boleto && (
                <div className="alert aviso-neutro">
                    {c.instrucoes || `Para pagar, fale com o ${empresa.nome}${empresa.telefone ? ` pelo telefone ${empresa.telefone}` : ''}.`}
                </div>
            )}

            <div className="card">
                <div className="card-header">Cobrança {c.numero}</div>
                <ul className="list-group list-group-flush">
                    {c.itens.map((i, n) => (
                        <li className="list-group-item d-flex justify-content-between gap-2" key={n}>
                            <span>{i.descricao}{i.placa && <span className="small text-muted d-block">{i.placa}</span>}</span>
                            <span className="text-nowrap">{moeda(i.valor)}</span>
                        </li>
                    ))}
                    {c.desconto > 0 && <li className="list-group-item d-flex justify-content-between"><span>Desconto</span><span>−{moeda(c.desconto)}</span></li>}
                    <li className="list-group-item d-flex justify-content-between fw-bold"><span>Total</span><span>{moeda(c.total - c.desconto)}</span></li>
                </ul>
            </div>
            <p className="small text-muted text-center mt-3">{empresa.nome}{empresa.telefone ? ` · ${empresa.telefone}` : ''}</p>
        </div>
    )
}
CobrancaPublica.layout = null
