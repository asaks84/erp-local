import { Link, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPagar from '../../Components/AbasPagar'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import { data as fData, mesAno, moeda } from '../../lib/formato'
import { SeloDespesa } from './Comum'

export default function Fatura({ fatura, itens: inicial, contas }) {
    const pessoal = fatura.tipo === 'pessoal'
    const [itens, setItens] = useState(inicial)
    const [soSemConta, setSoSemConta] = useState(false)
    const [salvando, setSalvando] = useState(false)
    const [erros, setErros] = useState({})
    const desf = useForm({ motivo: '' })
    const [desfazendo, setDesfazendo] = useState(false)

    const mudar = (id, campo, v) => setItens((l) => {
        const alvo = l.find((i) => i.id === id)
        return l.map((i) => {
            if (i.id === id) return { ...i, [campo]: v, ...(campo === 'conta_id' && v && pessoal ? { da_empresa: true } : {}) }
            // loja de um tipo só: a mesma conta vale para as outras compras dela ainda sem conta
            if (campo === 'conta_id' && v && !alvo.perguntar && i.estabelecimento === alvo.estabelecimento && !i.conta_id) return { ...i, conta_id: v }
            return i
        })
    })
    const soma = (f) => itens.filter(f).reduce((s, i) => s + i.valor, 0)
    const resumo = useMemo(() => ({
        empresa: soma((i) => i.da_empresa),
        semConta: itens.filter((i) => i.da_empresa && !i.conta_id).length,
        fora: soma((i) => !i.da_empresa),
    }), [itens]) // eslint-disable-line react-hooks/exhaustive-deps
    const visiveis = soSemConta ? itens.filter((i) => i.da_empresa && !i.conta_id) : itens
    const mudou = JSON.stringify(itens) !== JSON.stringify(inicial)

    function salvar() {
        const corpo = Object.fromEntries(itens.map((i) => [i.id, { conta_id: i.conta_id || null, descricao: i.descricao, da_empresa: i.da_empresa }]))
        router.put(`/pagar/faturas/${fatura.id}`, { itens: corpo }, {
            preserveScroll: true, onStart: () => setSalvando(true), onFinish: () => setSalvando(false),
            onError: setErros, onSuccess: () => setErros({}),
        })
    }

    return (
        <div className="pagina">
            <AbasPagar />
            <TituloPagina titulo={`${fatura.cartao} · ${mesAno(fatura.fechamento)}`}
                descricao={pessoal ? 'Cartão pessoal: marque as compras que são da empresa e escolha a conta de cada uma.' : 'Escolha a conta de cada compra. Em marketplace (Mercado Livre, Amazon...) escreva o que foi.'} />

            <div className="row g-3 mb-3">
                <div className="col-md-4"><div className="card h-100"><div className="card-body small">
                    <div>Fechamento {fData(fatura.fechamento)}{fatura.inicio ? ` (desde ${fData(fatura.inicio)})` : ''}</div>
                    <div>Vencimento <strong>{fData(fatura.vencimento)}</strong></div>
                    {fatura.arquivo && <a href={`/pagar/faturas/${fatura.id}/arquivo`}>Baixar o arquivo importado</a>}
                </div></div></div>
                <div className="col-md-4"><div className="card h-100"><div className="card-body small">
                    <div className="d-flex justify-content-between"><span>{pessoal ? 'Compras da empresa' : 'Total das compras'}</span><strong>{moeda(pessoal ? resumo.empresa : fatura.total)}</strong></div>
                    {!pessoal && resumo.fora > 0 && <div className="d-flex justify-content-between"><span>Não é da empresa</span><span>{moeda(resumo.fora)}</span></div>}
                    {fatura.saldo_arquivo !== null && Math.abs(fatura.saldo_arquivo - fatura.total) >= 0.01 && !pessoal &&
                        <div className="text-muted">O banco cobra {moeda(fatura.saldo_arquivo)} ({moeda(fatura.saldo_arquivo - fatura.total)} de diferença de arredondamento ou encargos).</div>}
                    <div className="d-flex justify-content-between"><span>Sem conta</span><span className={resumo.semConta ? 'text-danger fw-semibold' : ''}>{resumo.semConta}</span></div>
                </div></div></div>
                <div className="col-md-4"><div className="card h-100"><div className="card-body small">
                    {fatura.despesa
                        ? <>
                            <div className="mb-1">{fatura.despesa.pelo_socio ? <span className="selo">Paga pelo sócio</span> : <SeloDespesa estado={fatura.despesa.estado} />} <strong className="ms-1">{moeda(fatura.despesa.valor)}</strong></div>
                            <div className="text-muted">{fatura.despesa.pelo_socio ? 'Conta no resultado; não sai do caixa da empresa.' : 'A conta a pagar desta fatura. Pague por Contas a pagar.'}</div>
                            {!fatura.despesa.pelo_socio && <Link href={`/pagar?estado=todas&q=${encodeURIComponent(fatura.cartao)}`}>Ver em Contas a pagar</Link>}
                        </>
                        : <div className="text-muted">Nenhuma compra marcada como da empresa ainda.</div>}
                </div></div></div>
            </div>

            <div className="d-flex flex-wrap align-items-center gap-3 mb-2">
                <div className="form-check form-switch mb-0">
                    <input className="form-check-input" type="checkbox" id="so-sem-conta" checked={soSemConta} onChange={(e) => setSoSemConta(e.target.checked)} />
                    <label className="form-check-label" htmlFor="so-sem-conta">Só as sem conta</label>
                </div>
            </div>

            <div className="card">
                <div className="list-group list-group-flush fatura-itens">
                    {visiveis.length === 0 && <div className="list-group-item text-muted text-center py-4">Tudo classificado.</div>}
                    {visiveis.map((i) => (
                        <div key={i.id} className={`list-group-item${i.da_empresa ? '' : ' fora'}`}>
                            <div className="d-flex flex-wrap gap-2 align-items-baseline">
                                <span className="small text-muted text-nowrap">{fData(i.data)}</span>
                                <span className="fw-semibold me-auto">{i.original}{i.parcela && <span className="selo ms-2">parcela {i.parcela}</span>}{i.perguntar && i.da_empresa && <span className="selo selo-aviso ms-2">loja de tudo: diga o que foi</span>}</span>
                                <span className={`text-nowrap fw-semibold${i.valor < 0 ? ' text-success' : ''}`}>{i.valor < 0 ? 'estorno ' : ''}{moeda(Math.abs(i.valor))}</span>
                            </div>
                            <div className="row g-2 mt-1 align-items-center">
                                <div className="col-md-5">
                                    <select className={`form-select form-select-sm${i.da_empresa && !i.conta_id ? ' border-warning' : ''}${erros[`itens.${i.id}.conta_id`] ? ' is-invalid' : ''}`} value={i.conta_id} disabled={!i.da_empresa}
                                        onChange={(e) => mudar(i.id, 'conta_id', e.target.value ? Number(e.target.value) : '')} aria-label={`Conta de ${i.original}`}>
                                        <option value="">Escolha a conta</option>
                                        {contas.map((c) => <option key={c.id} value={c.id}>{c.rotulo}</option>)}
                                    </select>
                                </div>
                                <div className="col-md-5">
                                    <input className="form-control form-control-sm" placeholder="O que foi (ex.: Telhas da cobertura)" maxLength={120} value={i.descricao} disabled={!i.da_empresa}
                                        onChange={(e) => mudar(i.id, 'descricao', e.target.value)} aria-label={`O que foi: ${i.original}`} />
                                </div>
                                <div className="col-md-2">
                                    <div className="form-check form-switch mb-0">
                                        <input className="form-check-input" type="checkbox" id={`emp-${i.id}`} checked={i.da_empresa} onChange={(e) => mudar(i.id, 'da_empresa', e.target.checked)} />
                                        <label className="form-check-label small" htmlFor={`emp-${i.id}`}>Da empresa</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
                <div className="card-footer d-flex flex-wrap gap-2 justify-content-end align-items-center barra-salvar">
                    {fatura.despesa?.estado !== 'paga' || fatura.despesa?.pelo_socio
                        ? <button type="button" className="btn btn-link text-danger me-auto px-0" onClick={() => setDesfazendo(true)}>Desfazer importação</button>
                        : <span className="me-auto" />}
                    {mudou && <span className="small text-muted">Alterações não salvas</span>}
                    <button type="button" className="btn btn-primary" disabled={salvando || !mudou} onClick={salvar}>Salvar classificação</button>
                </div>
            </div>
            <p className="small text-muted mt-2">A loja classificada sempre na mesma conta passa a vir classificada nas próximas faturas; parcelas seguem a classificação da anterior. Marketplaces são perguntados a cada compra.</p>

            <Janela aberta={desfazendo} titulo="Desfazer importação" perigo rotuloOk="Desfazer" ocupado={desf.processing} onFechar={() => setDesfazendo(false)}
                onEnviar={() => desf.post(`/pagar/faturas/${fatura.id}/desfazer`)}>
                <p>A fatura e a classificação são apagadas, e a conta a pagar dela é cancelada. Use quando importou o arquivo errado.</p>
                <Campo rotulo="Motivo" value={desf.data.motivo} onChange={(e) => desf.setData('motivo', e.target.value)} erro={desf.errors.motivo} maxLength={150} autoFocus />
            </Janela>
        </div>
    )
}
