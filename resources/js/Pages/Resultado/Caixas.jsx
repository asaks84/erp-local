import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasResultado from '../../Components/AbasResultado'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import { data, dataHora, mesAno, moeda } from '../../lib/formato'

const mudarMes = (ym, n) => { const [a, m] = ym.split('-').map(Number); const d = new Date(a, m - 1 + n, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const hoje = () => new Date().toISOString().slice(0, 10)

function Diferenca({ v }) {
    if (v === null || v === undefined) return <span className="text-muted">—</span>
    if (Math.abs(v) < 0.005) return <span className="text-success">certo</span>
    return <span className={v > 0 ? 'text-warning-emphasis' : 'text-danger'}>{v > 0 ? 'sobrou ' : 'faltou '}{moeda(Math.abs(v))}</span>
}

/** detalhe de um caixa: por serviço, por forma, gaveta */
export function ResumoCaixa({ r }) {
    return (
        <div className="row g-3">
            <div className="col-md-6">
                <table className="table table-sm mb-3">
                    <thead><tr><th>Por serviço</th><th className="text-end">Qtd</th><th className="text-end">Total</th></tr></thead>
                    <tbody>{r.por_servico.map((l) => <tr key={l.servico}><td>{l.servico}</td><td className="text-end">{l.qtd}</td><td className="text-end">{moeda(l.total)}</td></tr>)}</tbody>
                    <tfoot><tr className="fw-bold"><td colSpan="2">Total recebido</td><td className="text-end">{moeda(r.total_recebido)}</td></tr></tfoot>
                </table>
                <table className="table table-sm mb-0">
                    <thead><tr><th>Por forma de pagamento</th><th className="text-end">Total</th></tr></thead>
                    <tbody>
                        {r.por_forma.length === 0 && <tr><td colSpan="2" className="text-muted">Nada recebido.</td></tr>}
                        {r.por_forma.map((l) => <tr key={l.forma}><td>{l.forma}</td><td className="text-end">{moeda(l.total)}</td></tr>)}
                    </tbody>
                </table>
            </div>
            <div className="col-md-6">
                <table className="table table-sm mb-3">
                    <thead><tr><th colSpan="2">Dinheiro na gaveta</th></tr></thead>
                    <tbody>
                        <tr><td>Troco inicial</td><td className="text-end">{moeda(r.troco_inicial)}</td></tr>
                        <tr><td>+ Recebido em dinheiro</td><td className="text-end">{moeda(r.dinheiro_recebido)}</td></tr>
                        {r.suprimentos > 0 && <tr><td>+ Suprimentos</td><td className="text-end">{moeda(r.suprimentos)}</td></tr>}
                        {r.sangrias > 0 && <tr><td>− Sangrias</td><td className="text-end">{moeda(-r.sangrias)}</td></tr>}
                        {r.pagamentos > 0 && <tr><td>− Pagamentos</td><td className="text-end">{moeda(-r.pagamentos)}</td></tr>}
                        {r.retiradas > 0 && <tr><td>− Retiradas do sócio</td><td className="text-end">{moeda(-r.retiradas)}</td></tr>}
                        <tr className="fw-bold"><td>Deveria ter</td><td className="text-end">{moeda(r.dinheiro_esperado)}</td></tr>
                        {r.dinheiro_contado !== undefined && (
                            <>
                                <tr><td>Contado</td><td className="text-end">{moeda(r.dinheiro_contado)}</td></tr>
                                <tr><td>Diferença</td><td className="text-end"><Diferenca v={r.diferenca} /></td></tr>
                                <tr><td>Ficou de troco</td><td className="text-end">{moeda(r.troco_deixado)}</td></tr>
                            </>
                        )}
                    </tbody>
                </table>
                {r.movimentos.length > 0 && (
                    <table className="table table-sm mb-0">
                        <thead><tr><th>Movimentos da gaveta</th><th className="text-end">Valor</th></tr></thead>
                        <tbody>{r.movimentos.map((m) => (
                            <tr key={m.id}><td>{m.rotulo}<div className="small text-muted">{m.hora}{m.quem ? ` · ${m.quem}` : ''}{m.motivo ? ` · ${m.motivo}` : ''}</div></td>
                                <td className="text-end text-nowrap">{moeda(m.sinal * m.valor)}</td></tr>
                        ))}</tbody>
                    </table>
                )}
            </div>
        </div>
    )
}

function NovaRetirada({ contas }) {
    const banco = contas.find((c) => c.tipo === 'banco')
    const form = useForm({ valor: '', data: hoje(), conta_financeira_id: banco?.id ?? contas[0]?.id ?? '', motivo: '' })
    return (
        <form className="row g-2 align-items-start" onSubmit={(e) => { e.preventDefault(); form.post('/resultado/retiradas', { preserveScroll: true, onSuccess: () => form.reset('valor', 'motivo') }) }}>
            <div className="col-sm-3"><CampoValor rotulo="Valor" value={form.data.valor} onChange={(v) => form.setData('valor', v)} erro={form.errors.valor} /></div>
            <div className="col-sm-3"><Campo rotulo="Data" type="date" value={form.data.data} max={hoje()} onChange={(e) => form.setData('data', e.target.value)} erro={form.errors.data} /></div>
            <div className="col-sm-3">
                <Campo as="select" rotulo="Saiu de" value={form.data.conta_financeira_id} onChange={(e) => form.setData('conta_financeira_id', e.target.value)} erro={form.errors.conta_financeira_id}>
                    {contas.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </Campo>
            </div>
            <div className="col-sm-3"><Campo rotulo="Observação (opcional)" value={form.data.motivo} maxLength={200} onChange={(e) => form.setData('motivo', e.target.value)} /></div>
            <div className="col-12 text-end"><button type="submit" className="btn btn-primary" disabled={form.processing}>Registrar retirada</button></div>
        </form>
    )
}

/** Gestão › Resultado e caixa › Caixa do PDV */
export default function Caixas({ mes, caixas, detalhe, retiradas, contas }) {
    const ir = (dados) => router.get('/resultado/caixas', { mes, ...dados }, { preserveScroll: true, preserveState: true })
    const [nova, setNova] = useState(false)
    const totalRet = retiradas.reduce((s, r) => s + r.valor, 0)
    return (
        <div className="pagina">
            <AbasResultado />
            <TituloPagina titulo="Caixa do PDV" descricao="Os caixas do balcão (o que passou pelo celular) com o fechamento do gerente, e as retiradas dos sócios, que saem do resultado como &quot;ficou na empresa&quot;." />
            <div className="btn-group mb-3" role="group" aria-label="Mês">
                <button type="button" className="btn btn-outline-secondary" onClick={() => ir({ mes: mudarMes(mes, -1), caixa: undefined })} aria-label="Mês anterior">‹</button>
                <span className="btn btn-outline-secondary disabled text-body fw-semibold" style={{ minWidth: '10rem' }}>{mesAno(mes + '-01')}</span>
                <button type="button" className="btn btn-outline-secondary" onClick={() => ir({ mes: mudarMes(mes, 1), caixa: undefined })} aria-label="Próximo mês">›</button>
            </div>

            <div className="card mb-3">
                <div className="card-header fw-semibold">Caixas</div>
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Aberto</th><th>Fechado</th><th className="text-end">Recebido</th><th className="text-end">Deveria ter</th><th className="text-end">Contado</th><th>Diferença</th></tr></thead>
                        <tbody>
                            {caixas.length === 0 && <tr><td colSpan="6" className="text-muted small py-3">Nenhum caixa no mês.</td></tr>}
                            {caixas.map((c) => (
                                <tr key={c.id} role="button" className={detalhe?.id === c.id ? 'table-active' : ''} onClick={() => ir({ caixa: c.id })}>
                                    <td>{dataHora(c.aberto_em)}<div className="small text-muted">{c.aberto_por}</div></td>
                                    <td>{c.situacao === 'aberto' ? <span className="selo selo-aviso">Aberto</span> : <>{dataHora(c.fechado_em)}<div className="small text-muted">{c.fechado_por}</div></>}</td>
                                    <td className="text-end">{c.total_recebido !== null ? moeda(c.total_recebido) : '—'}</td>
                                    <td className="text-end">{c.esperado !== null ? moeda(c.esperado) : '—'}</td>
                                    <td className="text-end">{c.contado !== null ? moeda(c.contado) : '—'}</td>
                                    <td><Diferenca v={c.diferenca} />{c.observacao && <div className="small text-muted">{c.observacao}</div>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {detalhe && (
                <div className="card mb-3">
                    <div className="card-header d-flex align-items-center gap-2">
                        <span className="fw-semibold flex-grow-1">Caixa aberto em {dataHora(detalhe.aberto_em)} {detalhe.situacao === 'aberto' ? '(ainda aberto: valores até agora)' : `· fechado em ${dataHora(detalhe.fechado_em)} por ${detalhe.fechado_por || '—'}`}</span>
                        <button type="button" className="btn-close" aria-label="Fechar detalhe" onClick={() => ir({ caixa: undefined })} />
                    </div>
                    <div className="card-body"><ResumoCaixa r={detalhe} />{detalhe.observacao && <p className="small text-muted mt-2 mb-0">Observação: {detalhe.observacao}</p>}</div>
                </div>
            )}

            <div className="card">
                <div className="card-header d-flex align-items-center gap-2">
                    <span className="fw-semibold flex-grow-1">Retiradas dos sócios {totalRet > 0 && <span className="text-muted fw-normal">· {moeda(totalRet)} no mês</span>}</span>
                    {!nova && <button type="button" className="btn btn-sm btn-primary" onClick={() => setNova(true)}>Registrar retirada</button>}
                </div>
                <div className="card-body">
                    <p className="small text-muted">Não é despesa: o lucro do mês não muda, mas a retirada sai do que ficou na empresa (no Resultado, abaixo do lucro) e aparece como saída no fluxo de caixa. As feitas na gaveta do PDV entram sozinhas; aqui, registre as que saíram do banco.</p>
                    {nova && <div className="border rounded p-3 mb-3"><NovaRetirada contas={contas} /></div>}
                    {retiradas.length === 0 ? <div className="text-muted small">Nenhuma retirada no mês.</div> : (
                        <table className="table table-sm align-middle mb-0">
                            <thead><tr><th>Data</th><th>Saiu de</th><th>Observação</th><th className="text-end">Valor</th><th /></tr></thead>
                            <tbody>{retiradas.map((r) => (
                                <tr key={r.id}>
                                    <td>{data(r.data)}</td><td>{r.origem}</td><td className="small">{r.motivo}{r.quem && <span className="text-muted"> · {r.quem}</span>}</td>
                                    <td className="text-end">{moeda(r.valor)}</td>
                                    <td className="text-end">{!r.pdv && <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => { if (window.confirm('Apagar esta retirada?')) router.delete(`/resultado/retiradas/${r.id}`, { preserveScroll: true }) }}>Apagar</button>}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    )}
                </div>
            </div>
        </div>
    )
}
