import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Paginacao from '../../Components/Paginacao'
import CampoData from '../../Components/CampoData'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import { moeda } from '../../lib/formato'

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

const mesAno = (c) => (c ? `${c.slice(5, 7)}/${c.slice(0, 4)}` : '')
const pct = (v) => `${String(v).replace('.', ',')}%`

/** as duas alíquotas do e-mail mensal da contabilidade, por competência */
function Aliquotas({ aliquotas, competenciaAtual, faltam }) {
    const deMes = (c) => aliquotas.find((a) => a.competencia === c)
    const inicial = faltam[0] || competenciaAtual
    const form = useForm({ competencia: inicial, iss: deMes(inicial)?.iss || '', tributos: deMes(inicial)?.tributos || '' })
    const trocarMes = (c) => form.setData({ competencia: c, iss: deMes(c)?.iss || '', tributos: deMes(c)?.tributos || '' })
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
                    <div className="col-6 col-md-3"><Campo rotulo="Competência" type="month" value={form.data.competencia} erro={form.errors.competencia} onChange={(e) => trocarMes(e.target.value)} /></div>
                    <div className="col-6 col-md-3"><Campo rotulo="Alíquota de ISS (%)" inputMode="decimal" placeholder="2,01" value={form.data.iss} erro={form.errors.iss} onChange={(e) => form.setData('iss', e.target.value.replace(',', '.'))} ajuda="Só entra em nota com ISS retido." /></div>
                    <div className="col-6 col-md-3"><Campo rotulo="Tributos aproximados (%)" inputMode="decimal" placeholder="17,62" value={form.data.tributos} erro={form.errors.tributos} onChange={(e) => form.setData('tributos', e.target.value.replace(',', '.'))} ajuda="O “valor aproximado dos tributos” do e-mail." /></div>
                    <div className="col-6 col-md-3 d-flex flex-column">
                        <button type="submit" className="btn btn-outline-primary mt-md-4" disabled={form.processing}>{salvo ? 'Atualizar' : 'Salvar'}</button>
                        {salvo && <span className="small text-muted mt-1">{mesAno(salvo.competencia)}: ISS {pct(salvo.iss)} · tributos {pct(salvo.tributos)}</span>}
                    </div>
                </form>
            </div>
        </div>
    )
}

