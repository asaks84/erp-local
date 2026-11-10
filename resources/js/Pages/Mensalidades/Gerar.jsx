import { router, useForm } from '@inertiajs/react'
import { useMemo } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import CampoData from '../../Components/CampoData'
import { competencia } from '../../Components/Dinheiro'
import { data as fData, moeda } from '../../lib/formato'

const SITUACAO = {
    gerar: ['Vai gerar', 'selo-aviso'],
    pausa: ['Pausado (taxa)', 'selo-marca'],
    inicio_no_mes: ['Começou no mês', ''],
    ja_gerada: ['Já gerada', 'selo-ok'],
    coberta: ['Já coberta', 'selo-ok'],
    pausa_gratis: ['Pausado', ''],
}

export default function Gerar({ mes, linhas }) {
    const { data, setData, post, processing, errors } = useForm({ mes, contratos: linhas.filter((l) => l.marcar).map((l) => l.contrato_id) })
    const selecionaveis = linhas.filter((l) => l.selecionavel)
    const total = useMemo(() => linhas.filter((l) => data.contratos.includes(l.contrato_id)).reduce((s, l) => s + l.valor, 0), [data.contratos, linhas])

    function marcar(id) {
        setData('contratos', data.contratos.includes(id) ? data.contratos.filter((x) => x !== id) : [...data.contratos, id])
    }
    function todos(v) {
        setData('contratos', v ? selecionaveis.map((l) => l.contrato_id) : [])
    }
    function trocarMes(v) {
        if (v) router.get('/mensalidades/gerar', { mes: v })
    }

    return (
        <div className="pagina">
            <TituloPagina titulo="Gerar mensalidades" descricao="Lança a mensalidade do mês na conta de cada mensalista. Quem já tem a mensalidade do mês não recebe outra." />
            <div className="row g-2 align-items-start">
                <div className="col-sm-4 col-md-3"><CampoData mes rotulo="Mês" value={mes} onChange={trocarMes} /></div>
                <div className="col text-muted small pt-sm-3">
                    {linhas.length} mensalista(s) · {selecionaveis.length} para gerar · marcados: <strong>{data.contratos.length}</strong>, {moeda(total)}
                </div>
            </div>
            {errors.contratos && <div className="alert alert-danger">{errors.contratos}</div>}
            <form onSubmit={(e) => { e.preventDefault(); post('/mensalidades/gerar', { preserveScroll: true }) }}>
                <div className="card">
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style={{ width: 40 }}><input type="checkbox" className="form-check-input" aria-label="Marcar todos" checked={selecionaveis.length > 0 && data.contratos.length === selecionaveis.length} onChange={(e) => todos(e.target.checked)} /></th>
                                    <th>Cliente</th><th>Veículo</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Valor</th><th>Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                {linhas.length === 0 && <tr><td colSpan="6" className="text-center text-muted py-4">Nenhum mensalista neste mês.</td></tr>}
                                {linhas.map((l) => {
                                    const [rot, cls] = SITUACAO[l.situacao] || [l.situacao, '']
                                    return (
                                        <tr key={l.contrato_id} className={l.selecionavel ? undefined : 'text-muted'}>
                                            <td>{l.selecionavel && <input type="checkbox" className="form-check-input" aria-label={`Gerar para ${l.veiculo}`} checked={data.contratos.includes(l.contrato_id)} onChange={() => marcar(l.contrato_id)} />}</td>
                                            <td>{l.cliente}</td>
                                            <td className="text-nowrap">{l.veiculo}<div className="small text-muted">{l.periodo}</div></td>
                                            <td className="d-none d-md-table-cell">{fData(l.vencimento)}</td>
                                            <td className="text-end text-nowrap">{moeda(l.valor)}</td>
                                            <td><span className={`selo ${cls}`}>{rot}</span>{l.detalhe && <div className="small text-muted">{l.detalhe}</div>}</td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div className="d-flex justify-content-end mt-3">
                    <button type="submit" className="btn btn-primary" disabled={processing || data.contratos.length === 0}>
                        Gerar {data.contratos.length} mensalidade(s) de {competencia(mes)}
                    </button>
                </div>
            </form>
        </div>
    )
}
