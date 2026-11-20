import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { CampoDinheiro, Folha, Formas, PerguntaImprimir, PixQr, moeda, papel, usaPixQr } from '../comum'
import { dadosReciboLavagem, dadosTicketLavagem } from '../impressao/modelo'
import { preferencia } from '../impressao/preferencia'
import { cursorNoFim } from '../../Components/CampoValor'
import LeitorQr from '../LeitorQr'
import { Icone, PlacaMercosul, TopoEtapa, passoDe, rolarAoTopo, useAvisos, useErro } from '../visual'

const soPlaca = (v) => v.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 7)
const atalhos = (total) => [...new Set([Math.ceil(total / 10) * 10, Math.ceil(total / 50) * 50, 100, 200].filter((v) => v > total))].slice(0, 3)
const nomeForma = (f) => f.nome.replace(/^Cartão de /i, '').toLowerCase()

/** topo de cada etapa (8.8): voltar, título, bolinhas e, se houver, o resumo fixo embaixo */
function Topo({ titulo, onVoltar, direita = null, linha = null, extra = null, passo = null }) {
    const lado = direita || extra
    return (
        <>
            <TopoEtapa titulo={titulo} onVoltar={onVoltar} passo={passo} />
            {(lado || linha) && (
                <div className="pdv-resumo-fixo">
                    {lado && <div className={`d-flex align-items-baseline gap-2 ${extra ? 'justify-content-end' : 'justify-content-between'}`}>{lado}</div>}
                    {linha && <div className="small text-muted">{linha}</div>}
                </div>
            )}
        </>
    )
}

/** dinheiro: quanto o cliente deu */
function Recebido({ total, value, onChange, onConfirmar, enviando, erro, rotulo = 'Confirmar' }) {
    return (
        <div className="mt-3">
            <label className="form-label fw-semibold mb-1" htmlFor="recebido">Quanto o cliente deu?</label>
            <CampoDinheiro id="recebido" autoFocus value={value} onChange={onChange} />
            <div className="pdv-opcoes mt-2">
                <button type="button" className="btn btn-outline-secondary" onClick={() => onChange(total)}>Valor exato</button>
                {atalhos(total).map((v) => <button key={v} type="button" className="btn btn-outline-secondary" onClick={() => onChange(v)}>{moeda(v)}</button>)}
            </div>
            {value > 0 && value + 0.001 < total && <div className="text-danger small mt-2">Menor que {moeda(total)}.</div>}
            <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando || value + 0.001 < total} onClick={onConfirmar}>
                {enviando ? 'Registrando…' : value >= total ? `${rotulo} ${moeda(value)} recebidos` : 'Confirmar valor recebido'}
            </button>
        </div>
    )
}

