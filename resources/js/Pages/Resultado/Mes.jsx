import { Link, router } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import AbasResultado from '../../Components/AbasResultado'
import { mesAno, moeda } from '../../lib/formato'

const mudarMes = (ym, n) => { const [a, m] = ym.split('-').map(Number); const d = new Date(a, m - 1 + n, 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }

function Linhas({ linhas, vazio }) {
    if (!linhas.length) return <tr><td colSpan="2" className="text-muted small py-3">{vazio}</td></tr>
    return linhas.map((l) => (
        <tr key={l.id} className={l.nivel === 1 ? 'fw-semibold' : 'small'}>
            <td style={{ paddingLeft: `${0.75 + (l.nivel - 1) * 1.25}rem` }}>{l.codigo && <span className="text-muted me-2">{l.codigo}</span>}{l.nome}</td>
            <td className={`text-end text-nowrap${l.valor < 0 ? ' text-danger' : ''}`}>{moeda(l.valor)}</td>
        </tr>
    ))
}

export default function Mes({ resultado: r, historico, atual }) {
    const ir = (dados) => router.get('/resultado', { mes: r.mes, regime: r.regime, ...dados }, { preserveScroll: true, preserveState: true })
    const maior = Math.max(1, ...historico.flatMap((h) => [h.receitas, h.despesas]))
    const positivo = r.resultado >= 0
    const extras = r.total_investimentos > 0 || r.fora_empresa > 0
    return (
        <div className="pagina">
            <AbasResultado />
            <TituloPagina titulo="Resultado do mês" descricao={r.regime === 'caixa'
                ? 'Pelo dinheiro: o que entrou (recebimentos) e o que saiu (contas pagas) no mês.'
                : 'Por mês de referência: o que o mês faturou e as despesas que são dele, pagas ou não.'} />

            <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                <div className="btn-group" role="group" aria-label="Mês">
                    <button type="button" className="btn btn-outline-secondary" onClick={() => ir({ mes: mudarMes(r.mes, -1) })} aria-label="Mês anterior">‹</button>
                    <span className="btn btn-outline-secondary disabled text-body fw-semibold" style={{ minWidth: '10rem' }}>{mesAno(r.mes + '-01')}</span>
                    <button type="button" className="btn btn-outline-secondary" onClick={() => ir({ mes: mudarMes(r.mes, 1) })} aria-label="Próximo mês">›</button>
                </div>
                <div className="btn-group ms-md-auto" role="group" aria-label="Olhar">
                    <button type="button" className={`btn ${r.regime === 'referencia' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => ir({ regime: 'referencia' })}>Por referência</button>
                    <button type="button" className={`btn ${r.regime === 'caixa' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => ir({ regime: 'caixa' })}>Pelo dinheiro</button>
                </div>
            </div>

            <div className="row g-3 mb-3">
                <div className="col-sm-4"><div className="card h-100"><div className="card-body"><div className="small text-muted">{r.regime === 'caixa' ? 'Entrou' : 'Receitas'}</div><div className="fs-4 fw-semibold">{moeda(r.total_receitas)}</div></div></div></div>
                <div className="col-sm-4"><div className="card h-100"><div className="card-body"><div className="small text-muted">{r.regime === 'caixa' ? 'Saiu' : 'Despesas'}</div><div className="fs-4 fw-semibold">{moeda(r.total_despesas)}</div>
                    {r.a_pagar > 0 && <div className="small text-muted">{moeda(r.a_pagar)} ainda a pagar</div>}</div></div></div>
                <div className="col-sm-4"><div className="card h-100"><div className="card-body"><div className="small text-muted">{positivo ? 'Sobrou' : 'Faltou'}</div>
                    <div className={`fs-4 fw-semibold ${positivo ? 'text-success' : 'text-danger'}`}>{moeda(r.resultado)}</div>
                    {r.margem !== null && <div className="small text-muted">{r.margem.toLocaleString('pt-BR')}% {r.regime === 'caixa' ? 'do que entrou' : 'das receitas'}</div>}
                    {extras && <div className="small">No fim: <strong className={r.resultado_final >= 0 ? 'text-success' : 'text-danger'}>{moeda(r.resultado_final)}</strong></div>}</div></div></div>
            </div>
            {atual && <div className="alert alert-info py-2 small">O mês ainda está em andamento: os números mudam até o fim do mês.</div>}
            {r.excluidos?.qtd > 0 && (
                <div className="alert alert-warning py-2 small">
                    As receitas incluem <strong>{moeda(r.excluidos.valor)}</strong> em {r.excluidos.qtd} conta(s) em aberto de cliente excluído.
                    Se não vão ser recebidas, cancele no sistema atual (depois da virada, aqui) para saírem do resultado. <Link href="/contas/abertas">Contas em aberto</Link>
                </div>
            )}

            <div className="row g-3">
                <div className="col-lg-7">
                    <div className="card">
                        <div className="table-responsive">
                            <table className="table align-middle mb-0">
                                <thead><tr><th>{r.regime === 'caixa' ? 'Entradas' : 'Receitas'}</th><th className="text-end">{moeda(r.total_receitas)}</th></tr></thead>
                                <tbody><Linhas linhas={r.receitas} vazio="Nada no mês." /></tbody>
                                <thead><tr><th>{r.regime === 'caixa' ? 'Saídas' : 'Despesas'}</th><th className="text-end">{moeda(r.total_despesas)}</th></tr></thead>
                                <tbody><Linhas linhas={r.despesas} vazio="Nenhuma despesa no mês." /></tbody>
                                <tfoot>
                                    <tr className="fw-bold"><td>{extras ? 'Resultado da operação' : 'Resultado'}</td><td className={`text-end ${positivo ? 'text-success' : 'text-danger'}`}>{moeda(r.resultado)}</td></tr>
                                    {r.investimentos.map((l) => (
                                        <tr key={`i${l.id}`} className={l.nivel === 1 ? '' : 'small'}><td style={{ paddingLeft: `${0.75 + (l.nivel - 1) * 1.25}rem` }}>(−) {l.nome}</td><td className="text-end text-nowrap">{moeda(-l.valor)}</td></tr>
                                    ))}
                                    {r.fora_empresa > 0 && <tr><td>(−) Compras no cartão que não são da empresa</td><td className="text-end text-nowrap">{moeda(-r.fora_empresa)}</td></tr>}
                                    {extras && <tr className="fw-bold"><td>Sobrou no fim</td><td className={`text-end ${r.resultado_final >= 0 ? 'text-success' : 'text-danger'}`}>{moeda(r.resultado_final)}</td></tr>}
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <p className="small text-muted mt-2">
                        {r.regime === 'caixa'
                            ? 'Recebimentos pela data em que foram recebidos; contas pelo dia em que foram pagas, com o valor pago de fato.'
                            : 'Mensalidades pelo mês de referência; estadias e serviços pela data. Débitos e créditos de troco não entram (são resto ou adiantamento de conta já contada). Despesas pelo mês de referência.'}
                        {' '}<Link href="/pagar">Contas a pagar</Link> · <Link href="/relatorios/recebimentos">Recebimentos</Link>
                    </p>
                </div>
                <div className="col-lg-5">
                    <div className="card">
                        <div className="card-header">Últimos 6 meses</div>
                        <ul className="list-group list-group-flush">
                            {historico.map((h) => (
                                <li key={h.mes} className={`list-group-item${h.mes === r.mes ? ' bg-body-tertiary' : ''}`} role="button" onClick={() => ir({ mes: h.mes })}>
                                    <div className="d-flex justify-content-between small mb-1"><span className="fw-semibold">{mesAno(h.mes + '-01')}</span><span className={h.resultado >= 0 ? 'text-success' : 'text-danger'}>{moeda(h.resultado)}</span></div>
                                    <div className="barra-resultado receita" style={{ width: `${(h.receitas / maior) * 100}%` }} title={`Receitas ${moeda(h.receitas)}`} />
                                    <div className="barra-resultado despesa" style={{ width: `${(h.despesas / maior) * 100}%` }} title={`Despesas ${moeda(h.despesas)}`} />
                                </li>
                            ))}
                        </ul>
                        <div className="card-footer small text-muted d-flex gap-3"><span><span className="legenda receita" /> {r.regime === 'caixa' ? 'Entrou' : 'Receitas'}</span><span><span className="legenda despesa" /> {r.regime === 'caixa' ? 'Saiu' : 'Despesas'}</span></div>
                    </div>
                </div>
            </div>
        </div>
    )
}
