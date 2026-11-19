import { Link, router } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import { dataHora } from '../../lib/formato'
import JanelaCodigoPin, { BotaoCodigoPin } from '../../Components/CodigoPin'

export default function UsuariosIndex({ usuarios }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Usuários" descricao="Quem entra no sistema e com qual nível de acesso. Quem é só do PDV não tem login nem senha: entra no balcão pelo PIN.">
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
                                <th className="d-none d-md-table-cell">PIN do PDV</th>
                                <th>Situação</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {usuarios.map((u) => (
                                <tr key={u.id}>
                                    <td>
                                        <span className="fw-semibold">{u.nome}</span>
                                        <span className="d-block d-sm-none text-muted small">{u.acessa_gestao ? u.login : 'Só PDV'}</span>
                                    </td>
                                    <td className="d-none d-sm-table-cell">{u.acessa_gestao ? u.login : <span className="selo selo-info">Só PDV</span>}</td>
                                    <td>{u.nivel}</td>
                                    <td className="d-none d-md-table-cell">{u.unidade || 'Todas'}</td>
                                    <td className="d-none d-lg-table-cell text-muted">{!u.acessa_gestao ? '—' : u.ultimo_acesso_em ? dataHora(u.ultimo_acesso_em) : 'Nunca'}</td>
                                    <td className="d-none d-md-table-cell small">
                                        {u.pin ? (
                                            <span className="d-inline-flex align-items-center gap-2">
                                                <span className="selo selo-ok">Cadastrado</span>
                                                <button type="button" className="btn btn-link btn-sm p-0 text-danger" onClick={() => router.post(`/usuarios/${u.id}/apagar-pin`, {}, { preserveScroll: true })}>apagar</button>
                                            </span>
                                        ) : u.ativo ? (
                                            <span className="d-inline-flex flex-wrap align-items-center gap-2">
                                                <span className={u.acessa_gestao ? 'text-muted' : 'selo selo-aviso'}>{u.codigo_ate ? `Código até ${u.codigo_ate}` : u.acessa_gestao ? 'Não cadastrado' : 'Aguardando PIN'}</span>
                                                <BotaoCodigoPin usuario={u} className="btn btn-link btn-sm p-0">{u.codigo_ate ? 'novo código' : 'gerar código'}</BotaoCodigoPin>
                                            </span>
                                        ) : <span className="text-muted">Não cadastrado</span>}
                                    </td>
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
            <JanelaCodigoPin />
        </div>
    )
}
