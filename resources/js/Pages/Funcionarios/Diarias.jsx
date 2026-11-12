import { Link, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasFuncionarios from '../../Components/AbasFuncionarios'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import Janela from '../../Components/Janela'
import Confirmar from '../../Components/Confirmar'
import Icone from '../../Components/Icone'
import { data as fData, moeda } from '../../lib/formato'
import { CamposPagamento, hoje } from '../Pagar/Comum'

const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb']
const diaSemana = (d) => DIAS[new Date(d + 'T12:00').getDay()]

export default function Diarias({ avulsos, abertas, pagas, formas, contas_financeiras }) {
    const lancar = useForm({ funcionario_id: '', datas: [hoje()], valor: '', observacao: '' })
    const [outroDia, setOutroDia] = useState('')
    const pix = formas.find((x) => /pix/i.test(x.nome)) || formas[0]
    const pagar = useForm({ funcionario_id: '', ids: [], pago: true, vencimento: hoje(), pago_em: hoje(), valor_pago: '', forma_pagamento_id: pix?.id ?? '', conta_financeira_id: pix?.conta_financeira_id ?? '' })
    const [pagando, setPagando] = useState(null) // grupo
    const [removendo, setRemovendo] = useState(null)
    const [marcadas, setMarcadas] = useState({}) // id -> bool (padrão: marcada)

    const grupos = useMemo(() => {
        const g = {}
        abertas.forEach((d) => { (g[d.funcionario_id] ||= { id: d.funcionario_id, nome: d.funcionario, dias: [] }).dias.push(d) })
        return Object.values(g).sort((a, b) => a.nome.localeCompare(b.nome))
    }, [abertas])
    const marcada = (d) => marcadas[d.id] !== false

    const escolherFuncionario = (id) => {
        const f = avulsos.find((x) => x.id === Number(id))
        lancar.setData((d) => ({ ...d, funcionario_id: id, valor: f?.valor_diaria ?? d.valor }))
    }
    const adicionarDia = (v) => {
        setOutroDia(v)
        if (v && !lancar.data.datas.includes(v)) { lancar.setData('datas', [...lancar.data.datas, v].sort()); setOutroDia('') }
    }
    const enviarDiarias = (e) => {
        e.preventDefault()
        lancar.post('/funcionarios/diarias', { preserveScroll: true, onSuccess: () => lancar.setData((d) => ({ ...d, datas: [hoje()], observacao: '' })) })
    }
    const abrirPagamento = (g) => {
        const ids = g.dias.filter(marcada).map((d) => d.id)
        pagar.clearErrors()
        pagar.setData((d) => ({ ...d, funcionario_id: g.id, ids, valor_pago: '' }))
        setPagando({ ...g, total: g.dias.filter(marcada).reduce((s, d) => s + d.valor, 0), qtd: ids.length })
    }
    const enviarPagamento = () => pagar.post('/funcionarios/diarias/pagar', { preserveScroll: true, onSuccess: () => { setPagando(null); setMarcadas({}) } })

    return (
        <div className="pagina">
            <AbasFuncionarios />
            <TituloPagina titulo="Diárias" descricao="Lance os dias que cada avulso trabalhou. Na hora de pagar, junte as diárias num pagamento: ele entra em Contas a pagar." />

            {avulsos.length === 0 ? (
                <div className="alert alert-info">Nenhum funcionário avulso cadastrado. <Link href="/funcionarios">Cadastre em Funcionários</Link>, com o tipo "Avulso".</div>
            ) : (
                <form className="card mb-3" onSubmit={enviarDiarias} noValidate>
                    <div className="card-header">Lançar diária</div>
                    <div className="card-body">
                        <div className="row g-2">
                            <div className="col-md-5">
                                <Campo as="select" rotulo="Funcionário" value={lancar.data.funcionario_id} onChange={(e) => escolherFuncionario(e.target.value)} erro={lancar.errors.funcionario_id}>
                                    <option value="">Escolha</option>
                                    {avulsos.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                </Campo>
                            </div>
                            <div className="col-md-3"><CampoValor rotulo="Valor por dia" value={lancar.data.valor} onChange={(v) => lancar.setData('valor', v)} erro={lancar.errors.valor} /></div>
                            <div className="col-md-4"><CampoData rotulo="Adicionar dia" value={outroDia} onChange={adicionarDia} erro={lancar.errors.datas || lancar.errors['datas.0']} /></div>
                        </div>
                        <div className="d-flex flex-wrap gap-2 mb-3">
                            {lancar.data.datas.map((d) => (
                                <span key={d} className="badge rounded-pill text-bg-light border d-inline-flex align-items-center gap-1 fw-normal fs-6">
                                    {diaSemana(d)} {fData(d)}
                                    <button type="button" className="btn-close btn-sm ms-1" style={{ fontSize: '.6rem' }} aria-label={`Tirar ${fData(d)}`} onClick={() => lancar.setData('datas', lancar.data.datas.filter((x) => x !== d))} />
                                </span>
                            ))}
                        </div>
                        <Campo rotulo="Observação (opcional)" value={lancar.data.observacao} onChange={(e) => lancar.setData('observacao', e.target.value)} erro={lancar.errors.observacao} maxLength={120} />
                    </div>
                    <div className="card-footer d-flex justify-content-end align-items-center gap-2">
                        {lancar.data.datas.length > 0 && Number(lancar.data.valor) > 0 && <span className="small text-muted me-auto">{lancar.data.datas.length} dia{lancar.data.datas.length > 1 ? 's' : ''} · {moeda(lancar.data.datas.length * Number(lancar.data.valor))}</span>}
                        <button type="submit" className="btn btn-primary" disabled={lancar.processing || !lancar.data.datas.length}>Lançar</button>
                    </div>
                </form>
            )}

            <h2 className="h6 text-muted mt-4">A pagar</h2>
            {grupos.length === 0 && <p className="text-muted small">Nenhuma diária a pagar.</p>}
            {grupos.map((g) => {
                const sel = g.dias.filter(marcada)
                return (
                    <div key={g.id} className="card mb-3">
                        <div className="card-header d-flex flex-wrap align-items-center gap-2">
                            <strong className="me-auto">{g.nome}</strong>
                            <span className="small">{sel.length} de {g.dias.length} · <strong>{moeda(sel.reduce((s, d) => s + d.valor, 0))}</strong></span>
                            <button type="button" className="btn btn-sm btn-primary" disabled={!sel.length} onClick={() => abrirPagamento(g)}>Pagar</button>
                        </div>
                        <ul className="list-group list-group-flush">
                            {g.dias.map((d) => (
                                <li key={d.id} className="list-group-item d-flex align-items-center gap-2">
                                    <input type="checkbox" className="form-check-input" checked={marcada(d)} onChange={(e) => setMarcadas((m) => ({ ...m, [d.id]: e.target.checked }))} aria-label={`Incluir ${fData(d.data)}`} />
                                    <span className="me-auto">{diaSemana(d.data)} {fData(d.data)}{d.observacao && <span className="small text-muted"> · {d.observacao}</span>}{d.situacao === 'cancelada' && <span className="selo ms-1">pagamento cancelado</span>}</span>
                                    <span>{moeda(d.valor)}</span>
                                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setRemovendo(d)} aria-label={`Remover diária de ${fData(d.data)}`}><Icone nome="lixeira" tamanho={16} /></button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )
            })}

            {pagas.length > 0 && (
                <>
                    <h2 className="h6 text-muted mt-4">Já pagas ou em conta a pagar (últimos 60 dias)</h2>
                    <div className="card">
                        <ul className="list-group list-group-flush">
                            {pagas.map((d) => (
                                <li key={d.id} className="list-group-item d-flex gap-2 small">
                                    <span className="me-auto">{d.funcionario} · {diaSemana(d.data)} {fData(d.data)}</span>
                                    <span>{moeda(d.valor)}</span>
                                    <span className={`selo ${d.situacao === 'paga' ? 'selo-ok' : ''}`}>{d.situacao === 'paga' ? 'Paga' : 'A pagar'}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </>
            )}

            <Janela aberta={!!pagando} titulo={pagando ? `Pagar ${pagando.nome}` : ''} rotuloOk={pagar.data.pago ? 'Registrar pagamento' : 'Criar conta a pagar'} ocupado={pagar.processing} onFechar={() => setPagando(null)} onEnviar={enviarPagamento}>
                {pagando && <p className="mb-3">{pagando.qtd} diária{pagando.qtd > 1 ? 's' : ''} · <strong>{moeda(pagando.total)}</strong></p>}
                {pagar.errors.ids && <div className="alert alert-danger py-2 small">{pagar.errors.ids}</div>}
                <div className="form-check form-switch mb-3">
                    <input className="form-check-input" type="checkbox" id="diaria-paga" checked={pagar.data.pago} onChange={(e) => pagar.setData('pago', e.target.checked)} />
                    <label className="form-check-label" htmlFor="diaria-paga">Já paguei</label>
                </div>
                {pagar.data.pago
                    ? <CamposPagamento form={pagar} formas={formas} contasFinanceiras={contas_financeiras} valorPrevisto comComprovante={false} />
                    : <CampoData rotulo="Pagar até" value={pagar.data.vencimento} onChange={(v) => pagar.setData('vencimento', v)} erro={pagar.errors.vencimento} />}
            </Janela>

            <Confirmar aberto={!!removendo} titulo="Remover diária" perigo rotuloOk="Remover" onCancelar={() => setRemovendo(null)}
                onOk={() => router.delete(`/funcionarios/diarias/${removendo.id}`, { preserveScroll: true, onFinish: () => setRemovendo(null) })}>
                {removendo && <>Remover a diária de <strong>{removendo.funcionario}</strong> em {fData(removendo.data)} ({moeda(removendo.valor)})?</>}
            </Confirmar>
        </div>
    )
}
