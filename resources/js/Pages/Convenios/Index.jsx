import { Link } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'

export default function ConveniosIndex({ convenios }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Convênios" descricao="Empresas ou clientes que pagam (tudo, até um tempo, ou com desconto) pelo uso de quem eles indicam.">
                <Link href="/convenios/novo" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Novo convênio</Link>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead><tr><th>Conveniado</th><th>Regra</th><th>Vale para</th><th>Situação</th><th /></tr></thead>
                        <tbody>
                            {convenios.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum convênio.</td></tr>}
                            {convenios.map((c) => (
                                <tr key={c.id} className={c.ativo ? undefined : 'inativo'}>
                                    <td><Link href={`/clientes/${c.cliente_id}`} className="fw-semibold">{c.cliente}</Link></td>
                                    <td>{c.resumo}</td>
                                    <td className="small">{c.vale}</td>
                                    <td>{c.ativo ? <span className="selo selo-ok">Ativo</span> : <span className="selo">Inativo</span>}</td>
                                    <td className="text-end"><Link href={`/convenios/${c.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar convênio de ${c.cliente}`}><Icone nome="lapis" tamanho={16} /></Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
