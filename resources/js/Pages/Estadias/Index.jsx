import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
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

function Entrada({ tipos, convenios }) {
    const { data, setData, post, processing, errors, reset } = useForm({ placa: '', tipo_veiculo_id: '', convenio_id: '', celular: '', entrada: '', identificacao: '' })
    const [info, setInfo] = useState(null)
    const [semPlaca, setSemPlaca] = useState(false)
    const [outraHora, setOutraHora] = useState(false)
    const campo = useRef(null)

    useEffect(() => {
        const p = data.placa.replace(/[^A-Za-z0-9]/g, '')
        if (p.length < 7) { setInfo(null); return }
        const t = setTimeout(() => {
            fetch(`/estadias/placa?placa=${encodeURIComponent(p)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => r.json()).then((j) => setInfo(j.veiculo || false)).catch(() => {})
        }, 250)
        return () => clearTimeout(t)
    }, [data.placa])

    function enviar(e) {
        e.preventDefault()
        post('/estadias', {
            preserveScroll: true,
            onSuccess: () => { reset(); setInfo(null); setOutraHora(false); setSemPlaca(false); campo.current?.focus() },
        })
    }

    const precisaTipo = semPlaca || info === false
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
                    {precisaTipo && (
                        <div className="col-md-3">
                            <Campo as="select" rotulo="Tipo do veículo" value={data.tipo_veiculo_id} onChange={(e) => setData('tipo_veiculo_id', e.target.value)} erro={errors.tipo_veiculo_id}>
                                <option value="">Escolha</option>
                                {tipos.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
                            </Campo>
                        </div>
                    )}
                    {convenios.length > 0 && (
                        <div className="col-md-3">
                            <Campo as="select" rotulo="Convênio" value={data.convenio_id} onChange={(e) => setData('convenio_id', e.target.value)} erro={errors.convenio_id}>
                                <option value="">Sem convênio</option>
                                {convenios.map((c) => <option key={c.id} value={c.id}>{c.nome} · {c.resumo}</option>)}
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
                        <strong>{info.rotulo}</strong> · {info.descricao || info.tipo} {info.cliente && <>· {info.cliente}</>} {info.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}
                    </p>
                )}
                {info === false && !semPlaca && <p className="small text-muted mb-2">Placa sem cadastro: entra como avulso.</p>}
            </div>
            <div className="card-footer d-flex align-items-center gap-2">
                {!outraHora && <button type="button" className="btn btn-sm btn-link px-0" onClick={() => { setOutraHora(true); setData('entrada', agoraTexto()) }}>Entrou em outro horário?</button>}
                <span className="flex-grow-1" />
                <button type="submit" className="btn btn-primary" disabled={processing}>Registrar entrada</button>
            </div>
        </form>
    )
}

function Saida({ estadia, formas, onFechar }) {
    const [saida, setSaida] = useState(agoraTexto())
    const [calc, setCalc] = useState(null)
    const [erroCalc, setErroCalc] = useState(null)
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const form = useForm({ saida: '', destino: estadia.mensalista ? 'mensalista' : 'pago', forma_pagamento_id: pix?.id ?? '', parcelas: 1 })

    useEffect(() => {
        setErroCalc(null)
        fetch(`/estadias/${estadia.id}/calcular?saida=${encodeURIComponent(saida)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(async (r) => { const j = await r.json(); if (!r.ok) throw new Error(j.erro); return j })
            .then(setCalc).catch((e) => { setCalc(null); setErroCalc(e.message) })
    }, [saida, estadia.id])

    const forma = formas.find((f) => f.id === Number(form.data.forma_pagamento_id))
    const paga = calc && form.data.destino !== 'mensalista' ? calc.cliente : 0
    function enviar() {
        form.transform((d) => ({ ...d, saida }))
        form.post(`/estadias/${estadia.id}/saida`, { preserveScroll: true, onSuccess: onFechar })
    }

    return (
        <Janela aberta titulo={`Saída · ${estadia.rotulo}`} rotuloOk="Confirmar saída" onFechar={onFechar} onEnviar={enviar} ocupado={form.processing || !calc}>
            <p className="small text-muted mb-2">Ticket {estadia.numero} · entrou {dataHora(estadia.entrada)}{estadia.tipo ? ` · ${estadia.tipo}` : ''}</p>
            <CampoDataHora rotulo="Saída" value={saida} onChange={setSaida} erro={form.errors.saida} />
            {erroCalc && <div className="alert alert-danger py-2">{erroCalc}</div>}
            {calc && (
                <div className="border rounded p-3 mb-3">
                    <div className="d-flex justify-content-between"><span>{calc.tempo} · {calc.tarifa}</span><strong>{moeda(calc.valor)}</strong></div>
                    <ul className="small text-muted mb-1 ps-3">{calc.detalhes.map((d, i) => <li key={i}>{d}</li>)}</ul>
                    {calc.regra && <div className="small">{calc.regra}</div>}
                    {calc.convenio > 0 && <div className="d-flex justify-content-between small"><span>Convênio paga</span><span>{moeda(calc.convenio)}</span></div>}
                    {calc.desconto > 0 && <div className="d-flex justify-content-between small"><span>Desconto do convênio</span><span>-{moeda(calc.desconto)}</span></div>}
                    <div className="d-flex justify-content-between fs-5 fw-bold mt-1"><span>A pagar</span><span>{moeda(paga)}</span></div>
                </div>
            )}
            {calc && calc.cliente > 0 && (
                <>
                    <div className="btn-group w-100 mb-3 flex-wrap" role="group" aria-label="Como fica">
                        {[['pago', 'Paga agora'], ...(estadia.cliente ? [['conta', 'Na conta do cliente']] : []), ...(estadia.mensalista ? [['mensalista', 'Mensalista']] : [])].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${form.data.destino === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => form.setData('destino', v)}>{r}</button>
                        ))}
                    </div>
                    {form.data.destino === 'pago' && (
                        <div className="row g-2">
                            <div className="col-8">
                                <Campo as="select" rotulo="Forma de pagamento" value={form.data.forma_pagamento_id} onChange={(e) => form.setData('forma_pagamento_id', e.target.value)} erro={form.errors.forma_pagamento_id}>
                                    {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                </Campo>
                            </div>
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
            <Entrada tipos={tipos} convenios={convenios} />

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
                                    <td><strong>{e.rotulo}</strong><div className="small text-muted">Ticket {e.numero}{e.tipo ? ` · ${e.tipo}` : ''}</div></td>
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
                                    <td><strong>{e.rotulo}</strong><div className="small text-muted">Ticket {e.numero}{e.cliente ? ` · ${e.cliente.nome}` : ''}</div></td>
                                    <td className="text-nowrap">{hora(e.entrada)} – {hora(e.saida)}<div className="small text-muted">{e.situacao === 'cancelada' ? '' : e.tempo}</div></td>
                                    <td className="text-end text-nowrap">{e.valor_calculado !== null ? moeda(e.valor_calculado) : '—'}{e.valor_convenio > 0 && <div className="small text-muted">convênio {moeda(e.valor_convenio)}</div>}</td>
                                    <td>{e.situacao === 'cancelada' ? <span className="selo" title={e.motivo_cancelamento || undefined}>Cancelada</span> : <span className={`selo ${e.destino === 'pago' ? 'selo-ok' : ''}`}>{e.destino_rotulo}</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {saindo && <Saida estadia={saindo} formas={formas} onFechar={() => setSaindo(null)} />}
            <Janela aberta={!!cancelando} titulo={`Cancelar entrada · ${cancelando?.rotulo ?? ''}`} perigo rotuloOk="Cancelar entrada" onFechar={() => setCancelando(null)}
                onEnviar={() => cancelar.post(`/estadias/${cancelando.id}/cancelar`, { preserveScroll: true, onSuccess: () => setCancelando(null) })} ocupado={cancelar.processing}>
                <p>Use só para entrada lançada por engano. Fica registrado, com o motivo.</p>
                <Campo rotulo="Motivo" value={cancelar.data.motivo} onChange={(e) => cancelar.setData('motivo', e.target.value)} erro={cancelar.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
