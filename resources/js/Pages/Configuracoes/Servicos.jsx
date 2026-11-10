import { Link } from '@inertiajs/react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import { moeda } from '../../lib/formato'

export default function Servicos({ servicos, tipos }) {
    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Lavagens e serviços" descricao="Serviços principais, adicionais e o preço de cada um por tipo de veículo (preços que valem hoje).">
                <Link href="/configuracoes/servicos/novo" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Novo serviço</Link>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Serviço</th>
                                {tipos.map((t) => <th key={t.id} className="text-end">{t.nome}</th>)}
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {servicos.length === 0 && <tr><td colSpan={tipos.length + 2} className="text-center text-muted py-4">Nenhum serviço.</td></tr>}
                            {servicos.map((s) => (
                                <tr key={s.id} className={s.ativo ? undefined : 'inativo'}>
                                    <td>
                                        <span className="fw-semibold">{s.nome}</span> <span className="text-muted small">{s.codigo}</span>
                                        <span className="d-block small">
                                            <span className={`selo ${s.papel === 'principal' ? 'selo-marca' : ''}`}>{s.papel === 'principal' ? 'Principal' : 'Adicional'}</span>
                                            {s.preco_modo === 'ate' && <span className="selo ms-1">Valor "até"</span>}
                                            {!s.ativo && <span className="selo ms-1">Inativo</span>}
                                            {s.adicionais.length > 0 && <span className="text-muted ms-1">+ {s.adicionais.join(', ')}</span>}
                                        </span>
                                    </td>
                                    {tipos.map((t) => <td key={t.id} className="text-end text-nowrap">{s.precos[t.id] !== undefined ? moeda(s.precos[t.id]) : <span className="text-muted">—</span>}</td>)}
                                    <td className="text-end"><Link href={`/configuracoes/servicos/${s.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${s.nome}`}><Icone nome="lapis" tamanho={16} /></Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
