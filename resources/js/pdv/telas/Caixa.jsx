import { useEffect, useState } from 'react'
import { api } from '../api'
import { usePin } from '../Pin'
import { CampoDinheiro, Formas, PerguntaImprimir, Previa, moeda } from '../comum'
import { montarFechamento, montarReciboConta } from '../impressao/ticket'
import { preferencia } from '../impressao/preferencia'

/** topo de cada etapa: voltar + título */
function Topo({ titulo, onVoltar, voltarRotulo = 'Voltar', extra = null }) {
    return (
        <div className="pdv-resumo-fixo">
            <button type="button" className="btn btn-link px-0 mb-1" onClick={onVoltar}>‹ {voltarRotulo}</button>
            <div className="d-flex justify-content-between align-items-baseline gap-2"><h1 className="h5 mb-0">{titulo}</h1>{extra}</div>
        </div>
    )
}

const atalhos = (total) => [...new Set([Math.ceil(total / 10) * 10, Math.ceil(total / 50) * 50, 100, 200].filter((v) => v > total))].slice(0, 3)

/** dinheiro: quanto o cliente deu (máscara + atalhos) */
function Recebido({ total, value, onChange, onConfirmar, enviando, erro }) {
    return (
        <div className="mt-3">
            <label className="form-label fw-semibold mb-1" htmlFor="recebido">Quanto o cliente deu?</label>
            <CampoDinheiro id="recebido" autoFocus value={value} onChange={onChange} />
            <div className="pdv-opcoes mt-2">
                <button type="button" className="btn btn-outline-secondary" onClick={() => onChange(total)}>Valor exato</button>
                {atalhos(total).map((v) => <button key={v} type="button" className="btn btn-outline-secondary" onClick={() => onChange(v)}>{moeda(v)}</button>)}
            </div>
            {value > 0 && value + 0.001 < total && <div className="text-danger small mt-2">Menor que {moeda(total)}.</div>}
            {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
            <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando || value + 0.001 < total} onClick={onConfirmar}>
                {enviando ? 'Registrando…' : value >= total ? `Confirmar ${moeda(value)} recebidos` : 'Confirmar valor recebido'}
            </button>
        </div>
    )
}