/** prévia da nota antes de emitir, como a "Prévia dos valores" do Emissor */
function Emitir({ pagamento: p, aliquota, federal, onFechar }) {
    const declara = p.pode_identificar && p.identificar_antes
    const form = useForm({
        identificar: declara, retido: p.pode_reter && declara && p.retido_antes,
        ret_federal: declara ? p.federal_antes.tipo : '0', ret_irrf: declara && p.federal_antes.irrf, ret_cp: declara && p.federal_antes.cp,
    })
    const tipo = federal.tipos[form.data.ret_federal] || {}
    const pctFed = (tipo.pis ? federal.pct.pis : 0) + (tipo.cofins ? federal.pct.cofins : 0) + (tipo.csll ? federal.pct.csll : 0)
        + (form.data.ret_irrf ? federal.pct.irrf : 0) + (form.data.ret_cp ? federal.pct.cp : 0)
    const iss = form.data.retido && aliquota ? Math.round(p.valor * parseFloat(aliquota.iss)) / 100 : 0
    const fed = Math.round(p.valor * pctFed) / 100
    const linha = (r, v, forte) => <div className={`d-flex justify-content-between border-bottom py-1${forte ? ' fw-semibold' : ''}`}><span>{r}</span><span>{v}</span></div>
    return (
        <Janela aberta titulo="Emitir NFS-e" rotuloOk="Emitir NFS-e" onFechar={onFechar} ocupado={form.processing}
            onEnviar={() => form.post(`/notas-fiscais/emitir/${p.id}`, { preserveScroll: true, onSuccess: onFechar })}>
            <div className="small mb-3">
                {linha('Cliente', p.cliente ? `${p.cliente}${p.documento ? ` · ${p.documento}` : ''}` : 'Consumidor (sem cadastro)')}
                {linha('Competência', mesAno(p.competencia))}
                {linha('Serviço prestado', moeda(p.valor))}
                {linha('Tributos aproximados (Simples)', aliquota ? pct(aliquota.tributos) : '—')}
            </div>
            <p className="small text-muted mb-2">Escolhas deste cliente (a próxima nota dele já vem igual):</p>
            <div className="form-check mb-2">
                <input className="form-check-input" type="checkbox" id="identificar" checked={form.data.identificar} disabled={!p.pode_identificar}
                    onChange={(e) => form.setData(e.target.checked ? { ...form.data, identificar: true } : { identificar: false, retido: false, ret_federal: '0', ret_irrf: false, ret_cp: false })} />
                <label className="form-check-label" htmlFor="identificar">Declarar os dados do cliente na nota</label>
                <div className="form-text">{p.pode_identificar ? 'Desmarcado, a nota sai sem tomador (“Tomador/Adquirente não informado”).' : 'Sem CPF/CNPJ válido no cadastro: a nota sai sem tomador.'}</div>
            </div>
            <div className="form-check mb-2">
                <input className="form-check-input" type="checkbox" id="retido" checked={form.data.retido} disabled={!p.pode_reter || !form.data.identificar} onChange={(e) => form.setData('retido', e.target.checked)} />
                <label className="form-check-label" htmlFor="retido">ISSQN retido pelo tomador</label>
                <div className="form-text">
                    {!p.pode_reter ? 'Para reter, o cliente precisa de CPF/CNPJ e endereço completo no município (cadastro do cliente).'
                        : 'Marque quando o cliente (normalmente empresa) recolhe o ISS no lugar do Local e desconta do pagamento.'}
                </div>
            </div>
            <div className="row g-2 mb-2">
                <div className="col-12">
                    <Campo as="select" rotulo="Retenção do PIS/COFINS/CSLL" value={form.data.ret_federal} disabled={!form.data.identificar} onChange={(e) => form.setData('ret_federal', e.target.value)}>
                        {Object.entries(federal.tipos).map(([v, t]) => <option key={v} value={v}>{t.nome}</option>)}
                    </Campo>
                </div>
                <div className="col-6"><div className="form-check"><input className="form-check-input" type="checkbox" id="irrf" checked={form.data.ret_irrf} disabled={!form.data.identificar} onChange={(e) => form.setData('ret_irrf', e.target.checked)} /><label className="form-check-label" htmlFor="irrf">IRRF retido</label></div></div>
                <div className="col-6"><div className="form-check"><input className="form-check-input" type="checkbox" id="cp" checked={form.data.ret_cp} disabled={!form.data.identificar} onChange={(e) => form.setData('ret_cp', e.target.checked)} /><label className="form-check-label" htmlFor="cp">Previdenciária retida</label></div></div>
            </div>
            {(form.data.retido || fed > 0) && (
                <div className="small">
                    {form.data.retido && linha(`ISSQN retido (${pct(aliquota.iss)})`, moeda(iss))}
                    {fed > 0 && linha(`Tributos federais retidos (${pct(pctFed.toFixed(2))})`, moeda(fed))}
                    {linha('Valor líquido da NFS-e', moeda(p.valor - iss - fed), true)}
                </div>
            )}
            {form.errors.nfse && <div className="alert alert-danger py-2 small mt-2 mb-0">{form.errors.nfse}</div>}
        </Janela>
    )
}

