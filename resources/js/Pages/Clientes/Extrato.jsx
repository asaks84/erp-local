import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import CampoData from '../../Components/CampoData'
import Icone from '../../Components/Icone'
import { data as fData, moeda } from '../../lib/formato'

export default function ExtratoCliente({ cliente, filtros, anterior, linhas, saldo_final }) {
    const [f, setF] = useState(filtros)
    const filtrar = (novo) => {
        const x = { ...f, ...novo }
        setF(x)
        if (x.de && x.ate) router.get(`/clientes/${cliente.id}/extrato`, x, { preserveState: true, replace: true })
    }
    return (
        <div className="pagina extrato-conta" style={{ maxWidth: 1000 }}>
            <TituloPagina titulo="Extrato da conta" descricao={`${cliente.nome} · cód. ${cliente.id}${cliente.documento ? ` · ${cliente.documento}` : ''}`}>
                <button type="button" className="btn btn-outline-secondary d-inline-flex align-items-center gap-2 nao-imprimir" onClick={() => window.print()}><Icone nome="impressora" tamanho={16} /> Imprimir</button>
                <Link href={`/clientes/${cliente.id}`} className="btn btn-outline-secondary nao-imprimir">Voltar ao cliente</Link>
            </TituloPagina>
            <div className="row g-2 nao-imprimir">
                <div className="col-6 col-md-3"><CampoData rotulo="De" value={f.de} onChange={(v) => filtrar({ de: v })} /></div>
                <div className="col-6 col-md-3"><CampoData rotulo="Até" value={f.ate} onChange={(v) => filtrar({ ate: v })} /></div>
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Data</th><th>Descrição</th><th className="text-end">Lançado</th><th className="text-end">Baixado</th><th className="text-end">Saldo</th></tr></thead>
                        <tbody>
                            <tr className="table-light"><td>{fData(f.de)}</td><td className="fw-semibold">Saldo anterior</td><td /><td /><td className="text-end fw-semibold text-nowrap">{moeda(anterior)}</td></tr>
                            {linhas.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum movimento no período.</td></tr>}
                            {linhas.map((l, i) => (
                                <tr key={i}>
                                    <td className="text-nowrap">{fData(l.data)}</td>
                                    <td>{l.descricao}</td>
                                    <td className="text-end text-nowrap">{l.valor > 0 ? moeda(l.valor) : ''}</td>
                                    <td className="text-end text-nowrap text-success">{l.valor < 0 ? moeda(-l.valor) : ''}</td>
                                    <td className="text-end text-nowrap">{moeda(l.saldo)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr><td colSpan="4" className="text-end fw-semibold">{saldo_final >= 0 ? 'Em aberto' : 'Crédito do cliente'}</td><td className="text-end fw-bold text-nowrap">{moeda(Math.abs(saldo_final))}</td></tr></tfoot>
                    </table>
                </div>
            </div>
            <p className="small text-muted mt-2">"Lançado" é o que entrou na conta (mensalidades, serviços, débitos); "Baixado" é o que saiu (pagamentos, créditos, cancelamentos).</p>
        </div>
    )
}
