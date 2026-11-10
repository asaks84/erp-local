import { Link, router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import { dataHora, moeda } from '../../lib/formato'

export default function Historico({ estadias, filtros }) {
    const [f, setF] = useState(filtros)
    const primeira = useRef(true)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => router.get('/estadias/historico', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300)
        return () => clearTimeout(t)
    }, [f])

    return (
        <div className="pagina">
            <TituloPagina titulo="Histórico de estadias">
                <Link href="/estadias" className="btn btn-outline-secondary">Voltar ao pátio</Link>
            </TituloPagina>
            <div className="row g-2 mb-1">
                <div className="col-md-4"><input type="search" className="form-control" style={{ height: 58 }} placeholder="Placa ou ticket" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} aria-label="Buscar" /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="De" value={f.de} onChange={(v) => setF({ ...f, de: v })} /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="Até" value={f.ate} onChange={(v) => setF({ ...f, ate: v })} /></div>
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
                                    <td><strong>{e.rotulo}</strong><div className="small text-muted">{e.cliente ? e.cliente.nome : 'Avulso'}{e.convenio ? ` · convênio ${e.convenio.nome}` : ''}</div></td>
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
