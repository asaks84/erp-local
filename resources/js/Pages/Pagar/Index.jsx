import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPagar from '../../Components/AbasPagar'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import Icone from '../../Components/Icone'
import { data as fData, moeda } from '../../lib/formato'
import { competencia } from '../../Components/Dinheiro'
import { CamposPagamento, SeloDespesa, hoje } from './Comum'

function Numero({ rotulo, r, classe = '', ativo, onClick }) {
    return (
        <div className="col-6 col-lg-3">
            <button type="button" className={`card h-100 w-100 text-start${ativo ? ' border-primary' : ''}`} onClick={onClick}>
                <div className="card-body">
                    <div className="text-muted small">{rotulo}</div>
                    <div className={`fs-4 fw-bold ${classe}`}>{typeof r === 'number' ? moeda(r) : moeda(r.valor)}</div>
                    {typeof r !== 'number' && <div className="small text-muted">{r.qtd} conta(s)</div>}
                </div>
            </button>
        </div>
    )
}

export default function PagarIndex({ despesas, filtros, resumo, fornecedores, formas, contas_financeiras }) {
    const [f, setF] = useState(filtros)
    const primeira = useRef(true)
    useEffect(() => {
        if (primeira.current) { primeira.current = false; return }
        const t = setTimeout(() => {
            router.get('/pagar', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true })
        }, 300)
        return () => clearTimeout(t)
    }, [f])
    const muda = (k) => (e) => setF((x) => ({ ...x, [k]: e?.target ? e.target.value : e }))

    const [pagando, setPagando] = useState(null)
    const pix = formas.find((x) => /pix/i.test(x.nome)) || formas[0]
    const pg = useForm({ pago_em: hoje(), valor_pago: '', forma_pagamento_id: pix?.id ?? '', conta_financeira_id: pix?.conta_financeira_id ?? '', comprovante: null })
    const abrirPagar = (x) => { pg.clearErrors(); pg.setData({ ...pg.data, pago_em: hoje(), valor_pago: '', comprovante: null }); setPagando(x) }

    const [acao, setAcao] = useState(null) // { tipo: 'cancelar'|'desfazer', x }
    const mt = useForm({ motivo: '' })

    const Acoes = ({ x }) => (
        <>
            {x.comprovante && <a href={`/pagar/${x.id}/comprovante`} target="_blank" rel="noreferrer" className="btn btn-sm btn-link">Comprovante</a>}
            {x.situacao === 'aberta' && <>
                <button type="button" className="btn btn-sm btn-primary" onClick={() => abrirPagar(x)}>Pagar</button>
                <Link href={`/pagar/${x.id}/editar`} className="btn btn-sm btn-outline-secondary ms-1" aria-label={`Editar ${x.descricao}`}><Icone nome="lapis" tamanho={16} /></Link>
                <button type="button" className="btn btn-sm btn-link text-muted" onClick={() => { mt.reset(); setAcao({ tipo: 'cancelar', x }) }}>Cancelar</button>
            </>}
            {x.situacao === 'paga' && <button type="button" className="btn btn-sm btn-link text-muted" onClick={() => { mt.reset(); setAcao({ tipo: 'desfazer', x }) }}>Desfazer pagamento</button>}
        </>
    )

    return (
        <div className="pagina">
            <AbasPagar />
            <TituloPagina titulo="Contas a pagar" descricao="Despesas da empresa: lance quando a conta chegar e registre o pagamento com o valor de fato e de onde saiu o dinheiro.">
                <Link href="/pagar/nova" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Nova conta</Link>
            </TituloPagina>

            <div className="row g-3 mb-3">
                <Numero rotulo="Atrasadas" r={resumo.atrasadas} classe={resumo.atrasadas.qtd ? 'text-danger' : ''} ativo={f.estado === 'atrasadas'} onClick={() => setF((x) => ({ ...x, estado: 'atrasadas' }))} />
                <Numero rotulo="Vencem hoje" r={resumo.hoje} ativo={f.estado === 'hoje'} onClick={() => setF((x) => ({ ...x, estado: 'hoje' }))} />
                <Numero rotulo="Próximos 7 dias" r={resumo.semana} ativo={false} onClick={() => setF((x) => ({ ...x, estado: 'abertas' }))} />
                <Numero rotulo="Pago no mês" r={resumo.pago_mes} ativo={f.estado === 'pagas'} onClick={() => setF((x) => ({ ...x, estado: 'pagas' }))} />
            </div>

            <div className="row g-2 mb-1">
                <div className="col-md-4"><input type="search" className="form-control" placeholder="Descrição ou fornecedor" value={f.q} onChange={muda('q')} aria-label="Buscar" /></div>
                <div className="col-6 col-md-2">
                    <select className="form-select" value={f.estado} onChange={muda('estado')} aria-label="Situação">
                        <option value="abertas">A pagar</option><option value="atrasadas">Atrasadas</option><option value="hoje">Vencem hoje</option>
                        <option value="pagas">Pagas</option><option value="canceladas">Canceladas</option><option value="todas">Todas</option>
                    </select>
                </div>
                <div className="col-6 col-md-2">
                    <select className="form-select" value={f.fornecedor_id} onChange={muda('fornecedor_id')} aria-label="Fornecedor">
                        <option value="">Todos os fornecedores</option>
                        {fornecedores.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-2"><CampoData rotulo={f.estado === 'pagas' ? 'Pago de' : 'Vence de'} value={f.de} onChange={muda('de')} className="mb-2" /></div>
                <div className="col-6 col-md-2"><CampoData rotulo="Até" value={f.ate} onChange={muda('ate')} className="mb-2" /></div>
            </div>

            <div className="card d-none d-md-block">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>{f.estado === 'pagas' ? 'Pago em' : 'Vencimento'}</th><th>Conta</th><th className="text-end">Valor</th><th>Situação</th><th className="text-end">Ações</th></tr></thead>
                        <tbody>
                            {despesas.data.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma conta aqui.</td></tr>}
                            {despesas.data.map((x) => (
                                <tr key={x.id}>
                                    <td className="text-nowrap">{fData(x.situacao === 'paga' ? x.pago_em : x.vencimento)}</td>
                                    <td>
                                        <div className="fw-semibold">{x.descricao}{x.parcela && <span className="text-muted fw-normal"> · {x.parcela}</span>}{x.recorrente && <span className="selo ms-1">fixa</span>}</div>
                                        <div className="small text-muted">{[x.fornecedor?.nome, x.conta, `ref. ${competencia(x.competencia)}`].filter(Boolean).join(' · ')}</div>
                                        {x.situacao === 'paga' && <div className="small text-muted">{x.forma} · saiu de {x.conta_financeira}</div>}
                                        {x.situacao === 'cancelada' && x.motivo_cancelamento && <div className="small text-muted">{x.motivo_cancelamento}</div>}
                                    </td>
                                    <td className="text-end text-nowrap">
                                        {moeda(x.situacao === 'paga' ? x.valor_pago : x.valor)}
                                        {x.situacao === 'paga' && x.valor_pago !== x.valor && <div className="small text-muted">previsto {moeda(x.valor)}</div>}
                                    </td>
                                    <td><SeloDespesa estado={x.estado} /></td>
                                    <td className="text-end text-nowrap"><Acoes x={x} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            {/* celular: lista em vez de tabela */}
            <div className="card d-md-none">
                <ul className="list-group list-group-flush">
                    {despesas.data.length === 0 && <li className="list-group-item text-center text-muted py-4">Nenhuma conta aqui.</li>}
                    {despesas.data.map((x) => (
                        <li key={x.id} className="list-group-item">
                            <div className="d-flex justify-content-between gap-2">
                                <div className="fw-semibold">{x.descricao}{x.parcela && <span className="text-muted fw-normal"> · {x.parcela}</span>}</div>
                                <div className="fw-semibold text-nowrap">{moeda(x.situacao === 'paga' ? x.valor_pago : x.valor)}</div>
                            </div>
                            <div className="small text-muted">{[x.fornecedor?.nome, x.conta].filter(Boolean).join(' · ')}</div>
                            <div className="d-flex align-items-center gap-2 mt-1">
                                <span className="small">{x.situacao === 'paga' ? `pago ${fData(x.pago_em)}` : `vence ${fData(x.vencimento)}`}</span>
                                <SeloDespesa estado={x.estado} />{x.recorrente && <span className="selo">fixa</span>}
                            </div>
                            <div className="mt-2"><Acoes x={x} /></div>
                        </li>
                    ))}
                </ul>
            </div>
            <Paginacao pagina={despesas} />

            <Janela aberta={!!pagando} titulo={`Pagar · ${pagando?.descricao ?? ''}`} rotuloOk="Registrar pagamento" ocupado={pg.processing} onFechar={() => setPagando(null)}
                onEnviar={() => pg.post(`/pagar/${pagando.id}/pagar`, { forceFormData: true, preserveScroll: true, onSuccess: () => setPagando(null) })}>
                <p className="mb-3">Valor da conta: <strong>{pagando && moeda(pagando.valor)}</strong> · vence {pagando && fData(pagando.vencimento)}</p>
                <CamposPagamento form={pg} formas={formas} contasFinanceiras={contas_financeiras} valorPrevisto />
            </Janela>

            <Janela aberta={!!acao} perigo titulo={acao?.tipo === 'cancelar' ? `Cancelar · ${acao?.x.descricao}` : `Desfazer pagamento · ${acao?.x.descricao}`}
                rotuloOk={acao?.tipo === 'cancelar' ? 'Cancelar conta' : 'Desfazer pagamento'} ocupado={mt.processing} onFechar={() => setAcao(null)}
                onEnviar={() => mt.post(`/pagar/${acao.x.id}/${acao.tipo}`, { preserveScroll: true, onSuccess: () => setAcao(null) })}>
                <p>{acao?.tipo === 'cancelar' ? 'A conta deixa de ser cobrada e fica registrada como cancelada.' : 'A conta volta para "a pagar" (o pagamento lançado por engano sai).'}</p>
                <Campo rotulo="Motivo" value={mt.data.motivo} onChange={(e) => mt.setData('motivo', e.target.value)} erro={mt.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
