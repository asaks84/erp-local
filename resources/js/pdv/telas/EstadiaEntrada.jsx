import { useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { Folha, Formas, PerguntaImprimir, Previa, Troco, linhasTicket, moeda } from '../comum'
import { montarTicket, ticketDaEstadia } from '../impressao/ticket'
import { preferencia } from '../impressao/preferencia'

const soPlaca = (v) => v.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 7)

/** entrada de estadia: placa, modelo, tamanho; diária marcada (paga agora ou na saída); PIN; ticket */
export default function EstadiaEntrada({ eu, opcoes, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [placa, setPlaca] = useState('')
    const [semPlaca, setSemPlaca] = useState(false)
    const [identificacao, setIdentificacao] = useState('')
    const [modelo, setModelo] = useState('')
    const [tipo, setTipo] = useState(opcoes.tipos.length === 1 ? opcoes.tipos[0].id : null)
    const [info, setInfo] = useState(null)
    const [pacote, setPacote] = useState(null)
    const [pagarAgora, setPagarAgora] = useState(false)
    const dinheiro = opcoes.formas.find((f) => f.tipo === 'dinheiro')
    const [forma, setForma] = useState(dinheiro?.id ?? opcoes.formas[0]?.id)
    const [recebido, setRecebido] = useState(0)
    const [erros, setErros] = useState({})
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [confirmarMensalista, setConfirmarMensalista] = useState(null) // { pin, texto }
    const [feito, setFeito] = useState(null)

    // placa completa: o que o sistema sabe dela (cadastro ou última visita)
    useEffect(() => {
        setInfo(null)
        if (semPlaca || placa.length < 7) return undefined
        let vivo = true
        api('GET', `/estadias/placa/${placa}`).then((j) => {
            if (!vivo) return
            setInfo(j)
            const s = j.veiculo || j.ultima
            if (s) {
                if (s.modelo) setModelo((m) => m || s.modelo)
                if (s.tipo_id && opcoes.tipos.some((t) => t.id === s.tipo_id)) setTipo(s.tipo_id)
            }
        }).catch(() => {})
        return () => { vivo = false }
    }, [placa, semPlaca, opcoes.tipos])

    const tipoObj = opcoes.tipos.find((t) => t.id === tipo)
    const diarias = tipoObj?.diarias || []
    const diaria = diarias.find((d) => d.id === pacote)
    const formaObj = opcoes.formas.find((f) => f.id === forma)
    useEffect(() => { if (pacote && !diarias.some((d) => d.id === pacote)) setPacote(null) }, [tipo]) // eslint-disable-line react-hooks/exhaustive-deps

    function enviar(pin, confirmar = false) {
        setEnviando(true)
        setErro(null)
        setErros({})
        api('POST', '/estadias', {
            pin, placa: semPlaca ? null : placa, identificacao: semPlaca ? identificacao : null, modelo, tipo_veiculo_id: tipo,
            pacote_id: pacote, pagar_agora: !!pacote && pagarAgora, forma_pagamento_id: pacote && pagarAgora ? forma : null,
            valor_recebido: pacote && pagarAgora && formaObj?.tipo === 'dinheiro' ? (recebido || null) : null, confirmar_mensalista: confirmar,
        }).then((j) => setFeito(j)).catch((e) => {
            if (e.campos?.mensalista) { setConfirmarMensalista({ pin, texto: e.campos.mensalista[0] }); return }
            setErros(e.campos || {})
            setErro(e.message)
        }).finally(() => setEnviando(false))
    }
    const registrar = () => {
        if (!tipo) { setErros({ tipo_veiculo_id: ['Escolha o tamanho do veículo.'] }); return }
        pedirPin('PIN para registrar a entrada').then(({ pin }) => enviar(pin)).catch(() => {})
    }

    if (feito) {
        const r = feito.recibo
        const tk = ticketDaEstadia(r, eu)
        const col = preferencia.get().colunas
        return (
            <PerguntaImprimir titulo={`Entrada de ${r.placa} registrada. Imprimir o ticket?`}
                montar={(op) => montarTicket(tk, op)}
                previa={<Previa linhas={linhasTicket(r, eu)} qr={tk.link} colunas={col} />}
                onFim={() => onFeito(`Entrada de ${r.placa} · Nº ${r.numero}${r.pago ? ` · ${r.servico} paga (${moeda(r.pago.valor)})` : ''}.`)} />
        )
    }

    const pronto = tipo && (semPlaca ? identificacao.trim() : placa.length >= 7) && !(pacote && pagarAgora && !forma)
    return (
        <div className="pb-5">
            <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Estadias</button>
            <h1 className="h5">Entrada</h1>

            {semPlaca ? (
                <div className="mb-3">
                    <label className="form-label small mb-1" htmlFor="ident">Identificação (sem placa)</label>
                    <input id="ident" className="form-control form-control-lg" value={identificacao} maxLength={40} onChange={(e) => setIdentificacao(e.target.value)} placeholder="Ex.: bicicleta azul" />
                </div>
            ) : (
                <div className="mb-2">
                    <label className="form-label small mb-1" htmlFor="placa">Placa</label>
                    <input id="placa" className={`form-control form-control-lg pdv-placa ${erros.placa ? 'is-invalid' : ''}`} value={placa} autoComplete="off" autoCapitalize="characters" autoFocus
                        onChange={(e) => { setPlaca(soPlaca(e.target.value)); setModelo('') }} />
                    {erros.placa && <div className="invalid-feedback">{erros.placa[0]}</div>}
                </div>
            )}
            <button type="button" className="btn btn-sm btn-link px-0 mb-2" onClick={() => setSemPlaca(!semPlaca)}>{semPlaca ? 'Tem placa' : 'Veículo sem placa'}</button>

            {info?.no_patio && <div className="alert alert-danger py-2 small">Já está no pátio: Nº {info.no_patio.numero}, entrou {info.no_patio.entrada}.</div>}
            {info?.veiculo && (
                <div className={`alert py-2 small ${info.veiculo.mensalista ? 'alert-warning' : 'aviso-neutro'}`}>
                    {info.veiculo.mensalista ? <><strong>Mensalista</strong> · {info.veiculo.cliente}. Mensalista não registra entrada.</> : <>Cadastrado: {info.veiculo.cliente}</>}
                </div>
            )}
            {info && !info.veiculo && info.ultima && <div className="small text-muted mb-2">Já esteve aqui em {info.ultima.quando}.</div>}

            <div className="mb-3">
                <label className="form-label small mb-1" htmlFor="modelo">Modelo</label>
                <input id="modelo" className="form-control form-control-lg" value={modelo} maxLength={40} onChange={(e) => setModelo(e.target.value)} placeholder="Ex.: Gol branco" />
            </div>

            <div className="mb-3">
                <div className="form-label small mb-1">Tamanho</div>
                <div className="pdv-opcoes">
                    {opcoes.tipos.map((t) => <button key={t.id} type="button" className={`btn btn-lg ${tipo === t.id ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setTipo(t.id)}>{t.nome}</button>)}
                </div>
                {erros.tipo_veiculo_id && <div className="text-danger small mt-1">{erros.tipo_veiculo_id[0]}</div>}
            </div>

            {diarias.length > 0 && (
                <div className="mb-3 border rounded p-2">
                    <div className="form-check form-switch mb-0">
                        <input className="form-check-input" type="checkbox" role="switch" id="diaria" checked={!!pacote} onChange={(e) => { setPacote(e.target.checked ? diarias[0].id : null); setPagarAgora(false) }} />
                        <label className="form-check-label" htmlFor="diaria">Marcar diária</label>
                    </div>
                    {pacote && (
                        <>
                            {diarias.length > 1 && (
                                <div className="pdv-opcoes mt-2">
                                    {diarias.map((d) => <button key={d.id} type="button" className={`btn ${pacote === d.id ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setPacote(d.id)}>{d.nome} · {moeda(d.valor)}</button>)}
                                </div>
                            )}
                            <div className="small text-muted mt-1">{diaria?.nome} {moeda(diaria?.valor)}: preço fechado, mesmo saindo antes. Passou do tempo, paga a diferença na saída.</div>
                            <div className="pdv-opcoes mt-2">
                                <button type="button" className={`btn ${!pagarAgora ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setPagarAgora(false)}>Paga na saída</button>
                                <button type="button" className={`btn ${pagarAgora ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setPagarAgora(true)}>Paga agora</button>
                            </div>
                            {pagarAgora && (
                                <div className="mt-2">
                                    <Formas formas={opcoes.formas} value={forma} onChange={setForma} />
                                    {formaObj?.tipo === 'dinheiro' && <div className="mt-2"><Troco total={diaria?.valor || 0} value={recebido} onChange={setRecebido} erro={erros.valor_recebido?.[0]} /></div>}
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}

            {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
            <div className="pdv-rodape-acao">
                <button type="button" className="btn btn-primary btn-lg w-100" disabled={!pronto || enviando} onClick={registrar}>
                    {enviando ? 'Registrando…' : pacote && pagarAgora ? `Registrar e receber ${moeda(diaria?.valor)}` : 'Registrar entrada'}
                </button>
            </div>

            {confirmarMensalista && (
                <Folha titulo="Placa de mensalista" onFechar={() => setConfirmarMensalista(null)}>
                    <p className="small">{confirmarMensalista.texto}</p>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-warning btn-lg" onClick={() => { const p = confirmarMensalista.pin; setConfirmarMensalista(null); enviar(p, true) }}>Registrar mesmo assim</button>
                        <button type="button" className="btn btn-outline-secondary btn-lg" onClick={() => setConfirmarMensalista(null)}>Não registrar</button>
                    </div>
                </Folha>
            )}
        </div>
    )
}
