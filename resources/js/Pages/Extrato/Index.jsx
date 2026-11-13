import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import { dataHora, data as fData, moeda } from '../../lib/formato'

export default function ExtratoIndex({ ultimas, tamanho_max_kb }) {
    const { data, setData, post, processing, errors, progress } = useForm({ arquivo: null })

    return (
        <div className="pagina">
            <TituloPagina titulo="Extrato bancário" descricao="Envie o extrato em CSV (exportado pelo app do banco). O sistema sugere o cliente de cada PIX recebido e você confere antes de dar baixa." />
            <form className="card mb-4" onSubmit={(e) => { e.preventDefault(); post('/extrato', { forceFormData: true }) }}>
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                    <div className="col-lg-8">
                    <label className="form-label" htmlFor="arquivo">Arquivo CSV (até {Math.round(tamanho_max_kb / 1024)} MB)</label>
                    <input id="arquivo" type="file" accept=".csv,text/csv" className={`form-control${errors.arquivo ? ' is-invalid' : ''}`} onChange={(e) => setData('arquivo', e.target.files[0] || null)} />
                    {errors.arquivo && <div className="invalid-feedback">{errors.arquivo}</div>}
                    <p className="form-text mb-0">Só as entradas são lidas; as saídas são ignoradas. PIX já baixado aparece marcado e não baixa de novo.</p>
                    {progress && <div className="progress mt-2"><div className="progress-bar" style={{ width: `${progress.percentage}%` }} /></div>}
                    </div>
                    <div className="col-lg-4 text-lg-end pb-lg-4">
                    <button type="submit" className="btn btn-primary" disabled={processing || !data.arquivo}>Ler o extrato</button>
                    </div>
                    </div>
                </div>
            </form>

            <h2 className="fs-5 fw-semibold">Últimos recebimentos pelo extrato</h2>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Data</th><th>Pagador</th><th>Cliente</th><th className="text-end">Valor</th><th className="d-none d-md-table-cell">Baixado</th></tr></thead>
                        <tbody>
                            {ultimas.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum extrato importado ainda.</td></tr>}
                            {ultimas.map((e) => (
                                <tr key={e.id}>
                                    <td>{fData(e.data)}</td>
                                    <td>{e.nome}</td>
                                    <td>{e.cliente ? <Link href={`/clientes/${e.cliente.id}`}>{e.cliente.nome}</Link> : '—'}</td>
                                    <td className="text-end text-nowrap">{moeda(e.valor)}</td>
                                    <td className="d-none d-md-table-cell small text-muted">{dataHora(e.processado_em)}{e.usuario ? ` · ${e.usuario}` : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    )
}
