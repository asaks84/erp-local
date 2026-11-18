import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasEstadias from '../../Components/AbasEstadias'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Campo from '../../Components/Campo'
import { dataHora, moeda } from '../../lib/formato'

const SITUACOES = [['todas', 'Todas'], ['finalizada', 'Finalizadas'], ['cancelada', 'Canceladas'], ['patio', 'No pátio']]

function Total({ rotulo, valor, sub }) {
    return <div className="col-6 col-md-3"><div className="card h-100"><div className="card-body py-2"><div className="small text-muted">{rotulo}</div><div className="fs-5 fw-semibold">{valor}</div>{sub && <div className="small text-muted">{sub}</div>}</div></div></div>
}

export default function Historico({ estadias, filtros, totais }) {
    const [f, setF] = useState(filtros)
    const primeira = useRef(true)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => router.get('/estadias/historico', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300)
        return () => clearTimeout(t)
    }, [f])

    return (
        <div className="pagina">
            <AbasEstadias />
            <TituloPagina titulo="Histórico de estadias" descricao="Pela data de entrada. Os totais são de tudo o que o filtro pega, não só desta página." />
            <div className="row g-2 mb-1">
                <div className="col-md-4"><input type="search" className="form-control" style={{ height: 58 }} placeholder="Placa, modelo ou ticket" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} aria-label="Buscar" /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="De" value={f.de} onChange={(v) => setF({ ...f, de: v })} /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="Até" value={f.ate} onChange={(v) => setF({ ...f, ate: v })} /></div>
                <div className="col-md-4">
                    <Campo as="select" rotulo="Situação" value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value })}>
                        {SITUACOES.map(([k, r]) => <option key={k} value={k}>{r}</option>)}
                    </Campo>
                </div>
            </div>
            <div className="row g-2 mb-3">
                <Total rotulo="Estadias" valor={totais.qtd} sub={[totais.finalizadas && `${totais.finalizadas} finalizada(s)`, totais.patio && `${totais.patio} no pátio`, totais.canceladas && `${totais.canceladas} cancelada(s)`].filter(Boolean).join(' · ')} />
                <Total rotulo="Total cobrado" valor={moeda(totais.cobrado)} sub="das finalizadas" />
                <Total rotulo="Pago na hora" valor={moeda(totais.na_hora)} sub={totais.na_conta > 0 ? `na conta: ${moeda(totais.na_conta)}` : 'inclui diárias pagas na entrada'} />
                <Total rotulo="Convênios" valor={moeda(totais.convenio)} />
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Ticket</th><th>Veículo</th><th>Entrada</th><th className="d-none d-md-table-cell">Saída</th><th className="text-end">Valor</th><th>Como ficou</th></tr></thead>
                        <tbody>
                            {estadias.data.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhuma estadia no período.</td></tr>}
                            {estadias.data.map((e) => (
                                <tr key={e.id} className={e.situacao === 'cancelada' ? 'text-muted' : undefined}>
                                    <td className="text-muted">{e.numero}</td>
                                    <td>
                                        <strong>{e.rotulo}</strong>{e.modelo && <span className="small"> · {e.modelo}</span>}
                                        <div className="small text-muted">{e.cliente ? e.cliente.nome : 'Avulso'}{e.tipo ? ` · ${e.tipo}` : ''}{e.convenio ? ` · convênio ${e.convenio.nome}` : ''}{e.pdv ? ' · PDV' : ''}</div>
                                        {e.diaria && <span className={`selo ${e.pago_entrada > 0 ? 'selo-ok' : ''}`}>{e.diaria.nome}{e.pago_entrada > 0 ? ' paga na entrada' : ''}</span>}
                                        {e.retirada && <div className="small text-muted">Ticket perdido · retirou {e.retirada}</div>}
                                        {e.situacao === 'cancelada' && e.motivo_cancelamento && <div className="small text-muted">Cancelada: {e.motivo_cancelamento}</div>}
                                    </td>
                                    <td className="text-nowrap">{dataHora(e.entrada)}</td>
                                    <td className="d-none d-md-table-cell text-nowrap">{e.saida ? dataHora(e.saida) : '—'}{e.saida && <div className="small text-muted">{e.tempo}</div>}</td>
                                    <td className="text-end text-nowrap">{e.valor_calculado !== null ? moeda(e.valor_calculado) : '—'}{e.valor_convenio > 0 && <div className="small text-muted">convênio {moeda(e.valor_convenio)}</div>}</td>
                                    <td>{e.situacao === 'patio' && <span className="selo selo-aviso">No pátio</span>}{e.situacao === 'cancelada' && <span className="selo" title={e.motivo_cancelamento || undefined}>Cancelada</span>}{e.situacao === 'finalizada' && <span className={`selo ${e.destino === 'pago' ? 'selo-ok' : ''}`}>{e.destino_rotulo}</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Paginacao pagina={estadias} />
        </div>
    )
}
