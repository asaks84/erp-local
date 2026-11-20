import { Link, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import { moeda } from '../../lib/formato'
import EscolhasNota from '../../Components/EscolhasNota'
import { avisar, postarJson } from '../../lib/json'

const SELO = { emitida: 'selo-ok', enviando: 'selo-aviso', rejeitada: 'selo-erro', cancelada: '' }
const NOME = { emitida: 'Emitida', enviando: 'Enviando', rejeitada: 'Rejeitada', cancelada: 'Cancelada' }

function Cancelar({ nota, onFechar }) {
    const form = useForm({ motivo: '', codigo: '1' })
    return (
        <Janela aberta titulo={`Cancelar a NFS-e ${nota.numero || ''}`} rotuloOk="Cancelar a nota" perigo onFechar={onFechar} ocupado={form.processing}
            onEnviar={() => form.post(`/notas-fiscais/${nota.id}/cancelar`, { preserveScroll: true, onSuccess: onFechar })}>
            <p className="small">{nota.tomador || 'Consumidor'} · {moeda(nota.valor)}. O cancelamento vai para o Sistema Nacional e não tem volta.</p>
            <Campo as="select" rotulo="Motivo" value={form.data.codigo} onChange={(e) => form.setData('codigo', e.target.value)}>
                <option value="1">Erro na emissão</option>
                <option value="2">Serviço não prestado</option>
                <option value="9">Outros</option>
            </Campo>
            <Campo as="textarea" rotulo="Explique" style={{ height: 90 }} maxLength={255} value={form.data.motivo} erro={form.errors.motivo} onChange={(e) => form.setData('motivo', e.target.value)} />
        </Janela>
    )
}

// "Mensalidade · Mensalidade Diurno" vira "Mensalidade Diurno"
const nomeServico = (s) => (s.descricao?.toLowerCase().startsWith(s.tipo.split('/')[0].toLowerCase()) ? s.descricao : `${s.tipo} · ${s.descricao}`)
const mesAno = (c) => (c ? `${c.slice(5, 7)}/${c.slice(0, 4)}` : '')
const pct = (v) => `${String(v).replace('.', ',')}%`

/** as duas alíquotas do e-mail mensal da contabilidade, por competência */
function Aliquotas({ aliquotas, competenciaAtual, faltam }) {
    const deMes = (c) => aliquotas.find((a) => a.competencia === c)
    const inicial = faltam[0] || competenciaAtual
    // máscara de maquininha (CampoValor), como os juros do Banco Inter: 0,00 e os números entram pelos centavos
    const doMes = (c) => ({ competencia: c, iss: Number(deMes(c)?.iss) || 0, tributos: Number(deMes(c)?.tributos) || 0 })
    const form = useForm(doMes(inicial))
    const trocarMes = (c) => form.setData(doMes(c))
    const salvo = deMes(form.data.competencia)
    return (
        <div className={`card mb-3${faltam.length ? ' border-warning' : ''}`}>
            <div className="card-header d-flex flex-wrap gap-2 align-items-center">
                <span>Alíquotas do mês</span>
                <span className="small text-muted">do e-mail da contabilidade</span>
                {faltam.length > 0 && <span className="selo selo-aviso ms-auto">Falta: {faltam.map(mesAno).join(', ')}</span>}
            </div>
            <div className="card-body">
                <form className="row g-2 align-items-start" noValidate onSubmit={(e) => { e.preventDefault(); form.put('/notas-fiscais/aliquotas', { preserveScroll: true }) }}>
                    <div className="col-6 col-md-3"><CampoData mes rotulo="Competência" value={form.data.competencia} erro={form.errors.competencia} onChange={(v) => v && trocarMes(v)} /></div>
                    <div className="col-6 col-md-3"><CampoValor rotulo="Alíquota de ISS" prefixo="%" value={form.data.iss} erro={form.errors.iss} onChange={(v) => form.setData('iss', v)} ajuda="Só entra em nota com ISS retido." /></div>
                    <div className="col-6 col-md-3"><CampoValor rotulo="Tributos aprox." prefixo="%" value={form.data.tributos} erro={form.errors.tributos} onChange={(v) => form.setData('tributos', v)} ajuda="O “valor aproximado dos tributos” do e-mail." /></div>
                    <div className="col-6 col-md-3 d-flex flex-column">
                        <button type="submit" className="btn btn-outline-primary mt-md-4" disabled={form.processing}>{salvo ? 'Atualizar' : 'Salvar'}</button>
                        {salvo && <span className="small text-muted mt-1">{mesAno(salvo.competencia)}: ISS {pct(salvo.iss)} · tributos {pct(salvo.tributos)}</span>}
                    </div>
                </form>
            </div>
        </div>
    )
}

/** busca de serviços sem nota por OS, ticket ou placa; emite um de cada vez */
function Avulsos({ avulsos, filtros, faltando, retFederal, aliquota }) {
    const [q, setQ] = useState(filtros.q)
    const [emitindo, setEmitindo] = useState(null)
    const buscar = (e) => { e.preventDefault(); router.get('/notas-fiscais', { aba: 'avulsos', q }, { preserveState: true, replace: true }) }
    return (
        <>
            <form className="row g-2 mb-2" onSubmit={buscar}>
                <div className="col-md-6"><Campo rotulo="Número da OS, do ticket ou placa" value={q} onChange={(e) => setQ(e.target.value)} autoFocus /></div>
                <div className="col-md-2"><button type="submit" className="btn btn-primary w-100 mt-md-1 py-md-3">Buscar</button></div>
            </form>
            {filtros.q && (
                <div className="card">
                    <div className="table-responsive">
                        <table className="table align-middle mb-0">
                            <thead><tr><th>Serviço</th><th>Cliente</th><th className="text-end">Valor</th><th /></tr></thead>
                            <tbody>
                                {avulsos.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum serviço sem nota com “{filtros.q}”.</td></tr>}
                                {avulsos.map((o) => (
                                    <tr key={o.id}>
                                        <td>
                                            <strong>OS {o.numero}</strong>{o.ticket && <span className="small text-muted"> · ticket {o.ticket}</span>}
                                            <div className="small text-muted">{o.data} · {nomeServico(o)}{o.placa && ` · ${o.placa}`}{o.pago ? ' · pago' : ' · em aberto'}</div>
                                        </td>
                                        <td>{o.cliente ? o.cliente.nome : <span className="text-muted">Sem cadastro</span>}{o.cliente?.documento && <div className="small text-muted">{o.cliente.documento}</div>}</td>
                                        <td className="text-end text-nowrap">{moeda(o.valor)}</td>
                                        <td className="text-end"><button type="button" className="btn btn-sm btn-primary" disabled={!aliquota} onClick={() => setEmitindo(o)}>Emitir</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
            {emitindo && <EmitirAvulso servico={emitindo} faltando={faltando} retFederal={retFederal} aliquota={aliquota} onFechar={() => setEmitindo(null)} />}
        </>
    )
}

/** emissão de um serviço avulso: escolhas do cliente, ou CPF/CNPJ e nome digitados para quem não tem cadastro */
function EmitirAvulso({ servico: o, faltando, retFederal, aliquota, onFechar }) {
    const [esc, setEsc] = useState(o.cliente ? { identificar: o.cliente.identificar, retido: o.cliente.retido && o.cliente.pode_reter, retem_federal: retFederal > 0 && o.cliente.federal } : {})
    const [avulso, setAvulso] = useState({ documento: '', nome: '' })
    const [estado, setEstado] = useState(null)
    const emitir = async () => {
        setEstado({ ocupado: true })
        const r = await postarJson('/notas-fiscais/emitir', { ordens: [o.id], ...esc, avulso_documento: avulso.documento, avulso_nome: avulso.nome })
        if (r.situacao === 'erro' || r.situacao === 'rejeitada') return setEstado({ erro: r.mensagem })
        onFechar()
        avisar(r.situacao === 'emitida' ? 'ok' : 'aviso', r.mensagem)
        router.reload({ preserveScroll: true })
    }
    return (
        <Janela aberta titulo={`Emitir NFS-e · OS ${o.numero}`} rotuloOk="Emitir NFS-e" onFechar={onFechar} ocupado={estado?.ocupado || faltando.length > 0} onEnviar={emitir}>
            <div className="small mb-3">
                <div className="d-flex justify-content-between border-bottom py-1"><span>{nomeServico(o)}{o.placa && ` · ${o.placa}`}</span><span>{moeda(o.valor)}</span></div>
            </div>
            {o.cliente
                ? <EscolhasNota cliente={o.cliente} valor={o.valor} esc={esc} setEsc={setEsc} retFederal={retFederal} aliquota={aliquota} />
                : (
                    <>
                        <p className="small text-muted mb-2">Sem cadastro: deixe em branco para nota sem tomador, ou digite os dados de quem pediu a nota.</p>
                        <div className="row g-2">
                            <div className="col-md-5"><Campo rotulo="CPF/CNPJ (opcional)" inputMode="numeric" value={avulso.documento} onChange={(e) => setAvulso({ ...avulso, documento: e.target.value })} /></div>
                            <div className="col-md-7"><Campo rotulo="Nome" value={avulso.nome} onChange={(e) => setAvulso({ ...avulso, nome: e.target.value })} disabled={!avulso.documento} /></div>
                        </div>
                    </>
                )}
            {faltando.length > 0 && <div className="alert alert-warning py-2 small mt-2 mb-0">Para emitir, falta: {faltando.join(', ')}.</div>}
            {estado?.erro && <div className="alert alert-danger py-2 small mt-2 mb-0">{estado.erro}</div>}
        </Janela>
    )
}

/** aba Clientes: serviços sem nota no período, por cliente; marque e vá para conferir e emitir */
function Clientes({ clientes, filtros }) {
    const [marcados, setMarcados] = useState(() => new Set(clientes.filter((c) => c.sugerido).map((c) => c.id)))
    const [busca, setBusca] = useState('')
    const filtrar = (k, v) => router.get('/notas-fiscais', { aba: 'clientes', de: filtros.de, ate: filtros.ate, [k]: v }, { preserveState: false, replace: true })
    const visiveis = useMemo(() => {
        const b = busca.trim().toLowerCase()
        return b ? clientes.filter((c) => `${c.nome} ${c.documento || ''}`.toLowerCase().includes(b)) : clientes
    }, [clientes, busca])
    const alternar = (id) => setMarcados((m) => { const n = new Set(m); n.has(id) ? n.delete(id) : n.add(id); return n })
    const todosVisiveis = visiveis.length > 0 && visiveis.every((c) => marcados.has(c.id))
    const marcarVisiveis = () => setMarcados((m) => { const n = new Set(m); visiveis.forEach((c) => (todosVisiveis ? n.delete(c.id) : n.add(c.id))); return n })
    const sel = clientes.filter((c) => marcados.has(c.id))
    const total = sel.reduce((s, c) => s + c.total, 0)
    const continuar = () => router.get('/notas-fiscais/conferir', { clientes: sel.map((c) => c.id).join(','), de: filtros.de, ate: filtros.ate })
    return (
        <>
            <div className="row g-2 mb-1">
                <div className="col-6 col-md-3"><CampoData rotulo="Serviços de" value={filtros.de} onChange={(v) => v && filtrar('de', v)} /></div>
                <div className="col-6 col-md-3"><CampoData rotulo="Até" value={filtros.ate} onChange={(v) => v && filtrar('ate', v)} /></div>
                <div className="col-md-6"><Campo rotulo="Buscar cliente" value={busca} onChange={(e) => setBusca(e.target.value)} /></div>
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style={{ width: 40 }}><input className="form-check-input" type="checkbox" aria-label="Marcar todos" checked={todosVisiveis} onChange={marcarVisiveis} /></th>
                                <th>Cliente</th><th className="text-end">Serviços sem nota</th><th className="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {visiveis.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum serviço sem nota no período.</td></tr>}
                            {visiveis.map((c) => (
                                <tr key={c.id} onClick={() => alternar(c.id)} style={{ cursor: 'pointer' }} className={marcados.has(c.id) ? 'table-active' : undefined}>
                                    <td><input className="form-check-input" type="checkbox" checked={marcados.has(c.id)} onChange={() => alternar(c.id)} onClick={(e) => e.stopPropagation()} aria-label={`Marcar ${c.nome}`} /></td>
                                    <td>{c.nome}<div className="small text-muted">{c.documento || 'sem CPF/CNPJ'}{c.sugerido && ' · teve nota no mês passado'}</div></td>
                                    <td className="text-end">{c.qtd}</td>
                                    <td className="text-end text-nowrap">{moeda(c.total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
            <div className="barra-acoes d-flex flex-wrap align-items-center gap-2 mt-3 p-3 border rounded bg-body sticky-bottom">
                <span><strong>{sel.length}</strong> cliente{sel.length === 1 ? '' : 's'} · <strong>{moeda(total)}</strong> em notas</span>
                <button type="button" className="btn btn-primary ms-auto" disabled={!sel.length} onClick={continuar}>Conferir e emitir</button>
            </div>
        </>
    )
}

/** Gestão › Notas fiscais: Clientes (marcar e emitir), Avulsos (busca) e Notas (emitidas). */
export default function NotasFiscais({ aba, clientes, avulsos, notas, filtros, faltando, ambiente, aliquotas, competenciaAtual, retFederal }) {
    const [cancelar, setCancelar] = useState(null)
    const aliquotaAtual = aliquotas.find((a) => a.competencia === competenciaAtual)
    const irPara = (a) => router.get('/notas-fiscais', a === 'clientes' ? { aba: a, de: filtros.de, ate: filtros.ate } : { aba: a })
    const abas = [['clientes', 'Clientes'], ['avulsos', 'Avulsos'], ['notas', 'Notas']]

    return (
        <div className="pagina">
            <TituloPagina titulo="Notas fiscais" descricao="NFS-e pelo Sistema Nacional, a partir dos serviços sem nota (pagos ou não)." />
            {faltando.length > 0 && <div className="alert alert-warning">Para emitir, falta: {faltando.join(', ')}. <Link href="/configuracoes/nfse">Configurar</Link></div>}
            {!faltando.length && ambiente !== 'producao' && <div className="alert alert-info py-2">Em <strong>testes</strong>: as notas saem sem valor fiscal. Passe para produção em <Link href="/configuracoes/nfse">Configurações › Nota fiscal</Link>.</div>}

            <Aliquotas aliquotas={aliquotas} competenciaAtual={competenciaAtual} faltam={aliquotaAtual ? [] : [competenciaAtual]} />

            <ul className="nav nav-tabs mb-3">
                {abas.map(([k, r]) => (
                    <li className="nav-item" key={k}>
                        <button type="button" className={`nav-link${aba === k ? ' active' : ''}`} onClick={() => irPara(k)}>
                            {r}{k === 'clientes' && aba === 'clientes' && <span className="badge text-bg-secondary ms-1">{clientes.length}</span>}
                        </button>
                    </li>
                ))}
            </ul>

            {aba === 'clientes' && <Clientes clientes={clientes} filtros={filtros} />}
            {aba === 'avulsos' && <Avulsos avulsos={avulsos} filtros={filtros} faltando={faltando} retFederal={retFederal} aliquota={aliquotaAtual} />}

            {aba === 'notas' && notas && (
                <>
                    <div className="card">
                        <div className="table-responsive">
                            <table className="table align-middle mb-0">
                                <thead><tr><th>Nota</th><th>Tomador</th><th className="text-end">Valor</th><th>Situação</th><th /></tr></thead>
                                <tbody>
                                    {notas.data.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma nota ainda.</td></tr>}
                                    {notas.data.map((n) => (
                                        <tr key={n.id} className={n.situacao === 'cancelada' ? 'text-muted' : undefined}>
                                            <td className="text-nowrap">
                                                <strong>{n.numero ? `Nº ${n.numero}` : `DPS ${n.dps}`}</strong>{n.ambiente !== 'producao' && <span className="selo ms-1">teste</span>}
                                                <div className="small text-muted">{n.emitida_em}{n.servicos > 0 && ` · ${n.servicos} serviço${n.servicos > 1 ? 's' : ''}`}</div>
                                            </td>
                                            <td>{n.tomador || <span className="text-muted">{n.cliente ? `${n.cliente} (fora da nota)` : 'Sem tomador'}</span>}<div className="small text-muted">{n.documento}</div></td>
                                            <td className="text-end text-nowrap">{moeda(n.valor)}{n.iss_retido && <div className="small text-muted">ISS retido {moeda(n.valor_iss)}</div>}{n.valor_ret_federal > 0 && <div className="small text-muted">Federais retidos {moeda(n.valor_ret_federal)}</div>}</td>
                                            <td><span className={`selo ${SELO[n.situacao]}`}>{NOME[n.situacao]}</span>{n.mensagem && n.situacao !== 'emitida' && <div className="small text-muted mt-1" style={{ maxWidth: 360 }}>{n.mensagem}</div>}</td>
                                            <td className="text-end text-nowrap">
                                                {n.chave && <a className="btn btn-sm btn-outline-secondary me-1" href={`/notas-fiscais/${n.id}/pdf`} target="_blank" rel="noreferrer">PDF</a>}
                                                <a className="btn btn-sm btn-outline-secondary me-1" href={`/notas-fiscais/${n.id}/xml`}>XML</a>
                                                {n.situacao === 'enviando' && <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => router.post(`/notas-fiscais/${n.id}/conferir`, {}, { preserveScroll: true })}>Conferir</button>}
                                                {n.situacao === 'emitida' && <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => setCancelar(n)}>Cancelar</button>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <Paginacao pagina={notas} />
                </>
            )}
            {cancelar && <Cancelar nota={cancelar} onFechar={() => setCancelar(null)} />}
        </div>
    )
}