/** receber conta de cliente no balcão: cliente → contas → forma → (dinheiro) → PIN → recibo */
function ReceberConta({ eu, opcoes, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [etapa, setEtapa] = useState('cliente')
    const [busca, setBusca] = useState('')
    const [achados, setAchados] = useState([])
    const [dados, setDados] = useState(null) // { cliente, contas }
    const [marcadas, setMarcadas] = useState([])
    const [forma, setForma] = useState(null)
    const [recebido, setRecebido] = useState(0)
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [feito, setFeito] = useState(null)

    useEffect(() => {
        if (busca.trim().length < 2) { setAchados([]); return undefined }
        const t = setTimeout(() => api('GET', `/clientes?q=${encodeURIComponent(busca.trim())}`).then(setAchados).catch(() => {}), 250)
        return () => clearTimeout(t)
    }, [busca])

    const escolher = (c) => {
        setErro(null)
        api('GET', `/clientes/${c.id}/contas`).then((j) => {
            setDados(j)
            setMarcadas(j.contas.filter((o) => !o.credito).map((o) => o.id))
            setEtapa('contas')
        }).catch((e) => setErro(e.message))
    }
    const contas = dados?.contas || []
    const creditos = contas.filter((o) => o.credito)
    const total = Math.round((contas.filter((o) => marcadas.includes(o.id)).reduce((s, o) => s + o.valor, 0) + creditos.reduce((s, o) => s + o.valor, 0)) * 100) / 100
    const formaObj = opcoes.formas.find((f) => f.id === forma)

    const receber = (valorRecebido = null) => pedirPin('PIN para receber').then(({ pin }) => {
        setEnviando(true)
        setErro(null)
        api('POST', `/clientes/${dados.cliente.id}/receber`, { pin, ordens: marcadas, forma_pagamento_id: forma, valor_recebido: valorRecebido })
            .then(setFeito).catch((e) => setErro(e.message)).finally(() => setEnviando(false))
    }).catch(() => {})

    if (feito) {
        const r = feito.recibo
        const linhas = [eu.empresa.nome, '-', '#RECIBO', ['Cliente', r.cliente], ['Data', r.data], '-', ...r.itens.map((i) => [i.descricao, moeda(i.valor)]), '-', [`Pago (${r.forma})`, moeda(r.total)], ...(r.observacao ? [r.observacao] : [])]
        return (
            <PerguntaImprimir titulo={feito.troco > 0 ? 'Entregue o troco. Imprimir o recibo?' : `${feito.mensagem} Imprimir o recibo?`}
                destaque={feito.troco > 0 && <div className="pdv-troco-final"><div>Troco <strong>{moeda(feito.troco)}</strong></div></div>}
                montar={(op) => montarReciboConta(r, eu, op)} previa={<Previa linhas={linhas} colunas={preferencia.get().colunas} />}
                onFim={() => onFeito(feito.mensagem)} />
        )
    }

    const voltar = () => { setErro(null); setEtapa({ contas: 'cliente', forma: 'contas', dinheiro: 'forma' }[etapa]) }
    return (
        <div className="pb-5">
            <Topo titulo={dados && etapa !== 'cliente' ? dados.cliente.nome : 'Receber conta de cliente'} onVoltar={etapa === 'cliente' ? onVoltar : voltar} voltarRotulo={etapa === 'cliente' ? 'Caixa' : 'Voltar'}
                extra={etapa !== 'cliente' && etapa !== 'contas' && <span className="h4 mb-0">{moeda(total)}</span>} />

            {etapa === 'cliente' && (
                <div className="mt-2">
                    <label className="form-label fw-semibold mb-1" htmlFor="busca">Cliente</label>
                    <input id="busca" className="form-control form-control-lg" autoFocus autoComplete="off" placeholder="Nome, código ou placa" value={busca} onChange={(e) => setBusca(e.target.value)} />
                    <div className="list-group mt-2">{achados.map((a) => <button key={a.id} type="button" className="list-group-item list-group-item-action py-3" onClick={() => escolher(a)}>{a.nome}</button>)}</div>
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                </div>
            )}

            {etapa === 'contas' && (
                <div className="mt-3">
                    {contas.filter((o) => !o.credito).length === 0 ? <div className="alert aviso-neutro">Nenhuma conta em aberto.</div> : (
                        <>
                            <div className="fw-semibold mb-2">O que está pagando?</div>
                            <div className="list-group">
                                {contas.filter((o) => !o.credito).map((o) => (
                                    <label key={o.id} className="list-group-item d-flex gap-3 align-items-center py-3">
                                        <input className="form-check-input m-0" type="checkbox" checked={marcadas.includes(o.id)} onChange={(e) => setMarcadas(e.target.checked ? [...marcadas, o.id] : marcadas.filter((x) => x !== o.id))} />
                                        <span className="flex-grow-1">{o.descricao}{o.placa && <span className="text-muted"> · {o.placa}</span>}
                                            <span className={`d-block small ${o.vencida ? 'text-danger' : 'text-muted'}`}>{o.vencimento ? `vence ${o.vencimento}` : ''}{o.vencida ? ' · vencida' : ''}</span></span>
                                        <strong className="text-nowrap">{moeda(o.valor)}</strong>
                                    </label>
                                ))}
                                {creditos.map((o) => <div key={o.id} className="list-group-item d-flex justify-content-between small"><span>{o.descricao} (abate)</span><span>{moeda(o.valor)}</span></div>)}
                            </div>
                            <div className="d-flex justify-content-between fs-4 fw-bold mt-3"><span>Total</span><span>{moeda(total)}</span></div>
                            <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={!marcadas.length || total <= 0} onClick={() => { setForma(null); setEtapa('forma') }}>Receber {moeda(total)}</button>
                        </>
                    )}
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
                        <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando} onClick={() => receber()}>
                            {enviando ? 'Registrando…' : `Recebi ${moeda(total)} no ${formaObj.nome.replace(/^Cartão de /i, '').toLowerCase()}`}
                        </button>
                    )}
                </div>
            )}

            {etapa === 'dinheiro' && <Recebido total={total} value={recebido} onChange={setRecebido} onConfirmar={() => receber(recebido)} enviando={enviando} erro={erro} />}
        </div>
    )
}