/** nova lavagem: placa → (estadia no pátio) → modelo e tamanho → serviço → adicionais → celular → quando paga → forma → dinheiro */
function Nova({ eu, opcoes, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [etapa, setEtapa] = useState('placa')
    const [placa, setPlaca] = useState('')
    const [info, setInfo] = useState(null)
    const [estadiaAcao, setEstadiaAcao] = useState(null)
    const [modelo, setModelo] = useState('')
    const [tipo, setTipo] = useState(null)
    const [servico, setServico] = useState(null)
    const [valorAte, setValorAte] = useState(0)
    const [adicionais, setAdicionais] = useState([])
    const [celular, setCelular] = useState('')
    const [forma, setForma] = useState(null)
    const [recebido, setRecebido] = useState(0)
    const [erro, setErro] = useErro()
    const [enviando, setEnviando] = useState(false)
    const [feito, setFeito] = useState(null)

    useEffect(() => {
        setInfo(null)
        if (placa.length < 7) return undefined
        let vivo = true
        api('GET', `/lavagens/placa/${placa}`).then((j) => {
            if (!vivo) return
            setInfo(j)
            const s = j.veiculo || j.ultima
            if (s?.modelo) setModelo((m) => m || s.modelo.toUpperCase())
            if (s?.tipo_id && opcoes.tipos.some((t) => t.id === s.tipo_id)) setTipo(s.tipo_id)
            if (j.ultima?.celular) setCelular((c) => c || j.ultima.celular)
        }).catch(() => {})
        return () => { vivo = false }
    }, [placa, opcoes.tipos])

    const tipoObj = opcoes.tipos.find((t) => t.id === tipo)
    const servicos = opcoes.servicos.filter((s) => s.precos[tipo] !== undefined)
    const sv = opcoes.servicos.find((s) => s.id === servico)
    const precoServico = sv ? (sv.preco_modo === 'ate' ? valorAte : sv.precos[tipo]) : 0
    const adicionaisDisp = (sv?.adicionais || []).filter((a) => a.precos[tipo] !== undefined)
    const total = Math.round((precoServico + adicionais.reduce((s, id) => s + (adicionaisDisp.find((a) => a.id === id)?.precos[tipo] || 0), 0)) * 100) / 100
    const formaObj = opcoes.formas.find((f) => f.id === forma)

    const ir = (e) => { setErro(null); setEtapa(e) }
    const depoisVeiculo = () => ir('servico')
    const depoisServico = () => (adicionaisDisp.length ? ir('adicionais') : depoisAdicionais())
    const depoisAdicionais = () => (info?.veiculo ? ir('pagamento') : ir('celular'))
    const escolherServico = (s) => {
        setServico(s.id)
        setAdicionais([])
        setValorAte(s.precos[tipo])
        if (s.preco_modo === 'ate') ir('valor')
        else if (s.adicionais.some((a) => a.precos[tipo] !== undefined)) ir('adicionais')
        else depoisAdicionais()
    }
    const voltar = () => ir({ estadia: 'placa', veiculo: info?.estadia ? 'estadia' : 'placa', servico: 'veiculo', valor: 'servico',
        adicionais: sv?.preco_modo === 'ate' ? 'valor' : 'servico', celular: adicionaisDisp.length ? 'adicionais' : (sv?.preco_modo === 'ate' ? 'valor' : 'servico'),
        pagamento: info?.veiculo ? (adicionaisDisp.length ? 'adicionais' : 'servico') : 'celular', forma: 'pagamento', dinheiro: 'forma', pix: 'forma' }[etapa])

    const registrar = ({ agora = false, valorRecebido = null, pixId = null } = {}) => {
        const cancelar = info?.estadia && estadiaAcao === 'cancelar'
        pedirPin(cancelar ? 'PIN de gerente (cancela a estadia)' : 'PIN para registrar a lavagem').then(({ pin }) => {
            setEnviando(true)
            setErro(null)
            api('POST', '/lavagens', {
                pin, placa, modelo, tipo_veiculo_id: tipo, celular, servico_id: servico, adicionais, valor: sv?.preco_modo === 'ate' ? valorAte : null,
                pagar_agora: agora, forma_pagamento_id: agora ? forma : null, valor_recebido: valorRecebido, pix_id: pixId,
                estadia_id: info?.estadia?.id ?? null, estadia_acao: info?.estadia ? estadiaAcao : null,
            }).then(setFeito).catch((e) => setErro(e.message)).finally(() => setEnviando(false))
        }).catch(() => {})
    }

    if (feito) {
        const r = feito.recibo
        return (
            <PerguntaImprimir titulo={feito.troco > 0 ? 'Entregue o troco. Imprimir o ticket?' : `Lavagem de ${r.placa} registrada. Imprimir o ticket?`}
                destaque={feito.troco > 0 && <div className="pdv-troco-final"><div>Troco <strong>{moeda(feito.troco)}</strong></div></div>}
                {...papel(eu, 'ticket', dadosTicketLavagem(r, eu))}
                onFim={() => onFeito(feito.mensagem)} />
        )
    }

    const resumo = [modelo, tipoObj?.nome, sv?.nome].filter(Boolean).join(' · ')
    return (
        <div className="pb-5">
            <Topo titulo="Entrada · lavagem" onVoltar={etapa === 'placa' ? onVoltar : voltar}
                passo={passoDe(['placa', 'veiculo', 'servico', 'adicionais', 'celular', 'pagamento', 'forma'], etapa, { estadia: 'placa', valor: 'servico', dinheiro: 'forma', pix: 'forma' })}
                direita={etapa !== 'placa' && <span className="fs-4 fw-bold" style={{ letterSpacing: '.04em' }}>{placa}</span>}
                linha={['servico', 'valor', 'adicionais', 'celular', 'pagamento', 'forma', 'dinheiro', 'pix'].includes(etapa) ? [resumo, total ? moeda(total) : null].filter(Boolean).join(' · ') : null} />

            {etapa === 'placa' && (
                <div className="mt-2">
                    <h2 className="h4 fw-bold text-center mb-3">Placa do veículo</h2>
                    <PlacaMercosul value={placa} onChange={(v) => { setPlaca(soPlaca(v)); setModelo(''); setCelular('') }}
                        onEnter={() => { if (placa.length >= 7 && info && !info.em_andamento) ir(info.estadia ? 'estadia' : 'veiculo') }} />
                    {info?.em_andamento && <div className="alert alert-danger py-2 small mt-2">Já tem lavagem em andamento para esta placa: Nº {info.em_andamento.numero}.</div>}
                    {info?.estadia && <div className="alert alert-warning py-2 small mt-2">Está no pátio: estadia Nº {info.estadia.numero}, entrou {info.estadia.entrada}.</div>}
                    {info?.veiculo && (
                        <div className={`alert py-2 small mt-2 ${info.veiculo.mensalista ? 'alert-warning' : 'aviso-neutro'}`}>
                            {info.veiculo.mensalista ? <><strong>Mensalista</strong> · {info.veiculo.cliente}</> : <>Cadastrado: {info.veiculo.cliente}</>}
                        </div>
                    )}
                    {info && !info.veiculo && info.ultima && (
                        <div className="pdv-cartao mt-2">
                            <span className="pdv-cartao-icone" style={{ width: 40, height: 40, borderRadius: 12 }}><Icone nome="relogio" tamanho={20} /></span>
                            <span className="pdv-cartao-texto">
                                <strong style={{ fontSize: '1rem' }}>{[info.ultima.modelo, opcoes.tipos.find((t) => t.id === info.ultima.tipo_id)?.nome].filter(Boolean).join(' · ') || 'Já esteve aqui'}</strong>
                                <span>Já esteve aqui em {info.ultima.quando} · dados preenchidos</span>
                            </span>
                        </div>
                    )}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={placa.length < 7 || !info || !!info.em_andamento} onClick={() => ir(info.estadia ? 'estadia' : 'veiculo')}>Continuar</button>
                </div>
            )}

            {etapa === 'estadia' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-1">O carro está em estadia (Nº {info.estadia.numero}, desde {info.estadia.entrada}). O que fazer com ela?</div>
                    <div className="small text-muted mb-2">A lavagem ganha um ticket próprio.</div>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setEstadiaAcao('manter'); ir('veiculo') }}>Continua cobrando<span className="d-block small">a estadia sai normalmente depois</span></button>
                        <button type="button" className="btn btn-outline-danger btn-lg" disabled={info.estadia.pago_entrada > 0} onClick={() => { setEstadiaAcao('cancelar'); ir('veiculo') }}>Cancelar a estadia<span className="d-block small">pede o PIN de um gerente</span></button>
                    </div>
                    {info.estadia.pago_entrada > 0 && <div className="small text-muted mt-2">A diária foi paga na entrada: a estadia não pode ser cancelada aqui.</div>}
                </div>
            )}

            {etapa === 'veiculo' && (
                <div className="mt-2">
                    <label className="form-label fw-semibold mb-1" htmlFor="modelo">Modelo</label>
                    <input id="modelo" className="form-control form-control-lg mb-3" value={modelo} maxLength={60} autoFocus enterKeyHint="done" onFocus={(e) => cursorNoFim(e.target)}
                        onKeyDown={(e) => { if (e.key === 'Enter') e.target.blur() }} autoCapitalize="characters" onChange={(e) => setModelo(e.target.value.toUpperCase())} placeholder="Ex.: ONIX" />
                    <div className="fw-semibold mb-1">Tamanho</div>
                    <div className="pdv-opcoes">
                        {opcoes.tipos.map((t) => <button key={t.id} type="button" className={`btn btn-lg ${tipo === t.id ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => { setTipo(t.id); setServico(null); setAdicionais([]) }}>{t.nome}</button>)}
                    </div>
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!tipo} onClick={depoisVeiculo}>Continuar</button>
                </div>
            )}

            {etapa === 'servico' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Serviço</div>
                    {servicos.length === 0 && <div className="alert alert-warning small">Nenhum serviço com preço para {tipoObj?.nome}. Cadastre em Configurações › Lavagens e serviços.</div>}
                    <div className="list-group">
                        {servicos.map((s) => (
                            <button key={s.id} type="button" className="list-group-item list-group-item-action d-flex justify-content-between py-3"
                                onClick={() => escolherServico(s)}>
                                <span>{s.nome}</span><strong>{s.preco_modo === 'ate' ? `até ${moeda(s.precos[tipo])}` : moeda(s.precos[tipo])}</strong>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {etapa === 'valor' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="valorAte">Valor de {sv?.nome} (até {moeda(sv?.precos[tipo])})</label>
                    <CampoDinheiro id="valorAte" autoFocus value={valorAte} onChange={setValorAte} />
                    {valorAte > sv?.precos[tipo] && <div className="text-danger small mt-2">No máximo {moeda(sv.precos[tipo])}.</div>}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={valorAte <= 0 || valorAte > sv?.precos[tipo]} onClick={depoisServico}>Continuar</button>
                </div>
            )}

            {etapa === 'adicionais' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Adicionais</div>
                    <div className="list-group">
                        {adicionaisDisp.map((a) => (
                            <label key={a.id} className="list-group-item d-flex gap-3 align-items-center py-3">
                                <input className="form-check-input m-0" type="checkbox" checked={adicionais.includes(a.id)} onChange={(e) => setAdicionais(e.target.checked ? [...adicionais, a.id] : adicionais.filter((x) => x !== a.id))} />
                                <span className="flex-grow-1">{a.nome}</span><strong>{moeda(a.precos[tipo])}</strong>
                            </label>
                        ))}
                    </div>
                    <div className="d-flex justify-content-between fs-4 fw-bold mt-3"><span>Total</span><span>{moeda(total)}</span></div>
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" onClick={depoisAdicionais}>{adicionais.length ? 'Continuar' : 'Sem adicionais'}</button>
                </div>
            )}

            {etapa === 'celular' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="celular">Celular / WhatsApp (opcional)</label>
                    <input id="celular" className="form-control form-control-lg" inputMode="tel" autoComplete="off" autoFocus value={celular} maxLength={20} onFocus={(e) => cursorNoFim(e.target)} onChange={(e) => setCelular(e.target.value)} />
                    <div className="small text-muted mt-1">Só para avisar o cliente quando a lavagem ficar pronta.</div>
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" onClick={() => ir('pagamento')}>{celular.trim() ? 'Continuar' : 'Sem celular'}</button>
                </div>
            )}

            {etapa === 'pagamento' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Quando paga?</div>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-primary btn-lg" disabled={enviando} onClick={() => registrar()}>Na entrega · registrar lavagem</button>
                        <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setForma(null); ir('forma') }}>Pagar agora ({moeda(total)})</button>
                    </div>
                </div>
            )}

            {etapa === 'forma' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Forma de pagamento</div>
                    <Formas formas={opcoes.formas} value={forma} onChange={(fid) => {
                        setForma(fid); setErro(null)
                        const f = opcoes.formas.find((x) => x.id === fid)
                        if (f?.tipo === 'dinheiro') { setRecebido(0); setEtapa('dinheiro') } else if (usaPixQr(eu, f)) setEtapa('pix')
                    }} />
                    {formaObj && formaObj.tipo !== 'dinheiro' && !usaPixQr(eu, formaObj) && (
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => registrar({ agora: true })}>{enviando ? 'Registrando…' : `Recebi ${moeda(total)} no ${nomeForma(formaObj)}`}</button>
                    )}
                </div>
            )}

            {etapa === 'pix' && <PixQr valor={total} descricao={`Lavagem ${placa}`.trim()} enviando={enviando} erro={erro}
                onPago={(pixId) => registrar({ agora: true, pixId })} onManual={() => registrar({ agora: true })} />}

            {etapa === 'dinheiro' && <Recebido total={total} value={recebido} onChange={setRecebido} onConfirmar={() => registrar({ agora: true, valorRecebido: recebido })} enviando={enviando} erro={erro} />}
        </div>
    )
}

/** entrega: resumo e A pagar fixos → convênio + paga agora / na conta de → forma → dinheiro → recibo */
function Entrega({ eu, opcoes, id, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [dados, setDados] = useState(null)
    const [etapa, setEtapa] = useState('como')
    const [pixFixo, setPixFixo] = useState(null) // valor e hora do cálculo congelados enquanto o QR está na tela
    const [convenio, setConvenio] = useState('')
    const [contaDe, setContaDe] = useState(null)
    const [busca, setBusca] = useState('')
    const [achados, setAchados] = useState([])
    const [forma, setForma] = useState(null)
    const [recebido, setRecebido] = useState(0)
    const [erro, setErro] = useErro()
    const [enviando, setEnviando] = useState(false)
    const [feito, setFeito] = useState(null)

    const calcular = useCallback(() => {
        api('GET', `/lavagens/${id}/calculo${convenio ? `?convenio_id=${convenio}` : ''}`).then((j) => { setDados(j); setErro(null) }).catch((e) => setErro(e.message))
    }, [id, convenio])
    // com o QR na tela o valor fica parado (o Pix é do valor exato)
    const noPix = etapa === 'pix'
    useEffect(() => { if (noPix) return undefined; calcular(); const t = setInterval(calcular, 60000); return () => clearInterval(t) }, [calcular, noPix])
    useEffect(() => {
        if (busca.trim().length < 2) { setAchados([]); return undefined }
        const t = setTimeout(() => api('GET', `/clientes?q=${encodeURIComponent(busca.trim())}`).then(setAchados).catch(() => {}), 250)
        return () => clearTimeout(t)
    }, [busca])

    if (feito) {
        const r = feito.recibo
        return (
            <PerguntaImprimir titulo={feito.troco > 0 ? 'Entregue o troco. Imprimir o recibo?' : `${feito.mensagem} Imprimir o recibo?`}
                destaque={feito.troco > 0 && <div className="pdv-troco-final"><div>Troco <strong>{moeda(feito.troco)}</strong></div></div>}
                {...papel(eu, 'recibo', dadosReciboLavagem(r, eu))}
                onFim={() => onFeito(feito.mensagem)} />
        )
    }
    if (!dados) return <div><TopoEtapa titulo="Entrega · lavagem" onVoltar={onVoltar} />{erro ? <div className="alert alert-danger">{erro}</div> : <div className="text-muted text-center py-4">Calculando…</div>}</div>

    const { lavagem: l, calculo: c } = dados
    const formaObj = opcoes.formas.find((f) => f.id === forma)
    const entregar = (extra) => pedirPin('PIN para entregar a lavagem').then(({ pin }) => {
        setEnviando(true)
        setErro(null)
        api('POST', `/lavagens/${id}/entregar`, { pin, convenio_id: convenio || null, ...extra })
            .then(setFeito).catch((e) => setErro(e.message)).finally(() => setEnviando(false))
    }).catch(() => {})
    const voltar = () => { setErro(null); setEtapa({ forma: 'como', conta: 'como', dinheiro: 'forma', pix: 'forma' }[etapa]) }

    return (
        <div className="pb-5">
            <TopoEtapa titulo="Entrega · lavagem" onVoltar={etapa === 'como' ? onVoltar : voltar}
                passo={passoDe(['como', 'forma', 'fim'], etapa, { dinheiro: 'fim', pix: 'fim', conta: 'forma' })} />
            <div className="pdv-resumo-fixo">
                <div className="d-flex justify-content-between align-items-baseline"><span className="fs-4 fw-bold" style={{ letterSpacing: '.04em' }}>{l.placa}</span><span className="text-muted small">Nº {l.numero} · {l.etapa_nome}</span></div>
                <div className="small text-muted">{[l.modelo, l.tipo, l.cliente?.nome].filter(Boolean).join(' · ')}</div>
                <div className="border rounded p-2 mt-2">
                    {c.itens.map((i) => <div key={i.id} className="d-flex justify-content-between small"><span>{i.descricao}</span><span>{moeda(i.valor)}</span></div>)}
                    {c.estadia && <div className="d-flex justify-content-between small"><span>Estadia depois de pronta ({c.estadia.tempo})</span><span>{moeda(c.estadia.valor)}</span></div>}
                    {c.convenio > 0 && <div className="d-flex justify-content-between small"><span>Convênio paga</span><span>-{moeda(c.convenio)}</span></div>}
                    {c.pago_antes > 0 && <div className="d-flex justify-content-between small text-muted"><span>Já pago</span><span>{moeda(c.pago_antes)}</span></div>}
                    {c.na_conta > 0 && <div className="d-flex justify-content-between small text-muted"><span>Na conta de {c.na_conta_de}</span><span>{moeda(c.na_conta)}</span></div>}
                    <div className="d-flex justify-content-between fs-3 fw-bold"><span>A pagar</span><span>{moeda(c.a_pagar)}</span></div>
                </div>
            </div>

            {etapa === 'como' && (
                <div className="mt-3">
                    {opcoes.convenios.length > 0 && c.itens.length > 0 && (
                        <div className="mb-3">
                            <label className="form-label small mb-1" htmlFor="convenio">Convênio</label>
                            <select id="convenio" className="form-select form-select-lg" value={convenio} onChange={(e) => setConvenio(e.target.value ? Number(e.target.value) : '')}>
                                <option value="">Sem convênio</option>
                                {opcoes.convenios.map((v) => <option key={v.id} value={v.id}>{v.nome}</option>)}
                            </select>
                            {c.regras_convenio?.length > 0 && <div className="small text-muted mt-1">{c.regras_convenio.join(' · ')}</div>}
                        </div>
                    )}
                    {c.a_pagar > 0 ? (
                        <div className="d-grid gap-2">
                            <button type="button" className="btn btn-primary btn-lg" onClick={() => { setForma(null); setEtapa('forma') }}>Pagar agora</button>
                            {l.cliente && <button type="button" className="btn btn-outline-primary btn-lg" disabled={enviando} onClick={() => entregar({ destino: 'conta', conta_cliente_id: l.cliente.id })}>Na conta de {l.cliente.nome}</button>}
                            <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setContaDe(null); setEtapa('conta') }}>{l.cliente ? 'Na conta de outro cliente' : 'Na conta de um cliente'}</button>
                        </div>
                    ) : (
                        <button type="button" className="btn btn-primary btn-lg w-100" disabled={enviando} onClick={() => entregar({ destino: 'pago' })}>{enviando ? 'Entregando…' : 'Nada a pagar · entregar'}</button>
                    )}
                </div>
            )}

            {etapa === 'forma' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Forma de pagamento</div>
                    <Formas formas={opcoes.formas} value={forma} onChange={(fid) => {
                        setForma(fid); setErro(null)
                        const f = opcoes.formas.find((x) => x.id === fid)
                        if (f?.tipo === 'dinheiro') { setRecebido(0); setEtapa('dinheiro') } else if (usaPixQr(eu, f)) { setPixFixo({ valor: c.a_pagar, em: c.calculado_em }); setEtapa('pix') }
                    }} />
                    {formaObj && formaObj.tipo !== 'dinheiro' && !usaPixQr(eu, formaObj) && (
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => entregar({ destino: 'pago', forma_pagamento_id: forma, calculado_em: c.calculado_em })}>{enviando ? 'Entregando…' : `Recebi ${moeda(c.a_pagar)} no ${nomeForma(formaObj)}`}</button>
                    )}
                </div>
            )}

            {etapa === 'pix' && <PixQr valor={pixFixo.valor} descricao={`Lavagem ${l.placa || ''}`.trim()} enviando={enviando} erro={erro}
                onPago={(pixId) => entregar({ destino: 'pago', forma_pagamento_id: forma, pix_id: pixId, calculado_em: pixFixo.em })}
                onManual={() => entregar({ destino: 'pago', forma_pagamento_id: forma, calculado_em: pixFixo.em })} />}

            {etapa === 'dinheiro' && <Recebido total={c.a_pagar} value={recebido} onChange={setRecebido} onConfirmar={() => entregar({ destino: 'pago', forma_pagamento_id: forma, valor_recebido: recebido, calculado_em: c.calculado_em })} enviando={enviando} erro={erro} />}

            {etapa === 'conta' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Na conta de quem?</div>
                    {contaDe ? (
                        <div className="border rounded p-3 d-flex justify-content-between align-items-center"><strong>{contaDe.nome}</strong><button type="button" className="btn btn-sm btn-link" onClick={() => setContaDe(null)}>trocar</button></div>
                    ) : (
                        <>
                            <input className="form-control form-control-lg" placeholder="Buscar cliente (nome ou código)" aria-label="Buscar cliente" value={busca} autoFocus onChange={(e) => setBusca(e.target.value)} autoComplete="off" />
                            <div className="list-group mt-1">{achados.map((a) => <button key={a.id} type="button" className="list-group-item list-group-item-action py-3" onClick={() => { setContaDe(a); setBusca('') }}>{a.nome}</button>)}</div>
                        </>
                    )}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!contaDe || enviando} onClick={() => entregar({ destino: 'conta', conta_cliente_id: contaDe.id })}>{contaDe ? `Lançar ${moeda(c.a_pagar)} na conta e entregar` : 'Escolha o cliente'}</button>
                </div>
            )}
        </div>
    )
}

/** PDV › Lavagem (8.4): andamento, nova, etapas e entrega */
/** lavagens: menu (em andamento), nova e entrega. inicio = 'nova' ou 'entrega' quando vem direto do Início */
export default function Lavagens({ eu, onVoltar, onSair, inicio = null, entregaInicial = null }) {
    const avisos = useAvisos()
    const { pedirPin } = usePin()
    const [opcoes, setOpcoes] = useState(null)
    const [lista, setLista] = useState(null)
    const [tela, setTela] = useState(inicio || 'menu')
    const [entregaId, setEntregaId] = useState(entregaInicial)
    const [busca, setBusca] = useState('')
    const [lendo, setLendo] = useState(false)
    const [erro, setErro] = useErro()
    const [avancando, setAvancando] = useState(null)
    const setAviso = (m) => m && avisos.ok(m)

    const carregar = useCallback(() => api('GET', '/lavagens/andamento').then(setLista).catch((e) => setErro(e.message)), [])
    useEffect(() => {
        api('GET', '/lavagens/opcoes').then(setOpcoes).catch((e) => setErro(e.message))
        carregar()
        const t = setInterval(carregar, 60000)
        return () => clearInterval(t)
    }, [carregar])
    // veio direto do Início: termina voltando para lá; senão, volta ao menu das lavagens
    const feito = (msg) => { setAviso(msg); if (inicio) { onSair(); return } setTela('menu'); carregar(); rolarAoTopo() }
    const voltarSub = () => (inicio ? onVoltar() : setTela('menu'))
    const procurar = useCallback((q) => {
        if (!q.trim()) return
        setErro(null)
        api('GET', `/lavagens/buscar?q=${encodeURIComponent(q.trim())}`).then((j) => { setEntregaId(j.lavagem.id); setTela('entrega') }).catch((e) => setErro(e.message))
    }, [])
    const lido = useCallback((t) => { setLendo(false); procurar(t) }, [procurar])
    const avancar = (l) => pedirPin(`PIN: ${l.placa} → ${l.proxima.nome}`).then(({ pin }) => {
        setAvancando(l.id)
        api('POST', `/lavagens/${l.id}/etapa`, { pin }).then(() => { setAviso(`${l.placa}: ${l.proxima.nome}.`); carregar() }).catch((e) => setErro(e.message)).finally(() => setAvancando(null))
    }).catch(() => {})

    if (!opcoes) return <div><TopoEtapa titulo="Lavagem" onVoltar={inicio ? onVoltar : onSair} />{erro ? <div className="alert alert-danger">{erro}</div> : <div className="text-muted text-center py-4">Carregando…</div>}</div>
    if (tela === 'nova') return <Nova eu={eu} opcoes={opcoes} onVoltar={voltarSub} onFeito={feito} />
    if (tela === 'entrega') return <Entrega eu={eu} opcoes={opcoes} id={entregaId} onVoltar={voltarSub} onFeito={feito} />

    const filtro = busca.replace(/[^A-Za-z0-9]/g, '').toUpperCase()
    const visiveis = (lista || []).filter((l) => !filtro || (l.placa || '').replace('-', '').includes(filtro) || String(l.numero).includes(filtro))
    return (
        <div>
            <TopoEtapa titulo="Lavagem" onVoltar={onSair} />
            <div className="pdv-botoes-grandes mb-3">
                <button type="button" className="pdv-grande" onClick={() => setTela('nova')}><Icone nome="mais" tamanho={28} className="mb-auto" /><span className="pdv-grande-titulo">Nova</span><span className="small">lavagem</span></button>
                <button type="button" className="pdv-grande" onClick={() => setLendo(true)}><Icone nome="qr" tamanho={28} className="mb-auto" /><span className="pdv-grande-titulo">Entregar</span><span className="small">ler o QR</span></button>
            </div>
            <form className="d-flex gap-2 mb-2" onSubmit={(e) => { e.preventDefault(); procurar(busca) }}>
                <input className="form-control form-control-lg" placeholder="Nº do ticket ou placa" aria-label="Número do ticket ou placa" value={busca} autoComplete="off" onChange={(e) => { setBusca(e.target.value.toUpperCase()); setErro(null) }} />
                <button type="submit" className="btn btn-primary btn-lg px-3" aria-label="Buscar" disabled={!busca.trim()}><Icone nome="busca" tamanho={22} /></button>
            </form>
            <div className="d-flex align-items-baseline gap-2 mt-3 mb-2"><h2 className="h6 mb-0">Em andamento</h2><span className="small text-muted">{lista ? `${lista.length} lavagem(ns)` : 'carregando…'}</span></div>
            <div className="list-group">
                {lista && visiveis.length === 0 && <div className="list-group-item text-muted small py-3">Nenhuma lavagem em andamento.</div>}
                {visiveis.map((l) => (
                    <div key={l.id} className="list-group-item py-2">
                        <button type="button" className="btn btn-link text-start text-body p-0 w-100 text-decoration-none" onClick={() => { setEntregaId(l.id); setTela('entrega') }}>
                            <div className="d-flex justify-content-between align-items-baseline">
                                <span><strong className="fs-5">{l.placa}</strong>{l.modelo && <span className="small"> · {l.modelo}</span>}</span>
                                <span className={`selo ${l.pronta ? 'selo-ok' : 'selo-aviso'}`}>{l.etapa_nome}</span>
                            </div>
                            <div className="small text-muted">Nº {l.numero} · {l.desde?.slice(11)} · {l.servicos.join(' + ')}{l.pago ? ' · pago' : ''}{l.cliente ? ` · ${l.cliente.nome}` : ''}</div>
                        </button>
                        {l.proxima && <button type="button" className="btn btn-outline-primary w-100 mt-2" disabled={avancando === l.id} onClick={() => avancar(l)}>→ {l.proxima.nome}</button>}
                        {!l.proxima && <button type="button" className="btn btn-primary w-100 mt-2" onClick={() => { setEntregaId(l.id); setTela('entrega') }}>Entregar</button>}
                    </div>
                ))}
            </div>
            {lendo && <LeitorQr onLido={lido} onFechar={() => setLendo(false)} />}
        </div>
    )
}
