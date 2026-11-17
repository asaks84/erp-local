import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import ContaDe from '../../Components/ContaDe'
import { dataHora, moeda } from '../../lib/formato'
import { placa as mPlaca, telefone } from '../../lib/mascaras'

const agoraTexto = () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
const hora = (v) => (v ? v.slice(11, 16) : '')

/** campo de data e hora "dd/mm/aaaa hh:mm" guardado como "aaaa-mm-dd hh:mm" */
function CampoDataHora({ rotulo, value, onChange, erro }) {
    const tela = (v) => (v ? `${v.slice(8, 10)}/${v.slice(5, 7)}/${v.slice(0, 4)} ${v.slice(11, 16)}` : '')
    const [texto, setTexto] = useState(tela(value))
    useEffect(() => setTexto(tela(value)), [value])
    function mudar(e) {
        const d = e.target.value.replace(/\D/g, '').slice(0, 12)
        const t = d.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2})\/(\d{2})(\d)/, '$1/$2/$3').replace(/^(\d{2}\/\d{2}\/\d{4})(\d)/, '$1 $2').replace(/(\d{2}\/\d{2}\/\d{4} \d{2})(\d)/, '$1:$2')
        setTexto(t)
        if (d.length === 12) onChange(`${d.slice(4, 8)}-${d.slice(2, 4)}-${d.slice(0, 2)} ${d.slice(8, 10)}:${d.slice(10, 12)}`)
    }
    return <Campo rotulo={`${rotulo} (dd/mm/aaaa hh:mm)`} value={texto} onChange={mudar} erro={erro} inputMode="numeric" />
}

/** dinheiro: quanto o cliente deu e o troco */
function Troco({ total, value, onChange, erro }) {
    const recebido = Number(value) || 0
    return (
        <div className="row g-2 align-items-end">
            <div className="col-6"><CampoValor opcional rotulo="Valor recebido" value={value} onChange={onChange} erro={erro} /></div>
            <div className="col-6 pb-3">{recebido > 0 && (recebido >= total ? <span>Troco: <strong>{moeda(recebido - total)}</strong></span> : <span className="text-danger small">Menor que {moeda(total)}</span>)}</div>
        </div>
    )
}

const numero = (v) => (v === '' || v === null || v === undefined ? null : Number(String(v).replace(',', '.')))

