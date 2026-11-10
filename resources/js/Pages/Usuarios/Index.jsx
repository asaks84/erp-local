import { Link } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import { dataHora } from '../../lib/formato'

export default function UsuariosIndex({ usuarios }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Usuários" descricao="Quem entra no sistema e com qual nível de acesso.">
                <Link href="/usuarios/novo" className="btn btn-primary d-inline-flex align-items-center gap-2">
                    <Icone nome="mais" /> Novo usuário
                </Link>
            </TituloPagina>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th className="d-none d-sm-table-cell">Login</th>
                                <th>Nível</th>
                                <th className="d-none d-md-table-cell">Unidade</th>
                                <th className="d-none d-lg-table-cell">Último acesso</th>
                                <th>Situação</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {usuarios.map((u) => (
                                <tr key={u.id}>
                                    <td>
                                        <span className="fw-semibold">{u.nome}</span>
                                        <span className="d-block d-sm-none text-muted small">{u.login}</span>
                                    </td>
                                    <td className="d-none d-sm-table-cell">{u.login}</td>
                                    <td>{u.nivel}</td>
                                    <td className="d-none d-md-table-cell">{u.unidade || 'Todas'}</td>
                                    <td className="d-none d-lg-table-cell text-muted">{u.ultimo_acesso_em ? dataHora(u.ultimo_acesso_em) : 'Nunca'}</td>
                                    <td>{u.ativo ? <span className="selo selo-ok">Ativo</span> : <span className="selo">Inativo</span>}</td>
                                    <td className="text-end">
                                        <Link href={`/usuarios/${u.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${u.nome}`}>
                                            <Icone nome="lapis" tamanho={16} />
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
