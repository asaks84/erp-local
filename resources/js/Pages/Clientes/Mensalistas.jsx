import { Link, router } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import AbasClientes from '../../Components/AbasClientes'
import CabecalhoImpressao from '../../Components/CabecalhoImpressao'
import Icone from '../../Components/Icone'
import { moeda } from '../../lib/formato'

/** Clientes › Mensalistas: resumo por tipo e período (controle) e a lista, com filtro por tipo de veículo. */
export default function Mensalistas({ controle, grupos, periodos, receita, tipos, escolhidos, lista }) {
    const todosMarcados = escolhidos.length === 0
    const marcado = (id) => todosMarcados || escolhidos.includes(id)
    const filtrar = (ids) => router.get('/clientes/mensalistas', ids.length && ids.length < tipos.length ? { tipos: ids } : {}, { preserveScroll: true, preserveState: true })
    const alternar = (id) => {
        const atuais = todosMarcados ? tipos.map((t) => t.id) : escolhidos
        const novos = atuais.includes(id) ? atuais.filter((x) => x !== id) : [...atuais, id]
        if (novos.length) filtrar(novos)   // pelo menos um tipo
    }
    const csv = '/clientes/mensalistas?csv=1' + escolhidos.map((id) => `&tipos[]=${id}`).join('')

    // totais do resumo
    const porGrupo = Object.fromEntries(Object.keys(grupos).map((g) => [g, Object.values(controle[g]).reduce((s, x) => ({ qtd: s.qtd + x.qtd, valor: s.valor + x.valor }), { qtd: 0, valor: 0 })]))
    const porPeriodo = Object.fromEntries(Object.keys(periodos).map((p) => [p, Object.keys(grupos).reduce((s, g) => ({ qtd: s.qtd + controle[g][p].qtd, valor: s.valor + controle[g][p].valor }), { qtd: 0, valor: 0 })]))
    const total = Object.values(porGrupo).reduce((s, x) => ({ qtd: s.qtd + x.qtd, valor: s.valor + x.valor }), { qtd: 0, valor: 0 })
    const totalLista = lista.reduce((s, l) => s + (l.valor || 0), 0)
    const nomesTipos = todosMarcados ? 'todos' : tipos.filter((t) => escolhidos.includes(t.id)).map((t) => t.nome).join(', ')

    return (
        <div className="pagina impressao-retrato">
            <AbasClientes />
            <CabecalhoImpressao titulo="Mensalistas" detalhe={`Tipos de veículo: ${nomesTipos}`} />
            <TituloPagina titulo="Mensalistas" descricao={receita ? 'Veículos mensalistas e receita mensal por tipo e período' : 'Veículos mensalistas por tipo e período'}>
                <button type="button" className="btn btn-outline-secondary d-inline-flex align-items-center gap-2 nao-imprimir" onClick={() => window.print()}><Icone nome="impressora" tamanho={16} /> Imprimir</button>
                <a href={csv} className="btn btn-outline-secondary nao-imprimir">Baixar planilha</a>
            </TituloPagina>

            <div className="row g-3 mb-3 resumo-mensalistas">
                <div className="col-6 col-md-3"><div className="card card-body h-100">
                    <div className="text-muted small">{receita ? 'Receita mensal' : 'Mensalistas'}</div>
                    <div className="fs-4 fw-bold text-success">{receita ? moeda(total.valor) : total.qtd}</div>
                    <div className="small text-muted">{total.qtd} veículo(s) mensalista(s)</div>
                </div></div>
                {Object.entries(grupos).map(([g, nome]) => (
                    <div key={g} className="col-6 col-md-3"><div className="card card-body h-100">
                        <div className="text-muted small">{nome}</div>
                        <div className="fs-4 fw-bold">{porGrupo[g].qtd}</div>
                        {receita && <div className="small text-muted">{moeda(porGrupo[g].valor)} por mês</div>}
                    </div></div>
                ))}
            </div>

            <div className="card mb-3">
                <div className="table-responsive">
                    <table className="table align-middle mb-0 imp-tabela">
                        <thead><tr><th>Tipo</th>
                            {Object.entries(periodos).map(([p, nome]) => [<th key={p} className="text-end num">{nome}</th>, receita && <th key={p + 'r'} className="text-end num">R$ {nome}</th>])}
                            <th className="text-end num">Total</th>{receita && <th className="text-end num">R$ Total</th>}
                        </tr></thead>
                        <tbody>
                            {Object.entries(grupos).map(([g, nome]) => (
                                <tr key={g}><td className="fw-semibold">{nome}</td>
                                    {Object.keys(periodos).map((p) => [<td key={p} className="text-end num">{controle[g][p].qtd}</td>, receita && <td key={p + 'r'} className="text-end num">{controle[g][p].valor ? moeda(controle[g][p].valor) : '—'}</td>])}
                                    <td className="text-end num fw-semibold">{porGrupo[g].qtd}</td>{receita && <td className="text-end num fw-semibold">{moeda(porGrupo[g].valor)}</td>}
                                </tr>
                            ))}
                        </tbody>
                        <tfoot><tr className="fw-semibold"><td>Total</td>
                            {Object.keys(periodos).map((p) => [<td key={p} className="text-end num">{porPeriodo[p].qtd}</td>, receita && <td key={p + 'r'} className="text-end num">{moeda(porPeriodo[p].valor)}</td>])}
                            <td className="text-end num">{total.qtd}</td>{receita && <td className="text-end num">{moeda(total.valor)}</td>}
                        </tr></tfoot>
                    </table>
                </div>
            </div>

            <div className="d-flex flex-wrap align-items-center gap-2 mb-2 nao-imprimir" role="group" aria-label="Tipos de veículo">
                <span className="small text-muted me-1">Tipos de veículo:</span>
                <button type="button" className={`btn btn-sm rounded-pill ${todosMarcados ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => filtrar([])} aria-pressed={todosMarcados}>Todos</button>
                {tipos.map((t) => (
                    <button key={t.id} type="button" className={`btn btn-sm rounded-pill ${!todosMarcados && marcado(t.id) ? 'btn-primary' : 'btn-outline-secondary'}`} aria-pressed={!todosMarcados && marcado(t.id)}
                        onClick={() => (todosMarcados ? filtrar([t.id]) : alternar(t.id))}>
                        {t.nome} <span className="opacity-75">{t.qtd}</span>
                    </button>
                ))}
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0 imp-tabela">
                        <thead><tr><th>Cliente</th><th>Veículo</th><th className="d-none d-md-table-cell d-print-table-cell">Tipo</th><th>Período</th>{receita && <th className="text-end num">Mensalidade</th>}</tr></thead>
                        <tbody>
                            {lista.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum mensalista neste filtro.</td></tr>}
                            {lista.map((l) => (
                                <tr key={l.contrato_id}>
                                    <td><Link href={`/clientes/${l.cliente_id}`} className="fw-semibold text-body text-decoration-none">{l.cliente}</Link><small className="d-block text-muted">Cód. {l.cliente_id}</small></td>
                                    <td>{l.veiculo}{l.descricao && <small className="d-block text-muted">{l.descricao}</small>}</td>
                                    <td className="d-none d-md-table-cell d-print-table-cell">{l.tipo}</td>
                                    <td>{l.periodo}</td>
                                    {receita && <td className="text-end num text-nowrap">{moeda(l.valor)}</td>}
                                </tr>
                            ))}
                        </tbody>
                        {lista.length > 0 && <tfoot><tr className="fw-semibold"><td colSpan={receita ? 4 : 4} className="d-none d-md-table-cell d-print-table-cell">{lista.length} veículo(s)</td><td colSpan={3} className="d-md-none d-print-none">{lista.length} veículo(s)</td>{receita && <td className="text-end num">{moeda(totalLista)}</td>}</tr></tfoot>}
                    </table>
                </div>
            </div>
            <p className="small text-muted mt-2 nao-imprimir">Conta os veículos com mensalidade maior que zero, de clientes e veículos ativos. Mensalidade pausada continua contando.</p>
        </div>
    )
}
