import { Link, router } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import { dataHora, data as fData } from '../../lib/formato'

export default function PendentesIndex({ pendentes }) {
    return (
        <div className="pagina">
            <TituloPagina titulo="Fila de espera" descricao="Cadastros enviados pelo formulário público, em ordem de chegada. Revise, aprove ou recuse." />
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle">
                        <thead><tr><th>#</th><th>Recebido em</th><th>Nome</th><th>Veículo</th><th /></tr></thead>
                        <tbody>
                            {pendentes.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum cadastro esperando.</td></tr>}
                            {pendentes.map((p, i) => (
                                <tr key={p.id} style={{ cursor: 'pointer' }} onClick={() => router.visit(`/pendentes/${p.id}`)}>
                                    <td className="text-muted">{i + 1}º</td>
                                    <td className="text-nowrap" title={dataHora(p.recebido_em)}>{fData(p.recebido_em)}</td>
                                    <td>
                                        <span className="fw-semibold">{p.nome}</span>
                                        {p.cliente_existente && <span className="selo selo-aviso ms-2">Já é cliente</span>}
                                        {p.do_sistema_atual && <span className="selo ms-2" title="Veio do link do sistema atual: aprove ou recuse lá">Aprovar no sistema atual</span>}
                                    </td>
                                    <td>{p.placa} <span className="text-muted small">{p.modelo}</span></td>
                                    <td className="text-end"><Link href={`/pendentes/${p.id}`} className="btn btn-sm btn-outline-secondary" onClick={(e) => e.stopPropagation()}>Revisar</Link></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
