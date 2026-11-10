import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import { dataHora } from '../../lib/formato'

export default function AtividadesIndex({ atividades, filtros, usuarios }) {
    const [usuario, setUsuario] = useState(filtros.usuario || '')
    const [busca, setBusca] = useState(filtros.busca || '')

    function filtrar(e) {
        e.preventDefault()
        router.get('/atividades', { usuario: usuario || undefined, busca: busca || undefined }, { preserveState: true })
    }

    return (
        <div className="pagina">
            <TituloPagina titulo="Registro de atividades" descricao="Quem fez o quê no sistema: acessos, alterações de cadastro, permissões e dinheiro." />
            <form onSubmit={filtrar} className="row g-2 mb-3">
                <div className="col-sm-4">
                    <select className="form-select" value={usuario} onChange={(e) => setUsuario(e.target.value)} aria-label="Usuário">
                        <option value="">Todos os usuários</option>
                        {usuarios.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                    </select>
                </div>
                <div className="col-sm-5">
                    <input className="form-control" type="search" placeholder="Buscar na ação ou no detalhe" value={busca}
                        onChange={(e) => setBusca(e.target.value)} aria-label="Buscar" />
                </div>
                <div className="col-sm-3 d-grid">
                    <button className="btn btn-outline-secondary" type="submit">Filtrar</button>
                </div>
            </form>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle">
                        <thead><tr><th>Quando</th><th>Usuário</th><th>Ação</th><th>Detalhe</th><th className="d-none d-md-table-cell">IP</th></tr></thead>
                        <tbody>
                            {atividades.data.length === 0 && (
                                <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum registro.</td></tr>
                            )}
                            {atividades.data.map((a) => (
                                <tr key={a.id}>
                                    <td className="text-nowrap">{dataHora(a.criado_em)}</td>
                                    <td>{a.usuario || '—'}</td>
                                    <td><span className="selo">{a.acao}</span></td>
                                    <td className="text-break">{a.detalhe}</td>
                                    <td className="d-none d-md-table-cell text-muted">{a.ip}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="d-flex justify-content-between align-items-center mt-3">
                <span className="text-muted small">Página {atividades.current_page}</span>
                <div className="d-flex gap-2">
                    {atividades.prev_page_url && <Link href={atividades.prev_page_url} preserveState className="btn btn-sm btn-outline-secondary">Anterior</Link>}
                    {atividades.next_page_url && <Link href={atividades.next_page_url} preserveState className="btn btn-sm btn-outline-secondary">Próxima</Link>}
                </div>
            </div>
        </div>
    )
}
