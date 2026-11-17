import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { CampoDinheiro, Folha, Formas, PerguntaImprimir, Previa, linhasRecibo, moeda } from '../comum'
import { montarRecibo } from '../impressao/ticket'
import { preferencia } from '../impressao/preferencia'

/**
 * Saída em etapas, uma decisão por tela, com o resumo e o "A pagar" fixos no alto:
 * 1. como fica (convênio; paga agora, na conta de ou mensalista; ticket perdido)
 * 2. forma de pagamento (ou o cliente da conta)
 * 3. dinheiro: valor recebido; confirmado, vai o PIN e aparece o troco com o recibo.
 */
export default function EstadiaSaida({ eu, opcoes, id, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [dados, setDados] = useState(null) // { estadia, calculo }
    const [etapa, setEtapa] = useState('como') // como | forma | dinheiro | conta
    const [convenio, setConvenio] = useState(undefined)
    const [destino, setDestino] = useState(null)
    const [forma, setForma] = useState(null)
    const [recebido, setRecebido] = useState(0)
    const [contaDe, setContaDe] = useState(null)
    const [busca, setBusca] = useState('')
    const [achados, setAchados] = useState([])
    const [perdido, setPerdido] = useState(false)
    const [retirada, setRetirada] = useState({ nome: '', documento: '' })
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [cancelando, setCancelando] = useState(false)
    const [motivo, setMotivo] = useState('')
    const [feito, setFeito] = useState(null)

    const calcular = useCallback(() => {
        const q = convenio === undefined ? '' : `?convenio_id=${convenio || ''}`
        api('GET', `/estadias/${id}/calculo${q}`).then((j) => {
            setDados(j)
            setErro(null)
            if (convenio === undefined) setConvenio(j.estadia.convenio?.id || '')
            setContaDe((c) => c || j.estadia.cliente || null)
        }).catch((e) => setErro(e.message))
    }, [id, convenio])
    useEffect(() => {
        calcular()
        const t = setInterval(calcular, 60000)
        return () => clearInterval(t)
    }, [calcular])

    // "na conta de": busca de cliente
    useEffect(() => {
        if (busca.trim().length < 2) { setAchados([]); return undefined }
        const t = setTimeout(() => api('GET', `/clientes?q=${encodeURIComponent(busca.trim())}`).then(setAchados).catch(() => {}), 250)
        return () => clearTimeout(t)
    }, [busca])

    if (feito) {
        const r = feito.recibo
        const troco = r.pago && feito.troco > 0 ? feito.troco : null
        return (
            <PerguntaImprimir titulo={troco ? 'Entregue o troco. Imprimir o recibo?' : `${feito.mensagem} Imprimir o recibo?`}
                destaque={troco && (
                    <div className="pdv-troco-final">
                        <div>Troco <strong>{moeda(troco)}</strong></div>
                    </div>
                )}
                montar={(op) => montarRecibo(r, eu, op)}
                previa={<Previa linhas={linhasRecibo(r, eu)} colunas={preferencia.get().colunas} />}
                onFim={() => onFeito(feito.mensagem)} />
        )
    }
    if (!dados) {
        return (
            <div>
                <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Estadias</button>
                {erro ? <div className="alert alert-danger">{erro}</div> : <div className="text-muted">Calculando…</div>}
            </div>
        )
    }

    const { estadia: e, calculo: c } = dados
    const aPagar = destino === 'mensalista' ? 0 : c.a_pagar
    const formaObj = opcoes.formas.find((f) => f.id === forma)
    const perdidoOk = !perdido || (retirada.nome.trim().length >= 3 && retirada.documento.trim().length >= 4)
    const valorRecebido = recebido || 0

    function finalizar(extra = {}) {
        const d = { destino: 'pago', ...extra }
        pedirPin('PIN para finalizar a saída').then(({ pin }) => {
            setEnviando(true)
            setErro(null)
            api('POST', `/estadias/${id}/saida`, {
                pin, destino: d.destino, convenio_id: convenio || null, conta_cliente_id: d.destino === 'conta' ? contaDe?.id : null,
                forma_pagamento_id: d.forma ?? null, valor_recebido: d.recebido ?? null,
                perdido, retirada_nome: perdido ? retirada.nome : null, retirada_documento: perdido ? retirada.documento : null,
            }).then((j) => setFeito({ ...j, recebido: d.recebido, troco: d.recebido ? Math.round((d.recebido - aPagar) * 100) / 100 : 0 }))
                .catch((ex) => setErro(ex.message)).finally(() => setEnviando(false))
        }).catch(() => {})
    }
    const escolherForma = (fid) => {
        setForma(fid)
        setErro(null)
        const f = opcoes.formas.find((x) => x.id === fid)
        if (f?.tipo === 'dinheiro') { setRecebido(0); setEtapa('dinheiro') }
    }
    const voltarEtapa = () => { setErro(null); setEtapa(etapa === 'dinheiro' ? 'forma' : 'como') }
    const cancelar = () => pedirPin('PIN de gerente para cancelar').then(({ pin }) => {
        setEnviando(true)
        api('POST', `/estadias/${id}/cancelar`, { pin, motivo }).then((j) => onFeito(j.mensagem)).catch((ex) => setErro(ex.message)).finally(() => { setEnviando(false); setCancelando(false) })
    }).catch(() => {})

    return (
        <div className="pb-5">
            {/* resumo fixo */}
            <div className="pdv-resumo-fixo">
                <button type="button" className="btn btn-link px-0 mb-1" onClick={etapa === 'como' ? onVoltar : voltarEtapa}>‹ {etapa === 'como' ? 'Estadias' : 'Voltar'}</button>
                <div className="d-flex justify-content-between align-items-baseline">
                    <span className="h4 mb-0">{e.rotulo}</span>
                    <span className="text-muted small">Nº {e.numero} · {c.tempo}</span>
                </div>
                <div className="small text-muted">{[e.modelo, e.tipo, e.cliente?.nome].filter(Boolean).join(' · ')}{e.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}</div>
                <div className="border rounded p-2 mt-2">
                    <div className="d-flex justify-content-between small"><span>{e.diaria ? e.diaria.nome : 'Estadia'} (entrou {e.entrada.slice(11, 16)})</span><span>{moeda(c.valor)}</span></div>
                    {c.convenio > 0 && <div className="d-flex justify-content-between small"><span>Convênio paga</span><span>-{moeda(c.convenio)}</span></div>}
                    {c.pago_entrada > 0 && <div className="d-flex justify-content-between small"><span>Pago na entrada</span><span>-{moeda(c.pago_entrada)}</span></div>}
                    <div className="d-flex justify-content-between fs-3 fw-bold"><span>A pagar</span><span>{moeda(aPagar)}</span></div>
                </div>
            </div>

            {etapa === 'como' && (
                <>
                    <ul className="small text-muted ps-3 mt-2 mb-3">{c.detalhes.map((d, i) => <li key={i}>{d}</li>)}</ul>

                    {opcoes.convenios.length > 0 && !(e.pago_entrada > 0) && (
                        <div className="mb-3">
                            <label className="form-label small mb-1" htmlFor="convenio">Convênio</label>
                            <select id="convenio" className="form-select form-select-lg" value={convenio ?? ''} onChange={(ev) => setConvenio(ev.target.value ? Number(ev.target.value) : '')}>
                                <option value="">Sem convênio</option>
                                {opcoes.convenios.map((v) => <option key={v.id} value={v.id}>{v.nome} · {v.resumo}</option>)}
                            </select>
                        </div>
                    )}

                    <div className="form-check form-switch mb-2">
                        <input className="form-check-input" type="checkbox" role="switch" id="perdido" checked={perdido} onChange={(ev) => setPerdido(ev.target.checked)} />
                        <label className="form-check-label" htmlFor="perdido">Ticket perdido</label>
                    </div>
                    {perdido && (
                        <div className="mb-3">
                            <input className="form-control mb-2" placeholder="Nome de quem está retirando" aria-label="Nome de quem está retirando" value={retirada.nome} maxLength={80} onChange={(ev) => setRetirada({ ...retirada, nome: ev.target.value })} />
                            <input className="form-control" placeholder="Documento (RG, CPF ou CNH)" aria-label="Documento" value={retirada.documento} maxLength={30} onChange={(ev) => setRetirada({ ...retirada, documento: ev.target.value })} />
                        </div>
                    )}

                    {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
                    {c.a_pagar > 0 ? (
                        <div className="d-grid gap-2 mt-3">
                            <button type="button" className="btn btn-primary btn-lg" disabled={!perdidoOk} onClick={() => { setDestino('pago'); setForma(null); setEtapa('forma') }}>Pagar agora</button>
                            <button type="button" className="btn btn-outline-primary btn-lg" disabled={!perdidoOk} onClick={() => { setDestino('conta'); setEtapa('conta') }}>Na conta de um cliente</button>
                            {e.mensalista && <button type="button" className="btn btn-outline-secondary btn-lg" disabled={!perdidoOk || enviando} onClick={() => { setDestino('mensalista'); finalizar({ destino: 'mensalista' }) }}>Mensalista (sem cobrar)</button>}
                        </div>
                    ) : (
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!perdidoOk || enviando} onClick={() => finalizar({ destino: e.mensalista ? 'mensalista' : 'pago' })}>
                            {enviando ? 'Liberando…' : 'Nada a pagar · liberar saída'}
                        </button>
                    )}
                    <div className="text-center mt-4">
                        <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => setCancelando(true)}>Cancelar esta entrada</button>
                    </div>
                </>
            )}

            {etapa === 'forma' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Forma de pagamento</div>
                    <Formas formas={opcoes.formas} value={forma} onChange={escolherForma} />
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    {formaObj && formaObj.tipo !== 'dinheiro' && (
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => finalizar({ forma })}>
                            {enviando ? 'Finalizando…' : `Recebi ${moeda(aPagar)} no ${formaObj.nome.replace(/^Cartão de /i, '').toLowerCase()}`}
                        </button>
                    )}
                </div>
            )}

            {etapa === 'dinheiro' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="recebido">Quanto o cliente deu?</label>
                    <CampoDinheiro id="recebido" autoFocus value={recebido} onChange={setRecebido} />
                    <div className="pdv-opcoes mt-2">
                        <button type="button" className="btn btn-outline-secondary" onClick={() => setRecebido(aPagar)}>Valor exato</button>
                        {[...new Set([Math.ceil(aPagar / 10) * 10, Math.ceil(aPagar / 50) * 50, 100, 200].filter((v) => v > aPagar))].slice(0, 3)
                            .map((v) => <button key={v} type="button" className="btn btn-outline-secondary" onClick={() => setRecebido(v)}>{moeda(v)}</button>)}
                    </div>
                    {valorRecebido > 0 && valorRecebido + 0.001 < aPagar && <div className="text-danger small mt-2">Menor que {moeda(aPagar)}.</div>}
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando || valorRecebido + 0.001 < aPagar} onClick={() => finalizar({ forma, recebido: valorRecebido })}>
                        {enviando ? 'Finalizando…' : valorRecebido >= aPagar ? `Confirmar ${moeda(valorRecebido)} recebidos` : 'Confirmar valor recebido'}
                    </button>
                </div>
            )}

            {etapa === 'conta' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Na conta de quem?</div>
                    {contaDe ? (
                        <div className="border rounded p-3 d-flex justify-content-between align-items-center">
                            <strong>{contaDe.nome}</strong>
                            <button type="button" className="btn btn-sm btn-link" onClick={() => setContaDe(null)}>trocar</button>
                        </div>
                    ) : (
                        <>
                            <input className="form-control form-control-lg" placeholder="Buscar cliente (nome ou código)" aria-label="Buscar cliente" value={busca} autoFocus onChange={(ev) => setBusca(ev.target.value)} autoComplete="off" />
                            <div className="list-group mt-1">{achados.map((a) => <button key={a.id} type="button" className="list-group-item list-group-item-action py-3" onClick={() => { setContaDe(a); setBusca('') }}>{a.nome}</button>)}</div>
                        </>
                    )}
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!contaDe || enviando} onClick={() => finalizar({ destino: 'conta' })}>
                        {enviando ? 'Finalizando…' : contaDe ? `Lançar ${moeda(aPagar)} na conta` : 'Escolha o cliente'}
                    </button>
                </div>
            )}

            {cancelando && (
                <Folha titulo={`Cancelar a entrada de ${e.rotulo}`} onFechar={() => setCancelando(false)}>
                    <p className="small text-muted">Só para entrada lançada por engano. Pede o PIN de um gerente; fica registrado com o motivo.</p>
                    <input className="form-control form-control-lg mb-2" placeholder="Motivo" aria-label="Motivo" value={motivo} maxLength={200} onChange={(ev) => setMotivo(ev.target.value)} />
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-danger btn-lg" disabled={!motivo.trim() || enviando} onClick={cancelar}>Cancelar entrada</button>
                        <button type="button" className="btn btn-outline-secondary btn-lg" onClick={() => setCancelando(false)}>Voltar</button>
                    </div>
                </Folha>
            )}
        </div>
    )
}