/** retirada (sangria ou pagamento) e suprimento; tudo com o PIN do gerente */
function Movimento({ opcoes, suprimento = false, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [etapa, setEtapa] = useState(suprimento ? 'valor' : 'tipo')
    const [tipo, setTipo] = useState(suprimento ? 'suprimento' : null) // sangria | pagamento | retirada | suprimento
    const [destino, setDestino] = useState(null)
    const [banco, setBanco] = useState(null)
    const [categoria, setCategoria] = useState(null)
    const [buscaCat, setBuscaCat] = useState('')
    const [valor, setValor] = useState(0)
    const [texto, setTexto] = useState('')
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)

    const motivoObrigatorio = tipo === 'sangria' || tipo === 'suprimento'
    const titulo = { suprimento: 'Suprimento', sangria: 'Sangria', pagamento: 'Pagamento', retirada: 'Retirada do sócio' }[tipo] || 'Retirada'
    const enviar = () => pedirPin(`PIN de gerente: ${titulo.toLowerCase()}`).then(({ pin }) => {
        setEnviando(true)
        setErro(null)
        api('POST', '/caixa/movimentos', {
            pin, tipo, valor, destino: tipo === 'sangria' ? destino : null, conta_financeira_id: destino === 'banco' ? banco : null,
            plano_conta_id: tipo === 'pagamento' ? categoria?.id : null, motivo: tipo === 'pagamento' ? null : texto, descricao: tipo === 'pagamento' ? texto : null,
        }).then((j) => onFeito(j.mensagem)).catch((e) => setErro(e.message)).finally(() => setEnviando(false))
    }).catch(() => {})

    const voltar = () => {
        setErro(null)
        if (etapa === 'valor' && suprimento) onVoltar()
        else if (etapa === 'valor') setEtapa(tipo === 'pagamento' ? 'categoria' : destino === 'banco' ? 'banco' : 'destino')
        else if (etapa === 'banco') setEtapa('destino')
        else setEtapa('tipo')
    }
    const categorias = opcoes.categorias.filter((c) => !buscaCat.trim() || c.nome.toLowerCase().includes(buscaCat.trim().toLowerCase()))
    const destinoRotulo = tipo === 'retirada' ? null : destino === 'banco' ? opcoes.bancos.find((b) => b.id === banco)?.nome : destino === 'cofre' ? 'Cofre' : categoria?.nome

    return (
        <div className="pb-5">
            <Topo titulo={titulo} onVoltar={etapa === 'tipo' || (suprimento && etapa === 'valor') ? onVoltar : voltar} voltarRotulo={etapa === 'tipo' || (suprimento && etapa === 'valor') ? 'Caixa' : 'Voltar'}
                extra={destinoRotulo && etapa === 'valor' && <span className="small text-muted">{destinoRotulo}</span>} />

            {etapa === 'tipo' && (
                <div className="d-grid gap-2 mt-3">
                    <button type="button" className="btn btn-primary btn-lg" onClick={() => { setTipo('sangria'); setEtapa('destino') }}>Sangria<span className="d-block small">tirar dinheiro da gaveta</span></button>
                    <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setTipo('pagamento'); setEtapa('categoria') }}>Pagamento<span className="d-block small">pagar uma despesa com o dinheiro do caixa</span></button>
                </div>
            )}

            {etapa === 'destino' && (
                <div className="mt-3">
                    <div className="fw-semibold mb-2">Para onde vai o dinheiro?</div>
                    <div className="d-grid gap-2">
                        <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setTipo('sangria'); setDestino('cofre'); setEtapa('valor') }}>Cofre</button>
                        {opcoes.bancos.length > 0 && <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setTipo('sangria'); setDestino('banco'); if (opcoes.bancos.length === 1) { setBanco(opcoes.bancos[0].id); setEtapa('valor') } else setEtapa('banco') }}>Depósito no banco</button>}
                        <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setTipo('retirada'); setDestino(null); setEtapa('valor') }}>Retirada do sócio</button>
                    </div>
                </div>
            )}

            {etapa === 'banco' && (
                <div className="d-grid gap-2 mt-3">
                    {opcoes.bancos.map((b) => <button key={b.id} type="button" className="btn btn-outline-primary btn-lg" onClick={() => { setBanco(b.id); setEtapa('valor') }}>{b.nome}</button>)}
                </div>
            )}

            {etapa === 'categoria' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="cat">Destino do pagamento</label>
                    <input id="cat" className="form-control form-control-lg mb-2" placeholder="Buscar (ex.: alimentação)" value={buscaCat} onChange={(e) => setBuscaCat(e.target.value)} autoComplete="off" />
                    <div className="list-group">
                        {categorias.slice(0, buscaCat ? 30 : 8).map((c) => (
                            <button key={c.id} type="button" className="list-group-item list-group-item-action py-3" onClick={() => { setCategoria(c); setEtapa('valor') }}>{c.nome}</button>
                        ))}
                    </div>
                    {!buscaCat && opcoes.categorias.length > 8 && <div className="small text-muted mt-2">As mais usadas primeiro. Busque para ver as outras.</div>}
                </div>
            )}

            {etapa === 'valor' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="valor">Valor</label>
                    <CampoDinheiro id="valor" autoFocus value={valor} onChange={setValor} />
                    <label className="form-label mt-3 mb-1" htmlFor="texto">{tipo === 'pagamento' ? 'Descrição (opcional)' : motivoObrigatorio ? 'Motivo' : 'Observação (opcional)'}</label>
                    <input id="texto" className="form-control form-control-lg" maxLength={tipo === 'pagamento' ? 120 : 200} value={texto} onChange={(e) => setTexto(e.target.value)}
                        placeholder={tipo === 'pagamento' ? 'Ex.: almoço da semana' : tipo === 'suprimento' ? 'Ex.: troco do cofre' : tipo === 'sangria' ? 'Ex.: excesso de dinheiro' : ''} />
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" disabled={enviando || valor <= 0 || (motivoObrigatorio && !texto.trim())} onClick={enviar}>
                        {enviando ? 'Registrando…' : `Registrar ${titulo.toLowerCase()} de ${moeda(valor)}`}
                    </button>
                    {tipo === 'pagamento' && <div className="small text-muted mt-2">Entra em Contas a pagar como despesa paga em dinheiro.</div>}
                    {tipo === 'retirada' && <div className="small text-muted mt-2">Não é despesa: aparece no Resultado do mês abaixo do lucro.</div>}
                </div>
            )}
        </div>
    )
}