/** Gestão › Notas fiscais: recebimentos sem nota (emitir) e as notas emitidas pelo Sistema Nacional. */
export default function NotasFiscais({ aEmitir, notas, filtros, faltando, ambiente, aliquotas, competenciaAtual, federal }) {
    const [aba, setAba] = useState(aEmitir.length ? 'emitir' : 'notas')
    const [emitindo, setEmitindo] = useState(null)
    const [cancelar, setCancelar] = useState(null)
    const filtrar = (k, v) => router.get('/notas-fiscais', { ...filtros, [k]: v }, { preserveState: true, replace: true })
    const aliquotaDe = (c) => aliquotas.find((a) => a.competencia === c)
    // meses com recebimento a emitir e sem as alíquotas informadas (e o mês atual)
    const faltam = [...new Set([competenciaAtual, ...aEmitir.map((p) => p.competencia)])].filter((c) => !aliquotaDe(c)).sort().reverse()

    return (
        <div className="pagina">
            <TituloPagina titulo="Notas fiscais" descricao="NFS-e pelo Sistema Nacional, uma por recebimento." />
            {faltando.length > 0 && <div className="alert alert-warning">Para emitir, falta: {faltando.join(', ')}. <Link href="/configuracoes/nfse">Configurar</Link></div>}
            {!faltando.length && ambiente !== 'producao' && <div className="alert alert-info py-2">Em <strong>testes</strong>: as notas saem sem valor fiscal. Passe para produção em <Link href="/configuracoes/nfse">Configurações › Nota fiscal</Link>.</div>}

            <Aliquotas aliquotas={aliquotas} competenciaAtual={competenciaAtual} faltam={faltam} />

            <ul className="nav nav-tabs mb-3">
                <li className="nav-item"><button type="button" className={`nav-link${aba === 'emitir' ? ' active' : ''}`} onClick={() => setAba('emitir')}>A emitir <span className="badge text-bg-secondary ms-1">{aEmitir.length}</span></button></li>
                <li className="nav-item"><button type="button" className={`nav-link${aba === 'notas' ? ' active' : ''}`} onClick={() => setAba('notas')}>Notas</button></li>
            </ul>

            {aba === 'emitir' && (
                <>
                    <div className="row g-2 mb-1">
                        <div className="col-6 col-md-3"><CampoData rotulo="Recebidos de" value={filtros.de} onChange={(v) => filtrar('de', v)} /></div>
                        <div className="col-6 col-md-3"><CampoData rotulo="Até" value={filtros.ate} onChange={(v) => filtrar('ate', v)} /></div>
                    </div>
                    <div className="card">
                        <div className="table-responsive">
                            <table className="table align-middle mb-0">
                                <thead><tr><th>Recebido</th><th>Cliente</th><th className="d-none d-md-table-cell">Forma</th><th className="text-end">Valor</th><th /></tr></thead>
                                <tbody>
                                    {aEmitir.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum recebimento sem nota no período.</td></tr>}
                                    {aEmitir.map((p) => (
                                        <tr key={p.id}>
                                            <td className="text-nowrap">{p.recebido_em}{p.origem === 'pdv' && <span className="selo selo-info ms-1">PDV</span>}</td>
                                            <td>{p.cliente || <span className="text-muted">Consumidor</span>}<div className="small text-muted">{p.documento || 'sem CPF/CNPJ: nota sem tomador'}{p.documento && !p.identificar_antes && ' · sem dados na nota'}{p.retido_antes && p.identificar_antes && ' · ISS retido'}{p.identificar_antes && (p.federal_antes.tipo !== '0' || p.federal_antes.irrf || p.federal_antes.cp) && ' · retenção federal'}</div></td>
                                            <td className="d-none d-md-table-cell">{p.forma}</td>
                                            <td className="text-end text-nowrap">{moeda(p.valor)}</td>
                                            <td className="text-end">
                                                {aliquotaDe(p.competencia)
                                                    ? <button type="button" className="btn btn-sm btn-primary" disabled={faltando.length > 0} onClick={() => setEmitindo(p)}>Emitir</button>
                                                    : <span className="small text-muted text-nowrap">sem alíquota {mesAno(p.competencia)}</span>}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </>
            )}

            {aba === 'notas' && (
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
                                                <div className="small text-muted">{n.emitida_em}</div>
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
            {emitindo && <Emitir pagamento={emitindo} aliquota={aliquotaDe(emitindo.competencia)} federal={federal} onFechar={() => setEmitindo(null)} />}
            {cancelar && <Cancelar nota={cancelar} onFechar={() => setCancelar(null)} />}
        </div>
    )
}
