import { Link, router } from '@inertiajs/react'
import { moeda } from '../../lib/formato'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'

export default function ConveniosIndex({ convenios }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Convênios" descricao="Empresas ou clientes que pagam pelo uso de quem eles indicam: por serviço, tudo, até um valor ou até um tempo.">
                <Link href="/convenios/novo" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Novo convênio</Link>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead><tr><th>Conveniado</th><th>O que paga</th><th>Fechamento</th><th>Situação</th><th /></tr></thead>
                        <tbody>
                            {convenios.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum convênio.</td></tr>}
                            {convenios.map((c) => (
                                <tr key={c.id} className={c.ativo ? undefined : 'inativo'}>
                                    <td><Link href={`/clientes/${c.cliente_id}`} className="fw-semibold">{c.cliente}</Link></td>
                                    <td className="small">{c.reconfigurar ? <span className="selo selo-aviso">Reconfigurar</span> : c.resumo}</td>
                                    <td className="small">
                                        {c.fechamento}
                                        {c.fechar && (
                                            <div className="mt-1 d-flex flex-wrap align-items-center gap-2">
                                                <span className="selo selo-aviso">Fechar: {c.fechar.itens} conta(s), {moeda(c.fechar.total)}</span>
                                                <button type="button" className="btn btn-sm btn-outline-dark" onClick={() => router.post(`/convenios/${c.id}/fechar`)}>Gerar cobrança</button>
                                            </div>
                                        )}
                                    </td>
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