/** fechamento (gerente): resumo → contado → troco que fica → imprimir */
function Fechar({ eu, onVoltar, onFeito }) {
    const { pedirPin } = usePin()
    const [pin, setPin] = useState(null)
    const [r, setR] = useState(null)
    const [etapa, setEtapa] = useState('resumo') // resumo | contado | troco
    const [contado, setContado] = useState(0)
    const [troco, setTroco] = useState(0)
    const [obs, setObs] = useState('')
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [feito, setFeito] = useState(null)

    useEffect(() => {
        pedirPin('PIN de gerente: fechar o caixa').then(({ pin: p }) => {
            setPin(p)
            api('POST', '/caixa/resumo', { pin: p }).then(setR).catch((e) => setErro(e.message))
        }).catch(() => onVoltar())
    }, []) // eslint-disable-line react-hooks/exhaustive-deps

    if (feito) {
        const f = feito
        const linhas = [eu.empresa.nome, '-', '#FECHAMENTO', ...f.por_servico.map((l) => [`${l.servico} (${l.qtd})`, moeda(l.total)]), ['Total recebido', moeda(f.total_recebido)], '-',
            ...f.por_forma.map((l) => [l.forma, moeda(l.total)]), '-', ['Deveria ter', moeda(f.dinheiro_esperado)], ['Contado', moeda(f.dinheiro_contado)], ['Diferença', moeda(f.diferenca)], ['Fica de troco', moeda(f.troco_deixado)]]
        return (
            <PerguntaImprimir titulo="Caixa fechado. Imprimir o resumo?" montar={(op) => montarFechamento(f, eu, op)} previa={<Previa linhas={linhas} colunas={preferencia.get().colunas} />}
                onFim={() => onFeito(`Caixa fechado. Ficaram ${moeda(f.troco_deixado)} de troco para o próximo.`)} />
        )
    }
    if (!r) {
        return <div><Topo titulo="Fechar caixa" onVoltar={onVoltar} voltarRotulo="Caixa" />{erro ? <div className="alert alert-danger mt-3">{erro}</div> : <div className="text-muted mt-3">Carregando…</div>}</div>
    }
    const dif = Math.round((contado - r.dinheiro_esperado) * 100) / 100
    const fechar = () => {
        setEnviando(true)
        setErro(null)
        api('POST', '/caixa/fechar', { pin, dinheiro_contado: contado, troco_deixado: troco, observacao: obs })
            .then((j) => setFeito(j.resumo)).catch((e) => setErro(e.message)).finally(() => setEnviando(false))
    }

    return (
        <div className="pb-5">
            <Topo titulo="Fechar caixa" onVoltar={etapa === 'resumo' ? onVoltar : () => { setErro(null); setEtapa(etapa === 'troco' ? 'contado' : 'resumo') }} voltarRotulo={etapa === 'resumo' ? 'Caixa' : 'Voltar'}
                extra={<span className="small text-muted">desde {r.aberto_em.slice(8, 10)}/{r.aberto_em.slice(5, 7)} {r.aberto_em.slice(11)}</span>} />

            {etapa === 'resumo' && (
                <div className="mt-2">
                    <table className="table table-sm mb-3">
                        <thead><tr><th>Por serviço</th><th className="text-end">Qtd</th><th className="text-end">Total</th></tr></thead>
                        <tbody>{r.por_servico.map((l) => <tr key={l.servico}><td>{l.servico}</td><td className="text-end">{l.qtd}</td><td className="text-end">{moeda(l.total)}</td></tr>)}</tbody>
                        <tfoot><tr className="fw-bold"><td colSpan="2">Total recebido</td><td className="text-end">{moeda(r.total_recebido)}</td></tr></tfoot>
                    </table>
                    <table className="table table-sm mb-3">
                        <thead><tr><th>Por forma</th><th className="text-end">Total</th></tr></thead>
                        <tbody>
                            {r.por_forma.length === 0 && <tr><td colSpan="2" className="text-muted">Nada recebido.</td></tr>}
                            {r.por_forma.map((l) => <tr key={l.forma}><td>{l.forma}</td><td className="text-end">{moeda(l.total)}</td></tr>)}
                        </tbody>
                    </table>
                    <table className="table table-sm mb-3">
                        <thead><tr><th colSpan="2">Dinheiro na gaveta</th></tr></thead>
                        <tbody>
                            <tr><td>Troco inicial</td><td className="text-end">{moeda(r.troco_inicial)}</td></tr>
                            <tr><td>+ Recebido em dinheiro</td><td className="text-end">{moeda(r.dinheiro_recebido)}</td></tr>
                            {r.suprimentos > 0 && <tr><td>+ Suprimentos</td><td className="text-end">{moeda(r.suprimentos)}</td></tr>}
                            {r.movimentos.filter((m) => m.sinal < 0).map((m) => <tr key={m.id}><td>− {m.rotulo}</td><td className="text-end">{moeda(-m.valor)}</td></tr>)}
                            <tr className="fw-bold fs-5"><td>Deveria ter</td><td className="text-end">{moeda(r.dinheiro_esperado)}</td></tr>
                        </tbody>
                    </table>
                    <button type="button" className="btn btn-primary btn-lg w-100" onClick={() => { setContado(0); setEtapa('contado') }}>Contar o dinheiro</button>
                </div>
            )}

            {etapa === 'contado' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="contado">Quanto tem na gaveta?</label>
                    <CampoDinheiro id="contado" autoFocus value={contado} onChange={setContado} />
                    <div className="border rounded p-3 mt-3">
                        <div className="d-flex justify-content-between"><span>Deveria ter</span><span>{moeda(r.dinheiro_esperado)}</span></div>
                        <div className={`d-flex justify-content-between fw-bold fs-5 ${Math.abs(dif) < 0.005 ? 'text-success' : dif > 0 ? 'text-warning-emphasis' : 'text-danger'}`}>
                            <span>{Math.abs(dif) < 0.005 ? 'Certo' : dif > 0 ? 'Sobrando' : 'Faltando'}</span><span>{moeda(Math.abs(dif))}</span>
                        </div>
                    </div>
                    <button type="button" className="btn btn-primary btn-lg w-100 mt-3" onClick={() => { setTroco(0); setEtapa('troco') }}>Continuar</button>
                </div>
            )}

            {etapa === 'troco' && (
                <div className="mt-3">
                    <label className="form-label fw-semibold mb-1" htmlFor="troco">Quanto fica de troco para amanhã?</label>
                    <CampoDinheiro id="troco" autoFocus value={troco} onChange={setTroco} />
                    {troco > contado + 0.001 && <div className="text-danger small mt-2">No máximo o que foi contado ({moeda(contado)}).</div>}
                    {troco <= contado && <div className="small text-muted mt-2">O resto ({moeda(Math.max(0, contado - troco))}) sai da gaveta com o gerente.</div>}
                    <label className="form-label mt-3 mb-1" htmlFor="obs">Observação (opcional)</label>
                    <input id="obs" className="form-control form-control-lg" maxLength={255} value={obs} onChange={(e) => setObs(e.target.value)} placeholder={Math.abs(dif) >= 0.005 ? 'Ex.: motivo da diferença' : ''} />
                    {erro && <div className="alert alert-danger py-2 small mt-3">{erro}</div>}
                    <button type="button" className="btn btn-danger btn-lg w-100 mt-3" disabled={enviando || troco > contado + 0.001} onClick={fechar}>{enviando ? 'Fechando…' : 'Fechar o caixa'}</button>
                </div>
            )}
        </div>
    )
}

