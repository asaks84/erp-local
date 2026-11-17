import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import { data as fData, dataHora } from '../../lib/formato'
import { telefone } from '../../lib/mascaras'
import { usePode } from '../../lib/permissoes'
import { abrirWhatsApp } from '../../lib/whatsapp'

const SITUACAO = { enviado: ['Enviado', ''], entregue: ['Entregue', 'selo-ok'], lido: ['Lido', 'selo-ok'], falha: ['Falhou', 'selo-erro'], pendente: ['Para enviar', 'selo-aviso'] }

/** Cobranças › Avisos: avisos de cobrança do dia pelo WhatsApp (manual pelo wa.me ou pela API) e os já enviados. */
export default function Avisos({ pendentes, enviados, api, automatico, hora, passos }) {
    const pode = usePode()
    const [ocupado, setOcupado] = useState(null)
    const podem = pendentes.filter((a) => !a.impedimento)
    const post = (url, id = 'todos') => { setOcupado(id); router.post(url, {}, { preserveScroll: true, onFinish: () => setOcupado(null) }) }
    const manual = (a) => {
        abrirWhatsApp(a.celular, a.texto)
        post(`/avisos/${a.id}/manual`, a.id)
    }
    const regua = passos.map((d) => (d < 0 ? `${-d} dia(s) antes` : d === 0 ? 'no dia' : `${d} dia(s) depois`)).join(', ')

    return (
        <div className="pagina">
            <TituloPagina titulo="Avisos de cobrança" descricao={`WhatsApp · régua: ${regua || 'nenhum passo ligado'}`}>
                {pode('configuracoes') && <Link href="/configuracoes/whatsapp" className="btn btn-outline-secondary">Configurar</Link>}
                {api && podem.length > 0 && <button type="button" className="btn btn-primary" disabled={ocupado === 'todos'} onClick={() => post('/avisos/enviar-todos')}>{ocupado === 'todos' ? 'Enviando…' : `Enviar todos (${podem.length})`}</button>}
            </TituloPagina>
            <div className="alert aviso-neutro small">
                {api
                    ? (automatico ? `Envio automático ligado: os avisos do dia saem sozinhos a partir das ${hora}. Aqui ficam os que ainda vão sair e os que falharam.` : 'A API está ligada, mas o envio automático não: envie por aqui.')
                    : 'Envio manual: "Abrir WhatsApp" abre a conversa com o texto pronto; é só apertar enviar. O aviso fica marcado como enviado.'}
            </div>

            <div className="card mb-4">
                <div className="card-header">Para enviar ({pendentes.length})</div>
                {pendentes.length === 0 ? <div className="card-body text-muted small">Nenhum aviso para hoje.</div> : (
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead><tr><th>Cliente</th><th>Aviso</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end" /></tr></thead>
                            <tbody>
                                {pendentes.map((a) => (
                                    <tr key={a.id}>
                                        <td>
                                            {a.cliente ? <Link href={`/clientes/${a.cliente.id}`} className="fw-semibold text-body text-decoration-none">{a.cliente.nome}</Link> : '—'}
                                            <div className="small text-muted">{a.celular ? telefone(a.celular) : 'sem celular válido'}{a.cobranca && <> · <Link href={`/cobrancas/${a.cobranca.id}`}>cobrança {a.cobranca.numero}</Link></>}</div>
                                        </td>
                                        <td>
                                            <div className="small">{a.rotulo}</div>
                                            {a.situacao === 'falha' && <div className="small text-danger-emphasis">Falhou: {a.erro}</div>}
                                            {a.situacao !== 'falha' && a.impedimento && <div className="small text-danger-emphasis">{a.impedimento}</div>}
                                        </td>
                                        <td className="d-none d-md-table-cell text-nowrap">{fData(a.vencimento)}</td>
                                        <td className="text-end text-nowrap">
                                            {!a.impedimento && a.celular && <button type="button" className="btn btn-sm btn-success me-1" disabled={ocupado === a.id} onClick={() => manual(a)}>Abrir WhatsApp</button>}
                                            {!a.impedimento && api && <button type="button" className="btn btn-sm btn-outline-primary me-1" disabled={ocupado === a.id} onClick={() => post(`/avisos/${a.id}/enviar`, a.id)}>Enviar pela API</button>}
                                            <button type="button" className="btn btn-sm btn-link text-muted p-0" onClick={() => post(`/avisos/${a.id}/dispensar`, a.id)} title="Não enviar este aviso">Dispensar</button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <div className="card">
                <div className="card-header">Enviados</div>
                {enviados.length === 0 ? <div className="card-body text-muted small">Nenhum aviso enviado ainda.</div> : (
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead><tr><th className="d-none d-sm-table-cell">Quando</th><th>Cliente</th><th className="d-none d-md-table-cell">Aviso</th><th>Situação</th><th className="text-end" /></tr></thead>
                            <tbody>
                                {enviados.map((a) => (
                                    <tr key={a.id} title={a.texto}>
                                        <td className="text-nowrap d-none d-sm-table-cell">{dataHora(a.enviado_em)}</td>
                                        <td>{a.cliente ? <Link href={`/clientes/${a.cliente.id}`}>{a.cliente.nome}</Link> : '—'}<div className="small text-muted d-sm-none">{dataHora(a.enviado_em)}</div></td>
                                        <td className="d-none d-md-table-cell small">{a.rotulo}</td>
                                        <td><span className={`selo ${(SITUACAO[a.situacao] || [])[1]}`}>{(SITUACAO[a.situacao] || [a.situacao])[0]}</span> <span className="small text-muted">{a.canal === 'api' ? 'pela API' : 'manual'}</span></td>
                                        <td className="text-end text-nowrap">
                                            {a.impedimento ? <span className="small text-muted" title={a.impedimento}>Não dá para reenviar</span> : (
                                                <>
                                                    {a.celular && <button type="button" className="btn btn-sm btn-outline-success me-1" disabled={ocupado === a.id} onClick={() => manual(a)} title="Abrir o WhatsApp com o texto de novo">Reenviar</button>}
                                                    {api && <button type="button" className="btn btn-sm btn-outline-primary" disabled={ocupado === a.id} onClick={() => post(`/avisos/${a.id}/enviar`, a.id)}>Reenviar pela API</button>}
                                                </>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    )
}