function Entrada({ tipos, convenios, formas }) {
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const { data, setData, post, processing, errors, reset, transform } = useForm({ placa: '', modelo: '', tipo_veiculo_id: '', convenio_id: '', celular: '', entrada: '', identificacao: '',
        pacote_id: '', pagar_agora: false, forma_pagamento_id: pix?.id ?? '', valor_recebido: '' })
    const [info, setInfo] = useState(null)
    const [semPlaca, setSemPlaca] = useState(false)
    const [outraHora, setOutraHora] = useState(false)
    const campo = useRef(null)

    useEffect(() => {
        const p = data.placa.replace(/[^A-Za-z0-9]/g, '')
        if (p.length < 7) { setInfo(null); return }
        const t = setTimeout(() => {
            fetch(`/estadias/placa?placa=${encodeURIComponent(p)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => r.json()).then((j) => {
                    setInfo(j.veiculo || false)
                    // cadastro traz o tipo; o operador ainda pode trocar
                    if (j.veiculo?.tipo_id) setData((d) => ({ ...d, tipo_veiculo_id: String(j.veiculo.tipo_id), modelo: d.modelo || (j.veiculo.modelo ?? '') }))
                }).catch(() => {})
        }, 250)
        return () => clearTimeout(t)
    }, [data.placa])

    function enviar(e) {
        e.preventDefault()
        transform((d) => ({ ...d, pacote_id: d.pacote_id || null, pagar_agora: !!d.pacote_id && d.pagar_agora, valor_recebido: numero(d.valor_recebido) }))
        post('/estadias', {
            preserveScroll: true,
            onSuccess: () => { reset(); setInfo(null); setOutraHora(false); setSemPlaca(false); campo.current?.focus() },
        })
    }

    const precisaTipo = semPlaca || info === false
    const tipoEscolhido = tipos.find((t) => String(t.id) === String(data.tipo_veiculo_id))
    const trocouTipo = info && info.tipo_id && data.tipo_veiculo_id && String(info.tipo_id) !== String(data.tipo_veiculo_id)
    const diarias = tipoEscolhido?.diarias || []
    const diaria = diarias.find((d) => String(d.id) === String(data.pacote_id))
    const formaEntrada = formas.find((f) => f.id === Number(data.forma_pagamento_id))
    return (
        <form className="card mb-4" onSubmit={enviar} noValidate>
            <div className="card-header d-flex align-items-center gap-2">
                <span className="flex-grow-1">Entrada</span>
                <button type="button" className="btn btn-sm btn-link" onClick={() => setSemPlaca(!semPlaca)}>{semPlaca ? 'Tem placa' : 'Sem placa'}</button>
            </div>
            <div className="card-body pb-1">
                <div className="row g-2">
                    <div className="col-md-3">
                        {semPlaca
                            ? <Campo rotulo="Identificação (ex.: bicicleta azul)" value={data.identificacao} onChange={(e) => setData('identificacao', e.target.value)} erro={errors.placa} maxLength={40} autoFocus />
                            : <Campo rotulo="Placa" value={mPlaca(data.placa)} onChange={(e) => setData('placa', e.target.value)} erro={errors.placa} autoFocus ref={campo} autoComplete="off" style={{ textTransform: 'uppercase' }} />}
                    </div>
                    {!semPlaca && (
                        <div className="col-md-3">
                            <Campo rotulo="Modelo (opcional)" value={data.modelo} onChange={(e) => setData('modelo', e.target.value)} erro={errors.modelo} maxLength={40} placeholder="Ex.: Gol branco" />
                        </div>
                    )}
                    <div className="col-md-3">
                        <Campo as="select" rotulo="Tipo do veículo" value={data.tipo_veiculo_id} onChange={(e) => { const v = e.target.value; setData((d) => ({ ...d, tipo_veiculo_id: v, pacote_id: '' })) }} erro={errors.tipo_veiculo_id}>
                            <option value="">Escolha</option>
                            {tipos.map((t) => <option key={t.id} value={t.id} disabled={!t.tabela}>{t.nome}{t.tabela ? '' : ' (sem tabela de estadia)'}</option>)}
                        </Campo>
                    </div>
                    {convenios.length > 0 && (
                        <div className="col-md-3">
                            <Campo as="select" rotulo="Convênio" value={data.convenio_id} onChange={(e) => setData('convenio_id', e.target.value)} erro={errors.convenio_id}>
                                <option value="">Sem convênio</option>
                                {convenios.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                            </Campo>
                        </div>
                    )}
                    {precisaTipo && (
                        <div className="col-md-3">
                            <Campo rotulo="Celular (opcional)" value={telefone(data.celular)} onChange={(e) => setData('celular', e.target.value)} inputMode="tel" />
                        </div>
                    )}
                    {outraHora && <div className="col-md-3"><CampoDataHora rotulo="Entrada" value={data.entrada} onChange={(v) => setData('entrada', v)} erro={errors.entrada} /></div>}
                </div>
                {info && (
                    <p className="small mb-2">
                        <strong>{info.rotulo}</strong> · {[info.descricao, info.tipo].filter(Boolean).join(' · ')} {info.cliente && <>· {info.cliente}</>} {info.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}
                    </p>
                )}
                {info === false && !semPlaca && <p className="small text-muted mb-2">Placa sem cadastro: entra como avulso.</p>}
                {tipoEscolhido?.tabela && <p className="small text-muted mb-2">Tabela: {tipoEscolhido.tabela}</p>}
                {trocouTipo && <p className="small text-warning-emphasis mb-2">Tipo diferente do cadastro ({info.tipo}): a estadia é cobrada como {tipoEscolhido?.nome}.</p>}
                {diarias.length > 0 && (
                    <div className="border-top pt-2 mb-2">
                        <div className="row g-2 align-items-center">
                            <div className="col-md-3">
                                <div className="form-check mb-0">
                                    <input className="form-check-input" type="checkbox" id="marcar-diaria" checked={!!data.pacote_id} onChange={(e) => setData('pacote_id', e.target.checked ? String(diarias[0].id) : '')} />
                                    <label className="form-check-label" htmlFor="marcar-diaria">Marcar diária</label>
                                </div>
                            </div>
                            {data.pacote_id && diarias.length > 1 && (
                                <div className="col-md-3">
                                    <select className="form-select form-select-sm" aria-label="Diária" value={data.pacote_id} onChange={(e) => setData('pacote_id', e.target.value)}>
                                        {diarias.map((d) => <option key={d.id} value={d.id}>{d.nome} · {moeda(d.valor)}</option>)}
                                    </select>
                                </div>
                            )}
                            {data.pacote_id && (
                                <div className="col-md-3">
                                    <div className="form-check mb-0">
                                        <input className="form-check-input" type="checkbox" id="pagar-agora" checked={data.pagar_agora} onChange={(e) => setData('pagar_agora', e.target.checked)} />
                                        <label className="form-check-label" htmlFor="pagar-agora">Pagar agora ({moeda(diaria?.valor ?? 0)})</label>
                                    </div>
                                </div>
                            )}
                        </div>
                        {data.pacote_id && <div className="form-text mt-1">Preço fechado da diária, mesmo saindo antes. Se passar do tempo dela, vale a tabela e paga a diferença na saída.</div>}
                        {errors.pacote_id && <div className="text-danger small">{errors.pacote_id}</div>}
                        {data.pacote_id && data.pagar_agora && (
                            <div className="row g-2 mt-1">
                                <div className="col-md-3">
                                    <Campo as="select" rotulo="Forma de pagamento" value={data.forma_pagamento_id} onChange={(e) => setData('forma_pagamento_id', e.target.value)} erro={errors.forma_pagamento_id}>
                                        {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                    </Campo>
                                </div>
                                {formaEntrada?.tipo === 'dinheiro' && <div className="col-md-5"><Troco total={diaria?.valor ?? 0} value={data.valor_recebido} onChange={(v) => setData('valor_recebido', v)} erro={errors.valor_recebido} /></div>}
                            </div>
                        )}
                    </div>
                )}
            </div>
            <div className="card-footer d-flex align-items-center gap-2">
                {!outraHora && <button type="button" className="btn btn-sm btn-link px-0" onClick={() => { setOutraHora(true); setData('entrada', agoraTexto()) }}>Entrou em outro horário?</button>}
                <span className="flex-grow-1" />
                <button type="submit" className="btn btn-primary" disabled={processing}>Registrar entrada</button>
            </div>
        </form>
    )
}

function Saida({ estadia, formas, convenios, onFechar }) {
    const [saida, setSaida] = useState(agoraTexto())
    const [calc, setCalc] = useState(null)
    const [erroCalc, setErroCalc] = useState(null)
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const form = useForm({ saida: '', destino: estadia.mensalista ? 'mensalista' : 'pago', forma_pagamento_id: pix?.id ?? '', parcelas: 1, conta_cliente_id: estadia.cliente?.id ?? '',
        convenio_id: estadia.convenio?.id ?? '', valor_recebido: '', perdido: false, retirada_nome: '', retirada_documento: '' })
    const [contaDe, setContaDe] = useState(estadia.cliente || null)

    useEffect(() => {
        setErroCalc(null)
        fetch(`/estadias/${estadia.id}/calcular?saida=${encodeURIComponent(saida)}&convenio_id=${form.data.convenio_id}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(async (r) => { const j = await r.json(); if (!r.ok) throw new Error(j.erro); return j })
            .then(setCalc).catch((e) => { setCalc(null); setErroCalc(e.message) })
    }, [saida, estadia.id, form.data.convenio_id])

    const forma = formas.find((f) => f.id === Number(form.data.forma_pagamento_id))
    const paga = calc && form.data.destino !== 'mensalista' ? calc.a_pagar : 0
    function enviar() {
        form.transform((d) => ({ ...d, saida, convenio_id: d.convenio_id || null, valor_recebido: numero(d.valor_recebido) }))
        form.post(`/estadias/${estadia.id}/saida`, { preserveScroll: true, onSuccess: onFechar })
    }

    return (
        <Janela aberta titulo={`Saída · ${estadia.rotulo}`} rotuloOk="Confirmar saída" onFechar={onFechar} onEnviar={enviar} ocupado={form.processing || !calc}>
            <p className="small text-muted mb-2">Ticket {estadia.numero} · entrou {dataHora(estadia.entrada)}{estadia.tipo ? ` · ${estadia.tipo}` : ''}</p>
            {estadia.modelo && <p className="small mb-2">{estadia.modelo}</p>}
            <CampoDataHora rotulo="Saída" value={saida} onChange={setSaida} erro={form.errors.saida} />
            {convenios.length > 0 && !(estadia.pago_entrada > 0) && (
                <Campo as="select" rotulo="Convênio" value={form.data.convenio_id} onChange={(e) => form.setData('convenio_id', e.target.value)} erro={form.errors.convenio_id}>
                    <option value="">Sem convênio</option>
                    {convenios.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </Campo>
            )}
            {erroCalc && <div className="alert alert-danger py-2">{erroCalc}</div>}
            {calc && (
                <div className="border rounded p-3 mb-3">
                    <div className="d-flex justify-content-between"><span>{calc.tempo} · {calc.tarifa}</span><strong>{moeda(calc.valor)}</strong></div>
                    <ul className="small text-muted mb-1 ps-3">{calc.detalhes.map((d, i) => <li key={i}>{d}</li>)}</ul>
                    {calc.regra && <div className="small">{calc.regra}</div>}
                    {calc.convenio > 0 && <div className="d-flex justify-content-between small"><span>Convênio paga</span><span>{moeda(calc.convenio)}</span></div>}
                    {calc.desconto > 0 && <div className="d-flex justify-content-between small"><span>Desconto do convênio</span><span>-{moeda(calc.desconto)}</span></div>}
                    {calc.pago_entrada > 0 && <div className="d-flex justify-content-between small"><span>Diária paga na entrada</span><span>-{moeda(calc.pago_entrada)}</span></div>}
                    <div className="d-flex justify-content-between fs-5 fw-bold mt-1"><span>A pagar</span><span>{moeda(paga)}</span></div>
                </div>
            )}
            {calc && calc.a_pagar > 0 && (
                <>
                    <div className="btn-group w-100 mb-3 flex-wrap" role="group" aria-label="Como fica">
                        {[['pago', 'Paga agora'], ['conta', 'Na conta de um cliente'], ...(estadia.mensalista ? [['mensalista', 'Mensalista']] : [])].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${form.data.destino === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => form.setData('destino', v)}>{r}</button>
                        ))}
                    </div>
                    {form.data.destino === 'conta' && (
                        <ContaDe cliente={contaDe} erro={form.errors.conta_cliente_id} onEscolher={(c) => { setContaDe(c); form.setData('conta_cliente_id', c.id) }} />
                    )}
                    {form.data.destino === 'pago' && (
                        <div className="row g-2">
                            <div className="col-8">
                                <Campo as="select" rotulo="Forma de pagamento" value={form.data.forma_pagamento_id} onChange={(e) => form.setData('forma_pagamento_id', e.target.value)} erro={form.errors.forma_pagamento_id}>
                                    {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                </Campo>
                            </div>
                            {forma?.tipo === 'dinheiro' && <div className="col-12"><Troco total={paga} value={form.data.valor_recebido} onChange={(v) => form.setData('valor_recebido', v)} erro={form.errors.valor_recebido} /></div>}
                            {forma?.parcelas_max > 1 && (
                                <div className="col-4">
                                    <Campo as="select" rotulo="Parcelas" value={form.data.parcelas} onChange={(e) => form.setData('parcelas', e.target.value)}>
                                        {Array.from({ length: forma.parcelas_max }, (_, i) => <option key={i} value={i + 1}>{i + 1}x</option>)}
                                    </Campo>
                                </div>
                            )}
                        </div>
                    )}
                </>
            )}
            {form.errors.destino && <div className="text-danger small">{form.errors.destino}</div>}
            <div className="form-check mt-2">
                <input className="form-check-input" type="checkbox" id="perdido" checked={form.data.perdido} onChange={(e) => form.setData('perdido', e.target.checked)} />
                <label className="form-check-label" htmlFor="perdido">Ticket perdido</label>
            </div>
            {form.data.perdido && (
                <div className="row g-2 mt-1">
                    <div className="col-7"><Campo rotulo="Nome de quem retira" value={form.data.retirada_nome} onChange={(e) => form.setData('retirada_nome', e.target.value)} erro={form.errors.retirada_nome} maxLength={80} /></div>
                    <div className="col-5"><Campo rotulo="Documento" value={form.data.retirada_documento} onChange={(e) => form.setData('retirada_documento', e.target.value)} maxLength={30} /></div>
                </div>
            )}
        </Janela>
    )
}

export default function EstadiasIndex({ patio, hoje, resumo_hoje, tipos, convenios, formas }) {
    const [saindo, setSaindo] = useState(null)
    const [cancelando, setCancelando] = useState(null)
    const cancelar = useForm({ motivo: '' })

    // atualiza o tempo no pátio a cada minuto
    useEffect(() => {
        const t = setInterval(() => router.reload({ only: ['patio'] }), 60000)
        return () => clearInterval(t)
    }, [])

    return (
        <div className="pagina">
            <TituloPagina titulo="Estadias">
                <Link href="/estadias/historico" className="btn btn-outline-secondary">Histórico</Link>
            </TituloPagina>
            <Entrada tipos={tipos} convenios={convenios} formas={formas} />

            <div className="d-flex align-items-baseline gap-2 mb-2">
                <h2 className="fs-5 fw-semibold mb-0">No pátio</h2>
                <span className="text-muted">{patio.length} veículo(s)</span>
            </div>
            <div className="card mb-4">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Veículo</th><th>Entrada</th><th>Tempo</th><th className="d-none d-md-table-cell">Quem</th><th /></tr></thead>
                        <tbody>
                            {patio.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum veículo no pátio.</td></tr>}
                            {patio.map((e) => (
                                <tr key={e.id}>
                                    <td>
                                        <strong>{e.rotulo}</strong>{e.modelo && <span className="small"> · {e.modelo}</span>}
                                        <div className="small text-muted">Ticket {e.numero}{e.tipo ? ` · ${e.tipo}` : ''}{e.pdv ? ' · PDV' : ''}</div>
                                        {e.diaria && <span className={`selo ${e.pago_entrada > 0 ? 'selo-ok' : ''}`}>{e.diaria.nome}{e.pago_entrada > 0 ? ' paga' : ''}</span>}
                                    </td>
                                    <td className="text-nowrap">{hora(e.entrada)}<div className="small text-muted">{e.entrada.slice(8, 10)}/{e.entrada.slice(5, 7)}</div></td>
                                    <td className="text-nowrap">{e.tempo}</td>
                                    <td className="d-none d-md-table-cell small">
                                        {e.cliente ? e.cliente.nome : 'Avulso'}
                                        {e.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}
                                        {e.convenio && <div className="text-muted">Convênio: {e.convenio.nome}</div>}
                                    </td>
                                    <td className="text-end text-nowrap">
                                        <button type="button" className="btn btn-sm btn-primary" onClick={() => setSaindo(e)}>Saída</button>
                                        <button type="button" className="btn btn-sm btn-outline-secondary ms-1" aria-label={`Cancelar ${e.rotulo}`} onClick={() => { cancelar.reset(); setCancelando(e) }}><Icone nome="lixeira" tamanho={16} /></button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="d-flex flex-wrap align-items-baseline gap-3 mb-2">
                <h2 className="fs-5 fw-semibold mb-0">Saídas de hoje</h2>
                <span className="text-muted small">{resumo_hoje.saidas} saída(s) · recebido {moeda(resumo_hoje.recebido)}{resumo_hoje.convenio > 0 ? ` · convênios ${moeda(resumo_hoje.convenio)}` : ''}</span>
            </div>
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Veículo</th><th>Horário</th><th className="text-end">Valor</th><th>Como ficou</th></tr></thead>
                        <tbody>
                            {hoje.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhuma saída hoje.</td></tr>}
                            {hoje.map((e) => (
                                <tr key={e.id} className={e.situacao === 'cancelada' ? 'text-muted' : undefined}>
                                    <td><strong>{e.rotulo}</strong><div className="small text-muted">Ticket {e.numero}{e.modelo ? ` · ${e.modelo}` : ''}{e.cliente ? ` · ${e.cliente.nome}` : ''}{e.pdv ? ' · PDV' : ''}</div>{e.retirada && <div className="small text-muted">Ticket perdido · retirou {e.retirada}</div>}</td>
                                    <td className="text-nowrap">{hora(e.entrada)} – {hora(e.saida)}<div className="small text-muted">{e.situacao === 'cancelada' ? '' : e.tempo}</div></td>
                                    <td className="text-end text-nowrap">{e.valor_calculado !== null ? moeda(e.valor_calculado) : '—'}{e.valor_convenio > 0 && <div className="small text-muted">convênio {moeda(e.valor_convenio)}</div>}</td>
                                    <td>{e.situacao === 'cancelada' ? <span className="selo" title={e.motivo_cancelamento || undefined}>Cancelada</span> : <span className={`selo ${e.destino === 'pago' ? 'selo-ok' : ''}`}>{e.destino_rotulo}</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {saindo && <Saida estadia={saindo} formas={formas} convenios={convenios} onFechar={() => setSaindo(null)} />}
            <Janela aberta={!!cancelando} titulo={`Cancelar entrada · ${cancelando?.rotulo ?? ''}`} perigo rotuloOk="Cancelar entrada" onFechar={() => setCancelando(null)}
                onEnviar={() => cancelar.post(`/estadias/${cancelando.id}/cancelar`, { preserveScroll: true, onSuccess: () => setCancelando(null) })} ocupado={cancelar.processing}>
                <p>Use só para entrada lançada por engano. Fica registrado, com o motivo.</p>
                <Campo rotulo="Motivo" value={cancelar.data.motivo} onChange={(e) => cancelar.setData('motivo', e.target.value)} erro={cancelar.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
