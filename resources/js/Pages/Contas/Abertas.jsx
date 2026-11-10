import { Link, router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import { data as fData, moeda } from '../../lib/formato'
import { telefone } from '../../lib/mascaras'

export default function Abertas({ linhas, resumo, filtros }) {
    const [q, setQ] = useState(filtros.q)
    const primeira = useRef(true)
    const ir = (mudar) => router.get('/contas/abertas', { q: q || undefined, ver: filtros.ver !== 'todas' ? filtros.ver : undefined, ...mudar }, { preserveState: true, replace: true })

    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => ir({}), 300)
        return () => clearTimeout(t)
    }, [q]) // eslint-disable-line react-hooks/exhaustive-deps

    const csv = `/contas/abertas?csv=1${filtros.ver === 'vencidas' ? '&ver=vencidas' : ''}${q ? '&q=' + encodeURIComponent(q) : ''}`
    return (
        <div className="pagina">
            <TituloPagina titulo="Contas em aberto">
                <a href={csv} className="btn btn-outline-secondary">Baixar planilha</a>
            </TituloPagina>

            <div className="row g-3 mb-3">
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Em aberto</div><div className="fs-4 fw-bold">{moeda(resumo.total)}</div></div></div>
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Já venceu</div><div className="fs-4 fw-bold text-danger">{moeda(resumo.vencido)}</div></div></div>
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Clientes devendo</div><div className="fs-4 fw-bold">{resumo.clientes}</div></div></div>
                <div className="col-6 col-md-3"><div className="card card-body"><div className="text-muted small">Com conta vencida</div><div className="fs-4 fw-bold">{resumo.inadimplentes}</div></div></div>
            </div>

            <div className="row g-2 mb-3">
                <div className="col-md-8">
                    <input type="search" className="form-control" placeholder="Cliente, código ou placa" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar" />
                </div>
                <div className="col-md-4">
                    <div className="btn-group w-100" role="group" aria-label="Mostrar">
                        {[['todas', 'Todas'], ['vencidas', 'Só vencidas']].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${filtros.ver === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => ir({ ver: v !== 'todas' ? v : undefined })}>{r}</button>
                        ))}
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Cliente</th><th className="d-none d-lg-table-cell">Itens</th><th className="d-none d-md-table-cell">Mais antigo</th><th className="text-end">Vencido</th><th className="text-end">Total</th><th className="d-none d-md-table-cell" /></tr></thead>
                        <tbody>
                            {linhas.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhuma conta em aberto.</td></tr>}
                            {linhas.map((l) => (
                                <tr key={l.id} role="link" style={{ cursor: 'pointer' }} onClick={() => router.visit(`/clientes/${l.id}`)}>
                                    <td>
                                        <div className="fw-semibold">{l.nome}</div>
                                        <div className="small text-muted">{[`Cód. ${l.id}`, l.placas, telefone(l.contato)].filter(Boolean).join(' · ')}</div>
                                    </td>
                                    <td className="d-none d-lg-table-cell small">{l.itens}</td>
                                    <td className="d-none d-md-table-cell text-nowrap">{fData(l.mais_antigo)}{l.dias > 0 && <div className="small text-danger">{l.dias} dia(s) de atraso</div>}</td>
                                    <td className="text-end text-nowrap">{l.vencido > 0 ? <span className="text-danger">{moeda(l.vencido)}</span> : '—'}</td>
                                    <td className="text-end text-nowrap fw-semibold">{moeda(l.total)}</td>
                                    <td className="text-end d-none d-md-table-cell"><Link href={`/clientes/${l.id}/receber`} className="btn btn-sm btn-outline-primary" onClick={(e) => e.stopPropagation()}>Receber</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
