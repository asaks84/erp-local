import { Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import BuscaClienteCampo from '../../Components/BuscaClienteCampo'
import { competencia } from '../../Components/Dinheiro'
import { data as fData, dataHora, moeda } from '../../lib/formato'
import { usePode } from '../../lib/permissoes'

const centavos = (v) => Math.round(Number(v || 0) * 100)

function Entrada({ e, linha, cliente, servicos, mudar, carregando }) {
    const pode = usePode()
    if (e.processado) {
        return (
            <div className="card mb-2 opacity-75">
                <div className="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
                    <strong>{moeda(e.valor)}</strong><span>{fData(e.data)}</span><span>{e.nome}</span>
                    <span className="selo selo-ok ms-auto">Já baixado{e.processado.cliente ? `: ${e.processado.cliente}` : ''} em {dataHora(e.processado.quando)}</span>
                </div>
            </div>
        )
    }
    const contas = cliente ? cliente.ordens.filter((o) => o.tipo !== 'credito') : []
    const creditos = cliente ? cliente.ordens.filter((o) => o.tipo === 'credito').reduce((s, o) => s + centavos(o.valor), 0) : 0
    const marcadas = linha.modo === 'parcial' ? contas.filter((o) => linha.ordens.includes(o.id)) : contas
    const total = (marcadas.reduce((s, o) => s + centavos(o.valor), 0) - creditos) / 100
    const dif = (centavos(e.valor) - centavos(total)) / 100

    return (
        <div className={`card mb-2 ${linha.usar ? 'border-primary' : ''}`}>
            <div className="card-body">
                <div className="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input type="checkbox" className="form-check-input mt-0" checked={linha.usar} disabled={!linha.cliente_id} onChange={(ev) => mudar({ usar: ev.target.checked })} aria-label="Baixar esta entrada" />
                    <strong className="fs-5">{moeda(e.valor)}</strong>
                    <span>{fData(e.data)}</span>
                    <span className="fw-semibold">{e.nome || e.tipo}</span>
                    {e.documento && <span className="small text-muted">doc. {e.documento.length === 6 ? `•••.${e.documento.slice(0, 3)}.${e.documento.slice(3)}-••` : e.documento}</span>}
                    {e.certeza && <span className={`selo ms-auto ${e.certeza === 'alta' ? 'selo-ok' : 'selo-aviso'}`}>{e.motivo}</span>}
                    {!e.certeza && <span className="selo ms-auto">{e.motivo}</span>}
                </div>
                <div className="row g-2">
                    <div className="col-md-6">
                        <BuscaClienteCampo valor={cliente ? { id: cliente.id, nome: cliente.nome } : null} onEscolher={(c) => mudar({ cliente_id: c?.id ?? null, usar: !!c, modo: 'tudo', ordens: [] })} />
                        {cliente && (
                            <div className="form-check mt-1">
                                <input className="form-check-input" type="checkbox" id={`lembrar-${e.indice}`} checked={!!linha.lembrar} onChange={(ev) => mudar({ lembrar: ev.target.checked })} />
                                <label className="form-check-label small" htmlFor={`lembrar-${e.indice}`}>Lembrar: este pagador paga por {cliente.nome}</label>
                            </div>
                        )}
                    </div>
                    <div className="col-md-6">
                        {carregando && <p className="text-muted small">Carregando contas…</p>}
                        {cliente && !carregando && (
                            <>
                                {contas.length === 0 ? <p className="small text-muted mb-1">Sem contas em aberto.</p> : (
                                    <>
                                        <div className="btn-group btn-group-sm mb-1">
                                            <button type="button" className={`btn ${linha.modo !== 'parcial' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => mudar({ modo: 'tudo' })}>Todas ({moeda((contas.reduce((s, o) => s + centavos(o.valor), 0) - creditos) / 100)})</button>
                                            <button type="button" className={`btn ${linha.modo === 'parcial' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => mudar({ modo: 'parcial', ordens: contas.map((o) => o.id) })}>Escolher</button>
                                        </div>
                                        {linha.modo === 'parcial' && contas.map((o) => (
                                            <div key={o.id} className="form-check small">
                                                <input className="form-check-input" type="checkbox" id={`o-${e.indice}-${o.id}`} checked={linha.ordens.includes(o.id)}
                                                    onChange={() => mudar({ ordens: linha.ordens.includes(o.id) ? linha.ordens.filter((x) => x !== o.id) : [...linha.ordens, o.id] })} />
                                                <label className="form-check-label" htmlFor={`o-${e.indice}-${o.id}`}>{o.descricao}{o.competencia ? ` ${competencia(o.competencia)}` : ''} · {fData(o.data)} · {moeda(o.valor)}</label>
                                            </div>
                                        ))}
                                    </>
                                )}
                                {dif > 0 && (
                                    <div className="small mt-1">
                                        <span className="fw-semibold">{moeda(dif)} a mais:</span>
                                        {[['credito', 'crédito'], ['servico', 'serviço na hora'], ['nada', 'não lançar']].map(([v, r]) => (
                                            <label key={v} className="ms-2"><input type="radio" className="form-check-input me-1" checked={linha.sobra === v} onChange={() => mudar({ sobra: v })} />{r}</label>
                                        ))}
                                        {linha.sobra === 'servico' && (
                                            <div className="row g-1 mt-1">
                                                <div className="col-6"><select className="form-select form-select-sm" value={linha.servico_id || ''} onChange={(ev) => mudar({ servico_id: ev.target.value })} aria-label="Serviço"><option value="">Serviço</option><option value="estadia">Estadia avulsa</option>{servicos.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}</select></div>
                                                <div className="col-6"><select className="form-select form-select-sm" value={linha.veiculo_id || ''} onChange={(ev) => mudar({ veiculo_id: ev.target.value })} aria-label="Veículo"><option value="">Sem veículo</option>{cliente.veiculos.map((v) => <option key={v.id} value={v.id}>{v.rotulo}</option>)}</select></div>
                                            </div>
                                        )}
                                    </div>
                                )}
                                {dif < 0 && (
                                    <div className="small mt-1">
                                        <span className="fw-semibold">Faltam {moeda(-dif)}:</span>
                                        <label className="ms-2"><input type="radio" className="form-check-input me-1" checked={linha.falta === 'debito'} onChange={() => mudar({ falta: 'debito' })} />débito</label>
                                        {pode('desconto') && <label className="ms-2"><input type="radio" className="form-check-input me-1" checked={linha.falta === 'desconto'} onChange={() => mudar({ falta: 'desconto' })} />desconto</label>}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}

export default function Conferir({ token, arquivo, saidas, entradas, clientes: iniciais, formas, servicos }) {
    const [clientes, setClientes] = useState(iniciais)
    const [carregando, setCarregando] = useState({})
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const form = useForm({
        token,
        forma_pagamento_id: pix?.id ?? '',
        linhas: entradas.filter((e) => !e.processado).map((e) => ({
            indice: e.indice, cliente_id: e.cliente_id, usar: e.certeza === 'alta', modo: 'tudo', ordens: [], sobra: 'credito', falta: 'debito', lembrar: false,
        })),
    })
    const linhaDe = (i) => form.data.linhas.find((l) => l.indice === i)

    function mudar(indice, campos) {
        form.setData('linhas', form.data.linhas.map((l) => (l.indice === indice ? { ...l, ...campos } : l)))
        if (campos.cliente_id && !clientes[campos.cliente_id]) {
            setCarregando((c) => ({ ...c, [indice]: true }))
            fetch(`/extrato/cliente/${campos.cliente_id}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => r.json()).then((c) => setClientes((x) => ({ ...x, [c.id]: c })))
                .finally(() => setCarregando((c) => ({ ...c, [indice]: false })))
        }
    }
    const marcadas = form.data.linhas.filter((l) => l.usar && l.cliente_id)
    const soma = marcadas.reduce((s, l) => s + centavos(entradas.find((e) => e.indice === l.indice).valor), 0) / 100
    const novas = entradas.filter((e) => !e.processado)

    function enviar(ev) {
        ev.preventDefault()
        form.transform((d) => ({ ...d, linhas: d.linhas.filter((l) => l.usar && l.cliente_id) }))
        form.post('/extrato/confirmar')
    }

    return (
        <div className="pagina">
            <TituloPagina titulo="Conferir extrato" descricao={`${arquivo} · ${entradas.length} entrada(s) (${novas.length} nova(s))${saidas ? ` · ${saidas} saída(s) ignorada(s)` : ''}`} />
            <div className="alert aviso-neutro small">Marque só o que tem certeza. Sugestões com selo verde vêm marcadas; as amarelas, confira o cliente. O valor e a data que dão baixa são os do arquivo.</div>
            <form onSubmit={enviar}>
                {entradas.map((e) => (
                    <Entrada key={e.indice} e={e} linha={linhaDe(e.indice) || {}} cliente={linhaDe(e.indice)?.cliente_id ? clientes[linhaDe(e.indice).cliente_id] : null}
                        servicos={servicos} mudar={(c) => mudar(e.indice, c)} carregando={carregando[e.indice]} />
                ))}
                <div className="card mt-3 sticky-bottom">
                    <div className="card-body d-flex flex-wrap gap-3 align-items-center">
                        <div style={{ minWidth: 200 }}>
                            <Campo as="select" rotulo="Forma de pagamento" value={form.data.forma_pagamento_id} onChange={(ev) => form.setData('forma_pagamento_id', ev.target.value)} className="mb-0">
                                {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                            </Campo>
                        </div>
                        <span className="flex-grow-1">{marcadas.length} entrada(s) marcada(s) · <strong>{moeda(soma)}</strong></span>
                        <Link href="/extrato" className="btn btn-outline-secondary">Cancelar</Link>
                        <button type="submit" className="btn btn-primary" disabled={form.processing || marcadas.length === 0}>Dar baixa</button>
                    </div>
                </div>
            </form>
        </div>
    )
}
