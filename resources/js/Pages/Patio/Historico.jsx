import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPatio from '../../Components/AbasPatio'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Campo from '../../Components/Campo'
import { Como, Entrada, SeloTipo, Servico, Valor, Veiculo } from '../../Components/PatioLinha'
import { moeda } from '../../lib/formato'

const SITUACOES = [['todas', 'Todas'], ['finalizada', 'Finalizadas'], ['cancelada', 'Canceladas'], ['patio', 'No pátio']]
const TIPOS = [['todos', 'Estadias e lavagens'], ['estadia', 'Só estadias'], ['lavagem', 'Só lavagens']]

function Total({ rotulo, valor, sub }) {
    return <div className="col-6 col-md-3"><div className="card h-100"><div className="card-body py-2"><div className="small text-muted">{rotulo}</div><div className="fs-5 fw-semibold">{valor}</div>{sub && <div className="small text-muted">{sub}</div>}</div></div></div>
}

/** Operação › Pátio › Histórico: estadias e lavagens por data de entrada, com os totais do que o filtro pega. */
export default function PatioHistorico({ linhas, filtros, totais }) {
    const [f, setF] = useState(filtros)
    const primeira = useRef(true)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => router.get('/patio/historico', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300)
        return () => clearTimeout(t)
    }, [f])

    return (
        <div className="pagina">
            <AbasPatio />
            <TituloPagina titulo="Histórico do pátio" descricao="Estadias e lavagens pela data de entrada. Os totais são de tudo o que o filtro pega, não só desta página." />
            <div className="row g-2 mb-1">
                <div className="col-md-3"><input type="search" className="form-control" style={{ height: 58 }} placeholder="Placa, modelo ou ticket" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} aria-label="Buscar" /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="De" value={f.de} onChange={(v) => setF({ ...f, de: v })} /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="Até" value={f.ate} onChange={(v) => setF({ ...f, ate: v })} /></div>
                <div className="col-6 col-md-3">
                    <Campo as="select" rotulo="Tipo" value={f.tipo} onChange={(e) => setF({ ...f, tipo: e.target.value })}>
                        {TIPOS.map(([k, r]) => <option key={k} value={k}>{r}</option>)}
                    </Campo>
                </div>
                <div className="col-6 col-md-2">
                    <Campo as="select" rotulo="Situação" value={f.situacao} onChange={(e) => setF({ ...f, situacao: e.target.value })}>
                        {SITUACOES.map(([k, r]) => <option key={k} value={k}>{r}</option>)}
                    </Campo>
                </div>
            </div>
            <div className="row g-2 mb-3">
                <Total rotulo="Estadias" valor={totais.estadias} sub={`${moeda(totais.estadias_valor)} cobrado`} />
                <Total rotulo="Lavagens" valor={totais.lavagens} sub={`${moeda(totais.lavagens_valor)} em serviços`} />
                <Total rotulo="Estadia após lavagem" valor={moeda(totais.estadia_lavagem)} sub="cobrada pela regra da lavagem" />
                <Total rotulo="No pátio / canceladas" valor={`${totais.patio} / ${totais.canceladas}`} />
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Ticket</th><th>Veículo</th><th>O quê</th><th>Entrada</th><th className="text-end">Valor</th><th>Como ficou</th></tr></thead>
                        <tbody>
                            {linhas.data.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nada no período.</td></tr>}
                            {linhas.data.map((l) => (
                                <tr key={l.chave} className={l.situacao === 'cancelada' ? 'text-muted' : undefined}>
                                    <td className="text-muted">{l.numero}<div><SeloTipo tipo={l.tipo} /></div></td>
                                    <td><Veiculo l={l} /></td>
                                    <td><Servico l={l} /></td>
                                    <td><Entrada l={l} comSaida /></td>
                                    <td className="text-end text-nowrap"><Valor l={l} /></td>
                                    <td><Como l={l} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <Paginacao pagina={linhas} />
        </div>
    )
}