/** PDV › Caixa (8.3): receber conta de cliente, retirada, suprimento e fechamento */
export default function Caixa({ eu, onVoltar }) {
    const [estado, setEstado] = useState(null)
    const [opcoes, setOpcoes] = useState(null)
    const [tela, setTela] = useState('menu')
    const [aviso, setAviso] = useState(null)
    const [erro, setErro] = useState(null)

    const carregar = () => api('GET', '/caixa').then(setEstado).catch((e) => setErro(e.message))
    useEffect(() => {
        carregar()
        api('GET', '/caixa/opcoes').then(setOpcoes).catch((e) => setErro(e.message))
    }, [])
    const feito = (msg) => { setAviso(msg); setTela('menu'); carregar(); window.scrollTo(0, 0) }
    const menu = () => setTela('menu')

    if (!opcoes) return <div><button type="button" className="btn btn-link px-0" onClick={onVoltar}>‹ Início</button>{erro ? <div className="alert alert-danger mt-2">{erro}</div> : <div className="text-muted mt-2">Carregando…</div>}</div>
    if (tela === 'receber') return <ReceberConta eu={eu} opcoes={opcoes} onVoltar={menu} onFeito={feito} />
    if (tela === 'retirada') return <Movimento opcoes={opcoes} onVoltar={menu} onFeito={feito} />
    if (tela === 'suprimento') return <Movimento opcoes={opcoes} suprimento onVoltar={menu} onFeito={feito} />
    if (tela === 'fechar') return <Fechar eu={eu} onVoltar={menu} onFeito={feito} />

    return (
        <div>
            <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Início</button>
            {aviso && <div className="alert alert-success py-2" onClick={() => setAviso(null)}>{aviso}</div>}
            <div className="d-flex justify-content-between align-items-baseline mb-3">
                <h1 className="h5 mb-0">Caixa</h1>
                {estado && <span className="small text-muted">aberto desde {estado.aberto_em}</span>}
            </div>
            <div className="pdv-botoes-grandes mb-3">
                <button type="button" className="pdv-grande" onClick={() => setTela('receber')}><span className="pdv-grande-titulo">Receber</span><span className="small">conta de cliente</span></button>
                <button type="button" className="pdv-grande" onClick={() => setTela('retirada')}><span className="pdv-grande-titulo">Retirada</span><span className="small">sangria ou pagamento</span></button>
            </div>
            <div className="list-group">
                <button type="button" className="list-group-item list-group-item-action py-3" onClick={() => setTela('suprimento')}>Suprimento (pôr dinheiro na gaveta)</button>
                <button type="button" className="list-group-item list-group-item-action py-3 text-danger" onClick={() => setTela('fechar')}>Fechar o caixa</button>
            </div>
            <p className="small text-muted mt-3">Retirada, suprimento e fechamento pedem o PIN de um gerente ou administrador.</p>
        </div>
    )
}
