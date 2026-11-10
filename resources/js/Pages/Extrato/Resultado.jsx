import { Link } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import { data as fData, moeda } from '../../lib/formato'

export default function Resultado({ resultado }) {
    const ok = resultado.filter((r) => r.ok)
    return (
        <div className="pagina">
            <TituloPagina titulo="Extrato processado" descricao={`${ok.length} de ${resultado.length} entrada(s) baixada(s) · ${moeda(ok.reduce((s, r) => s + r.valor, 0))}`}>
                <Link href="/extrato" className="btn btn-primary">Voltar ao extrato</Link>
            </TituloPagina>
            <div className="card">
                <ul className="list-group list-group-flush">
                    {resultado.length === 0 && <li className="list-group-item text-muted">Nenhuma entrada marcada.</li>}
                    {resultado.map((r, i) => (
                        <li key={i} className="list-group-item d-flex flex-wrap gap-2 align-items-center">
                            <span className={`selo ${r.ok ? 'selo-ok' : 'selo-erro'}`}>{r.ok ? 'Baixado' : 'Não baixado'}</span>
                            <strong>{moeda(r.valor)}</strong><span>{fData(r.data)}</span><span>{r.nome}</span>
                            <span className="text-muted">→ <Link href={`/clientes/${r.cliente.id}`}>{r.cliente.nome}</Link></span>
                            <span className="small text-muted w-100">{r.msg}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    )
}
