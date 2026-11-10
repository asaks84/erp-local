import { Link, usePage } from '@inertiajs/react'
import TituloPagina from '../Components/TituloPagina'
import { usePode } from '../lib/permissoes'

export default function Inicio({ resumo }) {
    const { auth, empresa } = usePage().props
    const pode = usePode()
    const primeiroNome = auth.usuario.nome.split(' ')[0]
    return (
        <div className="pagina">
            <TituloPagina titulo={`Olá, ${primeiroNome}`} descricao={empresa?.nome} />

            <div className="alert aviso-neutro">
                Este é o sistema novo, ainda em construção. O sistema atual continua sendo o oficial;
                os módulos (clientes, cobranças, estadias) entram aqui nas próximas etapas.
            </div>

            <div className="row g-3">
                <div className="col-6 col-md-3">
                    <div className="card h-100"><div className="card-body">
                        <div className="text-muted small">Unidades</div>
                        <div className="fs-3 fw-bold">{resumo.unidades}</div>
                    </div></div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="card h-100"><div className="card-body">
                        <div className="text-muted small">Usuários ativos</div>
                        <div className="fs-3 fw-bold">{resumo.usuarios}</div>
                    </div></div>
                </div>
                <div className="col-6 col-md-3">
                    <div className="card h-100"><div className="card-body">
                        <div className="text-muted small">Níveis de acesso</div>
                        <div className="fs-3 fw-bold">{resumo.niveis}</div>
                    </div></div>
                </div>
            </div>

            {pode('configuracoes') && (
                <div className="mt-4 d-flex flex-wrap gap-2">
                    <Link href="/empresa" className="btn btn-outline-secondary">Empresa e unidades</Link>
                    <Link href="/niveis" className="btn btn-outline-secondary">Níveis de acesso</Link>
                    {pode('usuarios') && <Link href="/usuarios" className="btn btn-outline-secondary">Usuários</Link>}
                </div>
            )}
        </div>
    )
}
