import { Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import BuscaCliente from '../../Components/BuscaCliente'

const chave = (sid) => (sid === null || sid === undefined ? 'estadia' : String(sid))

/** Convênio: para cada serviço marcado (e a estadia), quanto o convênio paga; e quando fecha. */
export default function ConvenioForm({ convenio, opcoes, modos, fechamentos, dias_semana: diasSemana }) {
    const novo = !convenio
    const [cliente, setCliente] = useState(convenio?.cliente || null)
    const inicial = {}
    ;(convenio?.regras || []).forEach((r) => {
        inicial[chave(r.servico_id)] = {
            modo: r.modo, limite_valor: r.limite_valor ?? '',
            horas: r.limite_min ? Math.floor(r.limite_min / 60) : 1, minutos: r.limite_min ? r.limite_min % 60 : 0,
        }
    })
    const { data, setData, post, put, transform, processing, errors } = useForm({
        cliente_id: convenio?.cliente_id || '', regras: inicial,
        fechamento: convenio?.fechamento || 'mensalidade', fechamento_dias: convenio?.fechamento_dias || 7, fechamento_semana: convenio?.fechamento_semana || 1,
        observacoes: convenio?.observacoes || '', ativo: convenio ? convenio.ativo : true,
    })

    const marcar = (o, sim) => {
        const r = { ...data.regras }
        if (sim) r[chave(o.servico_id)] = { modo: 'integral', limite_valor: '', horas: 1, minutos: 0 }
        else delete r[chave(o.servico_id)]
        setData('regras', r)
    }
    const mudar = (o, campo, valor) => setData('regras', { ...data.regras, [chave(o.servico_id)]: { ...data.regras[chave(o.servico_id)], [campo]: valor } })

    function salvar(e) {
        e.preventDefault()
        transform((d) => ({
            ...d,
            regras: opcoes.filter((o) => d.regras[chave(o.servico_id)]).map((o) => {
                const r = d.regras[chave(o.servico_id)]
                return { servico_id: o.servico_id, modo: r.modo, limite_valor: r.modo === 'parcial' ? r.limite_valor : null, limite_min: r.modo === 'tempo' ? Number(r.horas || 0) * 60 + Number(r.minutos || 0) : null }
            }),
        }))
        novo ? post('/convenios') : put(`/convenios/${convenio.id}`)
    }
    // erro de uma regra: o índice é o da lista enviada (só as marcadas, na ordem das opções)
    const marcadas = opcoes.filter((o) => data.regras[chave(o.servico_id)])
    const erroDe = (o) => {
        const i = marcadas.indexOf(o)
        return i < 0 ? null : (errors[`regras.${i}.limite_valor`] || errors[`regras.${i}.limite_min`] || errors[`regras.${i}.modo`] || errors[`regras.${i}.servico_id`])
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo convênio' : 'Editar convênio'} />
            {convenio?.reconfigurar && (
                <div className="alert alert-warning">Este convênio estava no modo "desconto para quem usa", que saiu. Marque os serviços e escolha como o convênio paga cada um.</div>
            )}
            {convenio && !convenio.regras_no_erp && !convenio.reconfigurar && (
                <div className="alert aviso-neutro small">As regras abaixo vieram do sistema atual. Ao salvar aqui, passam a ser do ERP e a importação deixa de mudá-las.</div>
            )}
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <label className="form-label fw-semibold">Conveniado (cliente que paga)</label>
                    {cliente
                        ? <div className="d-flex align-items-center gap-2 mb-3"><strong>{cliente.nome}</strong>{novo && <button type="button" className="btn btn-link btn-sm" onClick={() => { setCliente(null); setData('cliente_id', '') }}>trocar</button>}</div>
                        : <div className="mb-3"><BuscaCliente placeholder="Buscar o cliente conveniado" onEscolher={(c) => { setCliente(c); setData('cliente_id', c.id) }} /></div>}
                    {errors.cliente_id && <div className="text-danger small mb-3" style={{ marginTop: -8 }}>{errors.cliente_id}</div>}

                    <fieldset className="mb-4">
                        <legend className="fs-6 fw-semibold mb-1">O que o convênio paga</legend>
                        <div className="form-text mt-0 mb-2">Serviço não marcado: quem usou paga o preço normal. A parte de quem usou é paga na hora.</div>
                        {errors.regras && <div className="text-danger small mb-2">{errors.regras}</div>}
                        <div className="list-group">
                            {opcoes.map((o) => {
                                const r = data.regras[chave(o.servico_id)]
                                const erro = erroDe(o)
                                return (
                                    <div key={chave(o.servico_id)} className="list-group-item">
                                        <div className="row g-2 align-items-center">
                                            <div className="col-md-4">
                                                <div className="form-check mb-0">
                                                    <input className="form-check-input" type="checkbox" id={`s-${chave(o.servico_id)}`} checked={!!r} onChange={(e) => marcar(o, e.target.checked)} />
                                                    <label className="form-check-label" htmlFor={`s-${chave(o.servico_id)}`}>
                                                        <span className={o.estadia ? 'fw-semibold' : undefined}>{o.nome}</span>{o.adicional && <span className="small text-muted"> (adicional)</span>}
                                                    </label>
                                                </div>
                                            </div>
                                            {r && (
                                                <>
                                                    <div className="col-md-4">
                                                        <select className="form-select form-select-sm" aria-label={`Como o convênio paga ${o.nome}`} value={r.modo} onChange={(e) => mudar(o, 'modo', e.target.value)}>
                                                            {Object.entries(modos).filter(([k]) => k !== 'tempo' || o.estadia).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                                        </select>
                                                    </div>
                                                    <div className="col-md-4">
                                                        {r.modo === 'parcial' && (
                                                            <CampoValor opcional semMargem rotulo="Convênio paga até" value={r.limite_valor} onChange={(v) => mudar(o, 'limite_valor', v)} />
                                                        )}
                                                        {r.modo === 'tempo' && (
                                                            <div className="d-flex gap-1">
                                                                <Campo rotulo="Horas" type="number" min="0" value={r.horas} onChange={(e) => mudar(o, 'horas', e.target.value)} />
                                                                <Campo rotulo="Min" type="number" min="0" max="59" value={r.minutos} onChange={(e) => mudar(o, 'minutos', e.target.value)} />
                                                            </div>
                                                        )}
                                                        {r.modo === 'integral' && <span className="small text-muted">O convênio paga tudo.</span>}
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                        {r?.modo === 'parcial' && <div className="form-text">O que passar do limite, quem usou paga. Ex.: até R$ 10 e o serviço deu R$ 15: convênio R$ 10, quem usou R$ 5.</div>}
                                        {r?.modo === 'tempo' && <div className="form-text">O convênio paga a estadia até esse tempo; o excedente, quem usou paga.</div>}
                                        {erro && <div className="text-danger small">{erro}</div>}
                                    </div>
                                )
                            })}
                        </div>
                    </fieldset>

                    <fieldset className="mb-3">
                        <legend className="fs-6 fw-semibold mb-1">Fechamento</legend>
                        <div className="form-text mt-0 mb-2">No dia, o Início avisa e você gera a cobrança do convênio com um botão.</div>
                        <div className="row g-2">
                            <div className="col-sm-6">
                                <Campo as="select" rotulo="Quando" value={data.fechamento} onChange={(e) => setData('fechamento', e.target.value)} erro={errors.fechamento}>
                                    {Object.entries(fechamentos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </Campo>
                            </div>
                            <div className="col-sm-6">
                                {data.fechamento === 'dias' && <Campo rotulo="A cada quantos dias" type="number" min="1" max="90" value={data.fechamento_dias} onChange={(e) => setData('fechamento_dias', e.target.value)} erro={errors.fechamento_dias} />}
                                {data.fechamento === 'semana' && (
                                    <Campo as="select" rotulo="Dia da semana" value={data.fechamento_semana} onChange={(e) => setData('fechamento_semana', Number(e.target.value))} erro={errors.fechamento_semana}>
                                        {Object.entries(diasSemana).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                    </Campo>
                                )}
                                {data.fechamento === 'mensalidade' && <div className="small text-muted pt-2">No dia de vencimento do conveniado, junto com o resto da conta dele.</div>}
                            </div>
                        </div>
                    </fieldset>

                    <Campo rotulo="Observações" value={data.observacoes} maxLength={255} onChange={(e) => setData('observacoes', e.target.value)} />
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativo" checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativo">Ativo</label>
                    </div>
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href="/convenios" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
