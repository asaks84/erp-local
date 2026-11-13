import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import Janela from './Janela'
import Campo from './Campo'
import { data as fData, dataHora, moeda } from '../lib/formato'
import { usePode } from '../lib/permissoes'

const COR = { em_processamento: '', a_receber: 'selo-aviso', atrasado: 'selo-erro', recebido: 'selo-ok', cancelado: '', expirado: '', falha: 'selo-erro' }

function Copiar({ texto, rotulo }) {
    const [ok, setOk] = useState(false)
    const copiar = () => navigator.clipboard?.writeText(texto).then(() => { setOk(true); setTimeout(() => setOk(false), 1500) })
    return <button type="button" className="btn btn-sm btn-outline-secondary text-nowrap" onClick={copiar}>{ok ? 'Copiado' : rotulo}</button>
}

function Ativo({ b }) {
    const [qr, setQr] = useState(null)
    useEffect(() => { if (b.pix_copia_cola) QRCode.toDataURL(b.pix_copia_cola, { width: 360, margin: 1 }).then(setQr) }, [b.pix_copia_cola])
    if (b.situacao === 'em_processamento' && !b.linha_digitavel) {
        return <p className="mb-0 small text-muted">O Inter está gerando o boleto. Clique em Atualizar em alguns segundos.</p>
    }
    return (
        <div className="row g-3 align-items-center">
            <div className="col-md">
                {b.linha_digitavel && (
                    <div className="mb-3">
                        <div className="small text-muted">Linha digitável</div>
                        <div className="d-flex gap-2 align-items-center">
                            <code className="text-break flex-grow-1">{b.linha_digitavel}</code>
                            <Copiar texto={b.linha_digitavel} rotulo="Copiar" />
                        </div>
                    </div>
                )}
                {b.pix_copia_cola && (
                    <div>
                        <div className="small text-muted">Pix copia e cola</div>
                        <div className="d-flex gap-2 align-items-center">
                            <code className="text-break flex-grow-1 small" style={{ maxHeight: '3.2em', overflow: 'hidden' }}>{b.pix_copia_cola}</code>
                            <Copiar texto={b.pix_copia_cola} rotulo="Copiar" />
                        </div>
                    </div>
                )}
            </div>
            {qr && <div className="col-md-auto text-center"><img src={qr} alt="QR Code Pix do boleto" width="140" height="140" /></div>}
        </div>
    )
}

/** Quadro do boleto com Pix (Banco Inter) na tela da cobrança. */
export default function BoletoCobranca({ cobranca, boletos, inter, emAberto, faltaCadastro, clienteId, regrasSugerido = true }) {
    const pode = usePode()
    const [cancelar, setCancelar] = useState(null)
    const [ocupado, setOcupado] = useState(false)
    const [regras, setRegras] = useState(regrasSugerido)
    const form = useForm({ motivo: '' })
    const atual = boletos.find((b) => b.ativo) || null
    const anteriores = boletos.filter((b) => b !== atual)
    const podeGerar = inter && !atual && ['aberta', 'parcial'].includes(cobranca.situacao) && emAberto >= 2.5
    const acao = (url, dados = {}) => { setOcupado(true); router.post(url, dados, { preserveScroll: true, onFinish: () => setOcupado(false) }) }

    if (!inter && boletos.length === 0) return null

    return (
        <div className="card mb-3">
            <div className="card-header d-flex flex-wrap align-items-center gap-2">
                <span className="flex-grow-1">Boleto com Pix (Banco Inter)</span>
                {atual && <span className={`selo ${COR[atual.situacao]}`}>{atual.situacao_nome}</span>}
                {atual && <button type="button" className="btn btn-sm btn-outline-secondary" disabled={ocupado} onClick={() => acao(`/boletos/${atual.id}/atualizar`)}>Atualizar</button>}
                {atual?.tem_pdf && <a className="btn btn-sm btn-outline-secondary" href={`/boletos/${atual.id}/pdf`} target="_blank" rel="noopener">PDF</a>}
                {atual && pode('contas_editar') && <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => { form.reset(); setCancelar(atual) }}>Cancelar boleto</button>}
            </div>
            <div className="card-body">
                {atual && (
                    <>
                        <p className="small text-muted">{moeda(atual.valor)} · vence {fData(atual.vencimento)} · nº {atual.seu_numero}{atual.sem_regras && ' · sem multa, juros e desconto'}{atual.consultado_em && ` · consultado ${dataHora(atual.consultado_em)}`}</p>
                        <Ativo b={atual} />
                    </>
                )}
                {!atual && podeGerar && faltaCadastro.length > 0 && (
                    <div className="alert alert-warning mb-0">Para gerar o boleto, o Inter exige {faltaCadastro.join(', ')} no cadastro. <Link href={`/clientes/${clienteId}/editar`}>Completar cadastro</Link></div>
                )}
                {!atual && podeGerar && faltaCadastro.length === 0 && (
                    <>
                        <div className="d-flex flex-wrap align-items-center gap-3">
                            <span className="small text-muted flex-grow-1">Gera um boleto de {moeda(emAberto)} com vencimento em {fData(cobranca.vencimento)}. Quando for pago, a baixa é automática.</span>
                            <button type="button" className="btn btn-primary" disabled={ocupado} onClick={() => acao(`/cobrancas/${cobranca.id}/boleto`, { regras })}>{ocupado ? 'Gerando…' : 'Gerar boleto'}</button>
                        </div>
                        <div className="form-check mt-2 mb-0">
                            <input className="form-check-input" type="checkbox" id="bol-regras" checked={regras} onChange={(e) => setRegras(e.target.checked)} />
                            <label className="form-check-label small" htmlFor="bol-regras">Aplicar as regras do boleto (multa, juros e desconto)</label>
                            {!regrasSugerido && <div className="form-text">Desmarcado porque a cobrança tem a primeira mensalidade (ou uma proporcional).</div>}
                        </div>
                    </>
                )}
                {!atual && !podeGerar && boletos.length === 0 && <p className="small text-muted mb-0">Sem boleto.</p>}
                {anteriores.length > 0 && (
                    <ul className="list-unstyled small mb-0 mt-3">
                        {anteriores.map((b) => (
                            <li key={b.id} className="border-top pt-2 mt-2">
                                <span className={`selo ${COR[b.situacao]} me-2`}>{b.situacao_nome}</span>
                                Nº {b.seu_numero} · {moeda(b.valor)}
                                {b.situacao === 'recebido' && b.valor_recebido !== null && <> · pago {moeda(b.valor_recebido)} em {fData(b.recebido_em)}{b.recebido_por === 'PIX' ? ' (Pix)' : ''}</>}
                                {b.motivo_cancelamento && <> · {b.motivo_cancelamento}</>}
                                {b.erro && <div className="text-danger-emphasis">{b.erro}</div>}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Janela aberta={!!cancelar} titulo="Cancelar boleto" perigo rotuloOk="Cancelar boleto" onFechar={() => setCancelar(null)}
                onEnviar={() => form.post(`/boletos/${cancelar.id}/cancelar`, { preserveScroll: true, onSuccess: () => setCancelar(null) })} ocupado={form.processing}>
                <p>O boleto é cancelado no Inter e não pode mais ser pago. A cobrança continua em aberto.</p>
                <Campo rotulo="Motivo" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo || form.errors.boleto} maxLength={50} autoFocus />
            </Janela>
        </div>
    )
}
