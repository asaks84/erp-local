import { Link, router, useForm } from '@inertiajs/react'
import { useMemo } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import CampoData from '../../Components/CampoData'
import { competencia } from '../../Components/Dinheiro'
import { data as fData, moeda } from '../../lib/formato'

export default function GerarCobrancas({ mes, linhas, inter }) {
    const { data, setData, post, processing, errors } = useForm({ mes, clientes: linhas.filter((l) => l.marcar).map((l) => l.cliente_id), boleto: !!inter, regras: true })
    const possiveis = linhas.filter((l) => !l.ja_emitida)
    const total = useMemo(() => linhas.filter((l) => data.clientes.includes(l.cliente_id)).reduce((s, l) => s + l.total, 0), [data.clientes, linhas])
    const marcar = (id) => setData('clientes', data.clientes.includes(id) ? data.clientes.filter((x) => x !== id) : [...data.clientes, id])

    return (
        <div className="pagina">
            <TituloPagina titulo="Emitir cobranças do mês" descricao="Uma cobrança por cliente, com todas as contas em aberto. O que ficou de cobranças anteriores entra como saldo anterior." />
            <div className="row g-2 align-items-start">
                <div className="col-sm-4 col-md-3"><CampoData mes rotulo="Mês" value={mes} onChange={(v) => v && router.get('/cobrancas/gerar', { mes: v })} /></div>
                <div className="col text-muted small pt-sm-3">{linhas.length} cliente(s) com contas em aberto · marcados: <strong>{data.clientes.length}</strong>, {moeda(total)}</div>
            </div>
            <div className="alert aviso-neutro small">Gere as mensalidades do mês antes, para que entrem na cobrança. <Link href={`/mensalidades/gerar?mes=${mes}`}>Gerar mensalidades de {competencia(mes)}</Link></div>
            {errors.clientes && <div className="alert alert-danger">{errors.clientes}</div>}
            <form onSubmit={(e) => { e.preventDefault(); post('/cobrancas/gerar') }}>
                <div className="card">
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style={{ width: 40 }}><input type="checkbox" className="form-check-input" aria-label="Marcar todos" checked={possiveis.length > 0 && data.clientes.length === possiveis.length} onChange={(e) => setData('clientes', e.target.checked ? possiveis.map((l) => l.cliente_id) : [])} /></th>
                                    <th>Cliente</th><th className="d-none d-md-table-cell">Itens</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                {linhas.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum cliente com contas em aberto.</td></tr>}
                                {linhas.map((l) => (
                                    <tr key={l.cliente_id} className={l.ja_emitida ? 'text-muted' : undefined}>
                                        <td>{!l.ja_emitida && <input type="checkbox" className="form-check-input" aria-label={`Emitir para ${l.cliente}`} checked={data.clientes.includes(l.cliente_id)} onChange={() => marcar(l.cliente_id)} />}</td>
                                        <td>
                                            {l.cliente}
                                            <div className="d-flex flex-wrap gap-1 mt-1">
                                                {l.ja_emitida && <span className="selo selo-ok">Já emitida</span>}
                                                {!l.mensalidade_do_mes && <span className="selo">Sem mensalidade do mês</span>}
                                                {l.saldo_anterior && <span className="selo selo-aviso">Com saldo anterior</span>}
                                                {data.boleto && data.regras && l.boleto_sem_regras && <span className="selo" title="Tem a primeira mensalidade ou uma proporcional">Boleto sem regras</span>}
                                            </div>
                                        </td>
                                        <td className="d-none d-md-table-cell">{l.itens}</td>
                                        <td className="d-none d-md-table-cell">{fData(l.vencimento)}</td>
                                        <td className="text-end text-nowrap">{moeda(l.total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div className="d-flex flex-wrap justify-content-end align-items-center gap-3 mt-3">
                    {inter && (
                        <div className="form-check mb-0">
                            <input className="form-check-input" type="checkbox" id="gerar-boleto" checked={data.boleto} onChange={(e) => setData('boleto', e.target.checked)} />
                            <label className="form-check-label" htmlFor="gerar-boleto">Gerar boleto com Pix (Banco Inter)</label>
                        </div>
                    )}
                    {inter && data.boleto && (
                        <div className="form-check mb-0" title="Quem tem a primeira mensalidade ou uma proporcional sai sempre sem as regras">
                            <input className="form-check-input" type="checkbox" id="gerar-regras" checked={data.regras} onChange={(e) => setData('regras', e.target.checked)} />
                            <label className="form-check-label" htmlFor="gerar-regras">Aplicar as regras do boleto</label>
                        </div>
                    )}
                    <button type="submit" className="btn btn-primary" disabled={processing || data.clientes.length === 0}>Emitir {data.clientes.length} cobrança(s)</button>
                </div>
            </form>
        </div>
    )
}
