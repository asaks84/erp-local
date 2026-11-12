import { router } from '@inertiajs/react'
import { Fragment, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasResultado from '../../Components/AbasResultado'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { data as fData, moeda } from '../../lib/formato'

const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']
const lerSaldo = () => { try { return Number(localStorage.getItem('fluxo-saldo-inicial')) || '' } catch { return '' } }

export default function Fluxo({ filtros, hoje, dias, realizado, previsto, atrasado }) {
    const [saldo, setSaldo] = useState(lerSaldo)
    const [aberto, setAberto] = useState(null)
    const mudarSaldo = (v) => { setSaldo(v); try { localStorage.setItem('fluxo-saldo-inicial', v === '' ? '' : String(v)) } catch { /* sem armazenamento */ } }
    const filtrar = (campo, v) => v && router.get('/resultado/fluxo', { ...filtros, [campo]: v }, { preserveScroll: true, preserveState: true })
    const base = Number(saldo) || 0
    const Card = ({ titulo, entra, sai, nota }) => (
        <div className="col-md-4"><div className="card h-100"><div className="card-body">
            <div className="small text-muted mb-1">{titulo}</div>
            <div className="d-flex justify-content-between"><span>Entra</span><strong className="text-success">{moeda(entra)}</strong></div>
            <div className="d-flex justify-content-between"><span>Sai</span><strong className="text-danger">{moeda(sai)}</strong></div>
            {nota && <div className="small text-muted mt-1">{nota}</div>}
        </div></div></div>
    )
    return (
        <div className="pagina">
            <AbasResultado />
            <TituloPagina titulo="Fluxo de caixa" descricao="Dia a dia: o dinheiro que entrou e saiu até hoje e o que está previsto para os próximos dias." />
            <div className="row g-2 align-items-start">
                <div className="col-6 col-md-3"><CampoData rotulo="De" value={filtros.de} onChange={(v) => filtrar('de', v)} /></div>
                <div className="col-6 col-md-3"><CampoData rotulo="Até" value={filtros.ate} onChange={(v) => filtrar('ate', v)} /></div>
                <div className="col-md-3"><CampoValor rotulo="Saldo no início (opcional)" value={saldo} onChange={mudarSaldo} ajuda="Quanto havia no caixa e no banco no 1º dia. Fica só neste navegador." /></div>
            </div>
            <div className="row g-3 mb-3">
                <Card titulo="Realizado (até hoje)" entra={realizado.entradas} sai={realizado.saidas} nota="Cartão conta no dia em que cai na conta, já sem a taxa." />
                <Card titulo="Previsto (de amanhã em diante)" entra={previsto.entradas} sai={previsto.saidas} nota="A receber pelo vencimento e contas a pagar em aberto." />
                <Card titulo="Atrasado (fora do fluxo)" entra={atrasado.receber} sai={atrasado.pagar} nota="Vencido e não pago: a receber de clientes e contas a pagar." />
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0 fluxo">
                        <thead><tr><th>Dia</th><th className="text-end">Entra</th><th className="text-end">Sai</th><th className="text-end d-none d-md-table-cell">Do dia</th><th className="text-end">Saldo</th></tr></thead>
                        <tbody>
                            {dias.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nada no período.</td></tr>}
                            {dias.map((d, i) => {
                                const acum = base + d.acumulado
                                const primeiroPrevisto = d.previsto && (i === 0 || !dias[i - 1].previsto)
                                return (
                                    <Fragment key={d.data}>
                                        {primeiroPrevisto && <tr className="table-light"><td colSpan="5" className="small fw-semibold text-muted">Previsto</td></tr>}
                                        <tr className={d.previsto ? 'previsto' : ''} role="button" onClick={() => setAberto(aberto === d.data ? null : d.data)} aria-expanded={aberto === d.data}>
                                            <td className="text-nowrap">{DIAS[new Date(d.data + 'T12:00').getDay()]} {fData(d.data)}{d.data === hoje && <span className="selo ms-1">hoje</span>}</td>
                                            <td className="text-end text-nowrap text-success">{d.entradas ? moeda(d.entradas) : ''}</td>
                                            <td className="text-end text-nowrap text-danger">{d.saidas ? moeda(d.saidas) : ''}</td>
                                            <td className="text-end text-nowrap d-none d-md-table-cell">{moeda(d.saldo)}</td>
                                            <td className={`text-end text-nowrap fw-semibold${acum < 0 ? ' text-danger' : ''}`}>{moeda(acum)}</td>
                                        </tr>
                                        {aberto === d.data && (
                                            <tr className="detalhe"><td colSpan="5">
                                                <ul className="list-unstyled small mb-0">
                                                    {d.itens.map((it, k) => (
                                                        <li key={k} className="d-flex justify-content-between gap-2">
                                                            <span>{it.rotulo}{it.qtd > 1 && <span className="text-muted"> ({it.qtd})</span>}{it.previsto && <span className="text-muted"> · previsto</span>}</span>
                                                            <span className={`text-nowrap ${it.lado === 'entradas' ? 'text-success' : 'text-danger'}`}>{it.lado === 'entradas' ? '+' : '−'} {moeda(it.valor)}</span>
                                                        </li>
                                                    ))}
                                                </ul>
                                            </td></tr>
                                        )}
                                    </Fragment>
                                )
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
            <p className="small text-muted mt-2">Clique num dia para ver o que entrou e saiu. O previsto a receber considera que os clientes pagam no vencimento.</p>
        </div>
    )
}
