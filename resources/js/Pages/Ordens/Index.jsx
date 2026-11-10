import { Link, router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import { SeloOrdem, TIPOS_ORDEM, ValorOrdem, descricaoOrdem } from '../../Components/Dinheiro'
import { data as fData } from '../../lib/formato'

export default function OrdensIndex({ ordens, filtros }) {
    const [f, setF] = useState(filtros)
    const primeira = useRef(true)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => {
            const limpo = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
            router.get('/ordens', limpo, { preserveState: true, replace: true })
        }, 300)
        return () => clearTimeout(t)
    }, [f])
    const muda = (k) => (e) => setF((x) => ({ ...x, [k]: e?.target ? e.target.value : e }))

    return (
        <div className="pagina">
            <TituloPagina titulo="Ordens de serviço" descricao="Tudo o que foi lançado na conta dos clientes: mensalidades, lavagens, estadias, débitos e créditos." />
            <div className="row g-2 mb-1">
                <div className="col-md-4"><input type="search" className="form-control" placeholder="Número, placa ou cliente" value={f.q} onChange={muda('q')} aria-label="Buscar" /></div>
                <div className="col-6 col-md-2">
                    <select className="form-select" value={f.tipo} onChange={muda('tipo')} aria-label="Tipo">
                        <option value="">Todos os tipos</option>
                        {Object.entries(TIPOS_ORDEM).map(([k, r]) => <option key={k} value={k}>{r}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-2">
                    <select className="form-select" value={f.situacao} onChange={muda('situacao')} aria-label="Situação">
                        <option value="">Todas</option><option value="aberta">Em aberto</option><option value="paga">Pagas</option><option value="cancelada">Canceladas</option>
                    </select>
                </div>
                <div className="col-6 col-md-2"><CampoData rotulo="De" value={f.de} onChange={muda('de')} className="mb-2" /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="Até" value={f.ate} onChange={muda('ate')} className="mb-2" /></div>
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Descrição</th><th className="text-end">Valor</th><th>Situação</th></tr></thead>
                        <tbody>
                            {ordens.data.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhuma ordem encontrada.</td></tr>}
                            {ordens.data.map((o) => (
                                <tr key={o.id}>
                                    <td className="text-muted">{o.numero}</td>
                                    <td className="text-nowrap">{fData(o.data)}</td>
                                    <td>{o.cliente ? <Link href={`/clientes/${o.cliente.id}`}>{o.cliente.nome}</Link> : <span className="text-muted">sem cliente</span>}</td>
                                    <td>{descricaoOrdem(o)}{o.placa && <div className="small text-muted">{o.placa}</div>}</td>
                                    <td className="text-end"><ValorOrdem o={o} /></td>
                                    <td><SeloOrdem o={o} />{o.situacao === 'cancelada' && o.motivo_cancelamento && <div className="small text-muted">{o.motivo_cancelamento}</div>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Paginacao pagina={ordens} />
        </div>
    )
}
