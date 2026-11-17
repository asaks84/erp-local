import { useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { CampoDinheiro, Folha, Formas, PerguntaImprimir, Previa, linhasTicket, moeda } from '../comum'
import { montarTicket, ticketDaEstadia } from '../impressao/ticket'
import { preferencia } from '../impressao/preferencia'

const soPlaca = (v) => v.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 7)

/**
 * Entrada em etapas, uma decisão por tela, com o que já foi escolhido fixo no alto:
 * 1. placa  2. modelo e tamanho  3. por hora ou diária  4. diária: paga na saída ou agora
 * 5. forma de pagamento  6. dinheiro: quanto o cliente deu. Depois, PIN e "Imprimir o ticket?".
 */
export default function EstadiaEntrada({ eu, opcoes, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [etapa, setEtapa] = useState('placa') // placa | veiculo | cobranca | diaria | forma | dinheiro
    const [placa, setPlaca] = useState('')
    const [semPlaca, setSemPlaca] = useState(false)
    const [identificacao, setIdentificacao] = useState('')
    const [modelo, setModelo] = useState('')
    const [tipo, setTipo] = useState(opcoes.tipos.length === 1 ? opcoes.tipos[0].id : null)
    const [info, setInfo] = useState(null)
    const [pacote, setPacote] = useState(null)
    const [forma, setForma] = useState(null)
    const [recebido, setRecebido] = useState(0)
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [confirmarMensalista, setConfirmarMensalista] = useState(null) // { pin, dados, texto }
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
    const rotulo = semPlaca ? identificacao.trim() : placa

    function enviar(pin, dados) {
        setEnviando(true)
        setErro(null)
        api('POST', '/estadias', { pin, ...dados }).then((j) => setFeito({ ...j, recebido: dados.valor_recebido })).catch((e) => {
            if (e.campos?.mensalista) { setConfirmarMensalista({ pin, dados, texto: e.campos.mensalista[0] }); return }
            setErro(e.message)
        }).finally(() => setEnviando(false))
    }
    /** registra com o que foi escolhido: por hora (sem diária), diária paga na saída ou paga agora */
    function registrar({ comPacote = null, pagarAgora = false, valorRecebido = null } = {}) {
        const dados = {
            placa: semPlaca ? null : placa, identificacao: semPlaca ? identificacao : null, modelo, tipo_veiculo_id: tipo,
            pacote_id: comPacote, pagar_agora: pagarAgora, forma_pagamento_id: pagarAgora ? forma : null, valor_recebido: valorRecebido,
        }
        pedirPin('PIN para registrar a entrada').then(({ pin }) => enviar(pin, dados)).catch(() => {})
    }
    const ir = (e) => { setErro(null); setEtapa(e) }
    const voltar = () => ir({ veiculo: 'placa', cobranca: 'veiculo', diaria: 'cobranca', forma: 'diaria', dinheiro: 'forma' }[etapa])

    if (feito) {
        const r = feito.recibo
        const tk = ticketDaEstadia(r, eu)
        const troco = r.pago && feito.recebido ? Math.round((feito.recebido - r.pago.valor) * 100) / 100 : 0
        return (
            <PerguntaImprimir titulo={troco > 0 ? 'Entregue o troco. Imprimir o ticket?' : `Entrada de ${r.placa} registrada. Imprimir o ticket?`}
                destaque={troco > 0 && <div className="pdv-troco-final"><div>Troco <strong>{moeda(troco)}</strong></div></div>}
                montar={(op) => montarTicket(tk, op)}
                previa={<Previa linhas={linhasTicket(r, eu)} qr={tk.link} colunas={preferencia.get().colunas} />}
                onFim={() => onFeito(`Entrada de ${r.placa} · Nº ${r.numero}${r.pago ? ` · ${r.servico} paga (${moeda(r.pago.valor)})` : ''}.`)} />
        )
    }

    const placaOk = semPlaca ? identificacao.trim().length > 0 : placa.length >= 7
    return (
        <div className="pb-5">
            <div className="pdv-resumo-fixo">
                <button type="button" className="btn btn-link px-0 mb-1" onClick={etapa === 'placa' ? onVoltar : voltar}>‹ {etapa === 'placa' ? 'Estadias' : 'Voltar'}</button>
                <div className="d-flex justify-content-between align-items-baseline">
                    <h1 className="h5 mb-0">Entrada</h1>
                    {etapa !== 'placa' && <span className="h4 mb-0">{rotulo}</span>}
                </div>
                {['cobranca', 'diaria', 'forma', 'dinheiro'].includes(etapa) && (
                    <div className="small text-muted text-end">{[modelo, tipoObj?.nome, diaria && `${diaria.nome} ${moeda(diaria.valor)}`].filter(Boolean).join(' · ')}</div>
                )}
            </div>

            {etapa === 'placa' && (
                <div className="mt-2">
                    {semPlaca ? (
                        <>
                            <label className="form-label fw-semibold mb-1" htmlFor="ident">Identificação (sem placa)</label>
                            <input id="ident" className="form-control form-control-lg" value={identificacao} maxLength={40} autoFocus onChange={(e) => setIdentificacao(e.target.value)} placeholder="Ex.: bicicleta azul" />
                        </>
                    ) : (
                        <>
                            <label className="form-label fw-semibold mb-1" htmlFor="placa">Placa</label>
                            <input id="placa" className="form-control form-control-lg pdv-placa" value={placa} autoComplete="off" autoCapitalize="characters" autoFocus
                                onChange={(e) => { setPlaca(soPlaca(e.target.value)); setModelo('') }} />
                        </>
                    )}
                    <button type="button" className="btn btn-sm btn-link px-0 mt-1" onClick={() => setSemPlaca(!semPlaca)}>{semPlaca ? 'Tem placa' : 'Veículo sem placa'}</button>

                    {info?.no_patio && <div className="alert alert-danger py-2 small mt-2">Já está no pátio: Nº {info.no_patio.numero}, entrou {info.no_patio.entrada}.</div>}
                    {info?.veiculo && (
                        <div className={`alert py-2 small mt-2 ${info.veiculo.mensalista ? 'alert-warning' : 'aviso-neutro'}`}>
                            {info.veiculo.mensalista ? <><strong>Mensalista</strong> · {info.veiculo.cliente}. Mensalista não registra entrada.</> : <>Cadastrado: {info.veiculo.cliente}</>}
                        </div>
                    )}
                    {info && !info.veiculo && info.ultima && <div className="small text-muted mt-2">Já esteve aqui em {info.ultima.quando}.</div>}

                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!placaOk || !!info?.no_patio} onClick={() => ir('veiculo')}>Continuar</button>
                </div>
            )}

            {etapa === 'veiculo' && (
                <div className="mt-2">
                    <label className="form-label fw-semibold mb-1" htmlFor="modelo">Modelo</label>
                    <input id="modelo" className="form-control form-control-lg mb-3" value={modelo} maxLength={40} onChange={(e) => setModelo(e.target.value)} placeholder="Ex.: Gol branco" />
                    <div className="fw-semibold mb-1">Tamanho</div>
                    <div className="pdv-opcoes">
                        {opcoes.tipos.map((t) => <button key={t.id} type="button" className={`btn btn-lg ${tipo === t.id ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => { setTipo(t.id); setPacote(null) }}>{t.nome}</button>)}
                    </div>
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    {diarias.length > 0 || !tipo
                        ? <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!tipo} onClick={() => ir('cobranca')}>Continuar</button>
                        : <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => registrar()}>{enviando ? 'Registrando…' : 'Registrar entrada'}</button>}
                </div>
            )}

            {etapa === 'cobranca' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Como vai cobrar?</div>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-primary btn-lg" disabled={enviando} onClick={() => { setPacote(null); registrar() }}>Por hora · registrar entrada</button>
                        {diarias.map((d) => (
                            <button key={d.id} type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setPacote(d.id); ir('diaria') }}>{d.nome} · {moeda(d.valor)}</button>
                        ))}
                    </div>
                    <div className="small text-muted mt-2">Diária é preço fechado, mesmo saindo antes. Passou do tempo, paga a diferença na saída.</div>
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                </div>
            )}

            {etapa === 'diaria' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Quando paga a {diaria?.nome}?</div>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-primary btn-lg" onClick={() => { setForma(null); ir('forma') }}>Paga agora ({moeda(diaria?.valor)})</button>
                        <button type="button" className="btn btn-outline-primary btn-lg" disabled={enviando} onClick={() => registrar({ comPacote: pacote })}>Paga na saída · registrar entrada</button>
                    </div>
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                </div>
            )}

            {etapa === 'forma' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Forma de pagamento</div>
                    <Formas formas={opcoes.formas} value={forma} onChange={(fid) => {
                        setForma(fid)
                        setErro(null)
                        if (opcoes.formas.find((x) => x.id === fid)?.tipo === 'dinheiro') { setRecebido(0); setEtapa('dinheiro') }
                    }} />
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    {formaObj && formaObj.tipo !== 'dinheiro' && (
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => registrar({ comPacote: pacote, pagarAgora: true })}>
                            {enviando ? 'Registrando…' : `Recebi ${moeda(diaria?.valor)} no ${formaObj.nome.replace(/^Cartão de /i, '').toLowerCase()}`}
                        </button>
                    )}
                </div>
            )}

            {etapa === 'dinheiro' && (() => {
                const total = diaria?.valor || 0
                return (
                    <div className="mt-3">
                        <label className="form-label fw-semibold mb-1" htmlFor="recebido">Quanto o cliente deu?</label>
                        <CampoDinheiro id="recebido" autoFocus value={recebido} onChange={setRecebido} />
                        <div className="pdv-opcoes mt-2">
                            <button type="button" className="btn btn-outline-secondary" onClick={() => setRecebido(total)}>Valor exato</button>
                            {[...new Set([Math.ceil(total / 10) * 10, Math.ceil(total / 50) * 50, 100, 200].filter((v) => v > total))].slice(0, 3)
                                .map((v) => <button key={v} type="button" className="btn btn-outline-secondary" onClick={() => setRecebido(v)}>{moeda(v)}</button>)}
                        </div>
                        {recebido > 0 && recebido + 0.001 < total && <div className="text-danger small mt-2">Menor que {moeda(total)}.</div>}
                        {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando || recebido + 0.001 < total}
                            onClick={() => registrar({ comPacote: pacote, pagarAgora: true, valorRecebido: recebido })}>
                            {enviando ? 'Registrando…' : recebido >= total ? `Confirmar ${moeda(recebido)} recebidos` : 'Confirmar valor recebido'}
                        </button>
                    </div>
                )
            })()}

            {confirmarMensalista && (
                <Folha titulo="Placa de mensalista" onFechar={() => setConfirmarMensalista(null)}>
                    <p className="small">{confirmarMensalista.texto}</p>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-warning btn-lg" onClick={() => { const c = confirmarMensalista; setConfirmarMensalista(null); enviar(c.pin, { ...c.dados, confirmar_mensalista: true }) }}>Registrar mesmo assim</button>
                        <button type="button" className="btn btn-outline-secondary btn-lg" onClick={() => setConfirmarMensalista(null)}>Não registrar</button>
                    </div>
                </Folha>
            )}
        </div>
    )
}
