import { Link } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import { data as fData } from '../../lib/formato'

const SELO = { vigente: ['selo-ok', 'Vigente'], futura: ['selo-aviso', 'Futura'], encerrada: ['', 'Encerrada'] }

export default function Tarifas({ tarifas }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Tabelas de estadia" descricao="Quanto se cobra pelo tempo no pátio, por tipo de veículo. Reajuste = nova versão com data de início; até lá, vale a atual.">
                <Link href="/configuracoes/tarifas/nova" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Nova tabela</Link>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead><tr><th>Tabela</th><th>Tipos</th><th className="d-none d-lg-table-cell">Regras</th><th>Vale</th><th /></tr></thead>
                        <tbody>
                            {tarifas.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma tabela.</td></tr>}
                            {tarifas.map((t) => {
                                const [classe, rotulo] = SELO[t.situacao]
                                return (
                                    <tr key={t.id} className={t.situacao === 'encerrada' ? 'inativo' : undefined}>
                                        <td><span className="fw-semibold">{t.nome}</span>{t.unidade && <span className="d-block small text-muted">Só na unidade {t.unidade}</span>}</td>
                                        <td className="small">{t.tipos.join(', ') || <span className="text-danger">nenhum tipo</span>}</td>
                                        <td className="d-none d-lg-table-cell small text-muted">{t.resumo}</td>
                                        <td className="small text-nowrap">
                                            <span className={`selo ${classe}`}>{rotulo}</span>
                                            <span className="d-block text-muted">{t.vigencia_inicio <= '2000-01-01' ? 'desde sempre' : `de ${fData(t.vigencia_inicio)}`}{t.vigencia_fim ? ` até ${fData(t.vigencia_fim)}` : ''}</span>
                                        </td>
                                        <td className="text-end text-nowrap">
                                            {t.situacao !== 'encerrada' && <Link href={`/configuracoes/tarifas/nova?copiar=${t.id}`} className="btn btn-sm btn-outline-secondary me-1">Reajustar</Link>}
                                            <Link href={`/configuracoes/tarifas/${t.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${t.nome}`}><Icone nome="lapis" tamanho={16} /></Link>
                                        </td>
                                    </tr>
                                )
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
