import { Link } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'

export default function NiveisIndex({ niveis }) {
    return (
        <div className="pagina">
            <TituloPagina
                titulo="Níveis de acesso"
                descricao="O dia a dia vale para todos. Aqui você escolhe o que cada nível pode fazer além disso."
            >
                <Link href="/niveis/novo" className="btn btn-primary d-inline-flex align-items-center gap-2">
                    <Icone nome="mais" /> Novo nível
                </Link>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead>
                            <tr><th>Nível</th><th>Permissões</th><th>Usuários</th><th /></tr>
                        </thead>
                        <tbody>
                            {niveis.map((n) => (
                                <tr key={n.id}>
                                    <td className="fw-semibold">
                                        {n.nome} {n.fixo && <span className="selo selo-marca ms-1">Acesso total</span>}
                                    </td>
                                    <td className="text-muted">{n.fixo ? 'Tudo' : n.total_permissoes === 0 ? 'Só o dia a dia' : `${n.total_permissoes} de ${n.total_catalogo}`}</td>
                                    <td>{n.usuarios}</td>
                                    <td className="text-end">
                                        <Link href={`/niveis/${n.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${n.nome}`}>
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
