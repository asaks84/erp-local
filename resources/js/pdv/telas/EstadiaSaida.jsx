import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { Folha, Formas, PerguntaImprimir, Previa, Troco, linhasRecibo, moeda, numero } from '../comum'
import { montarRecibo } from '../impressao/ticket'
import { preferencia } from '../impressao/preferencia'

/**
 * Saída: o servidor calcula; convênio escolhido aqui (opcional); paga agora, na conta de um cliente ou
 * mensalista; ticket perdido pede nome e documento; PIN; recibo.
 */
export default function EstadiaSaida({ eu, opcoes, id, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [dados, setDados] = useState(null) // { estadia, calculo }
    const [convenio, setConvenio] = useState(undefined)
    const [destino, setDestino] = useState(null)
    const pix = opcoes.formas.find((f) => f.tipo === 'pix')
    const [forma, setForma] = useState(opcoes.formas.find((f) => f.tipo === 'dinheiro')?.id ?? pix?.id)
    const [recebido, setRecebido] = useState('')
    const [contaDe, setContaDe] = useState(null)
    const [busca, setBusca] = useState('')
    const [achados, setAchados] = useState([])
    const [perdido, setPerdido] = useState(false)
    const [retirada, setRetirada] = useState({ nome: '', documento: '' })
    const [erro, setErro] = useState(null)
    const [erros, setErros] = useState({})
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
            setDestino((d) => d || (j.estadia.mensalista ? 'mensalista' : 'pago'))
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
        return (
            <PerguntaImprimir titulo={`${feito.mensagem} Imprimir o recibo?`}
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
    const pagaAgora = aPagar > 0 && destino === 'pago'

    function finalizar(pin) {
        setEnviando(true)
        setErro(null)
        setErros({})
        api('POST', `/estadias/${id}/saida`, {
            pin, destino, convenio_id: convenio || null, conta_cliente_id: destino === 'conta' ? contaDe?.id : null,
            forma_pagamento_id: pagaAgora ? forma : null, valor_recebido: pagaAgora && formaObj?.tipo === 'dinheiro' ? numero(recebido) : null,
            perdido, retirada_nome: perdido ? retirada.nome : null, retirada_documento: perdido ? retirada.documento : null,
        }).then(setFeito).catch((ex) => { setErros(ex.campos || {}); setErro(ex.message) }).finally(() => setEnviando(false))
    }
    const cancelar = () => pedirPin('PIN de gerente para cancelar').then(({ pin }) => {
        setEnviando(true)
        api('POST', `/estadias/${id}/cancelar`, { pin, motivo }).then((j) => onFeito(j.mensagem)).catch((ex) => setErro(ex.message)).finally(() => { setEnviando(false); setCancelando(false) })
    }).catch(() => {})

    const pronto = destino && !(pagaAgora && !forma) && !(destino === 'conta' && !contaDe) && !(perdido && (retirada.nome.trim().length < 3 || retirada.documento.trim().length < 4))
    return (
        <div className="pb-5">
            <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Estadias</button>
            <div className="d-flex justify-content-between align-items-baseline">
                <h1 className="h4 mb-0">{e.rotulo}</h1>
                <span className="text-muted">Nº {e.numero}</span>
            </div>
            <div className="small text-muted mb-3">{[e.modelo, e.tipo, e.cliente?.nome].filter(Boolean).join(' · ')}{e.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}</div>

            <div className="border rounded p-3 mb-3">
                <div className="d-flex justify-content-between small"><span>Entrada</span><span>{e.entrada.slice(8, 10)}/{e.entrada.slice(5, 7)} {e.entrada.slice(11, 16)}</span></div>
                <div className="d-flex justify-content-between small"><span>Tempo</span><span>{c.tempo}</span></div>
                <div className="d-flex justify-content-between mt-1"><span>{e.diaria ? e.diaria.nome : 'Estadia'}</span><strong>{moeda(c.valor)}</strong></div>
                <ul className="small text-muted mb-1 ps-3">{c.detalhes.map((d, i) => <li key={i}>{d}</li>)}</ul>
                {c.convenio > 0 && <div className="d-flex justify-content-between small"><span>Convênio paga</span><span>-{moeda(c.convenio)}</span></div>}
                {c.pago_entrada > 0 && <div className="d-flex justify-content-between small"><span>Pago na entrada</span><span>-{moeda(c.pago_entrada)}</span></div>}
                <div className="d-flex justify-content-between fs-4 fw-bold mt-1"><span>A pagar</span><span>{moeda(aPagar)}</span></div>
            </div>

            {opcoes.convenios.length > 0 && !(e.pago_entrada > 0) && (
                <div className="mb-3">
                    <label className="form-label small mb-1" htmlFor="convenio">Convênio</label>
                    <select id="convenio" className="form-select form-select-lg" value={convenio ?? ''} onChange={(ev) => setConvenio(ev.target.value ? Number(ev.target.value) : '')}>
                        <option value="">Sem convênio</option>
                        {opcoes.convenios.map((v) => <option key={v.id} value={v.id}>{v.nome} · {v.resumo}</option>)}
                    </select>
                    {erros.convenio_id && <div className="text-danger small">{erros.convenio_id[0]}</div>}
                </div>
            )}

            {c.a_pagar > 0 && (
                <div className="mb-3">
                    <div className="pdv-opcoes mb-2">
                        {[['pago', 'Paga agora'], ['conta', 'Na conta de'], ...(e.mensalista ? [['mensalista', 'Mensalista']] : [])].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${destino === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setDestino(v)}>{r}</button>
                        ))}
                    </div>
                    {destino === 'pago' && (
                        <>
                            <Formas formas={opcoes.formas} value={forma} onChange={setForma} />
                            {formaObj?.tipo === 'dinheiro' && <div className="mt-2"><Troco total={aPagar} value={recebido} onChange={setRecebido} erro={erros.valor_recebido?.[0]} /></div>}
                        </>
                    )}
                    {destino === 'conta' && (
                        contaDe
                            ? <div className="d-flex align-items-center gap-2"><strong>{contaDe.nome}</strong><button type="button" className="btn btn-sm btn-link" onClick={() => setContaDe(null)}>trocar</button></div>
                            : (
                                <div>
                                    <input className="form-control form-control-lg" placeholder="Buscar cliente (nome ou código)" aria-label="Buscar cliente" value={busca} onChange={(ev) => setBusca(ev.target.value)} autoComplete="off" />
                                    <div className="list-group mt-1">{achados.map((a) => <button key={a.id} type="button" className="list-group-item list-group-item-action" onClick={() => { setContaDe(a); setBusca('') }}>{a.nome}</button>)}</div>
                                </div>
                            )
                    )}
                    {erros.conta_cliente_id && <div className="text-danger small">{erros.conta_cliente_id[0]}</div>}
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
                    {erros.retirada_nome && <div className="text-danger small">{erros.retirada_nome[0]}</div>}
                </div>
            )}

            {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
            <div className="pdv-rodape-acao">
                <button type="button" className="btn btn-primary btn-lg w-100" disabled={!pronto || enviando} onClick={() => pedirPin('PIN para finalizar a saída').then(({ pin }) => finalizar(pin)).catch(() => {})}>
                    {enviando ? 'Finalizando…' : pagaAgora ? `Receber ${moeda(aPagar)} e liberar` : 'Liberar saída'}
                </button>
            </div>
            <div className="text-center mt-3">
                <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => setCancelando(true)}>Cancelar esta entrada</button>
            </div>

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
