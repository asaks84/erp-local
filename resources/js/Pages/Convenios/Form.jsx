import { Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import BuscaCliente from '../../Components/BuscaCliente'

export default function ConvenioForm({ convenio }) {
    const novo = !convenio
    const [cliente, setCliente] = useState(convenio?.cliente || null)
    const { data, setData, post, put, transform, processing, errors } = useForm({
        cliente_id: convenio?.cliente_id || '', modo: convenio?.modo || 'tudo',
        limite_horas: convenio?.limite_min ? Math.floor(convenio.limite_min / 60) : 1, limite_minutos: convenio?.limite_min ? convenio.limite_min % 60 : 0,
        desconto_tipo: convenio?.desconto_tipo || 'pct', desconto_valor: convenio?.desconto_valor ?? '',
        vale_estadia: convenio ? convenio.vale_estadia : true, vale_servicos: convenio ? convenio.vale_servicos : false,
        observacoes: convenio?.observacoes || '', ativo: convenio ? convenio.ativo : true,
    })

    function salvar(e) {
        e.preventDefault()
        transform((d) => ({ ...d, limite_min: Number(d.limite_horas || 0) * 60 + Number(d.limite_minutos || 0) }))
        novo ? post('/convenios') : put(`/convenios/${convenio.id}`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo convênio' : 'Editar convênio'} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <div className="row g-4">
                    <div className="col-lg-6">
                    <label className="form-label fw-semibold">Conveniado (cliente)</label>
                    {cliente
                        ? <div className="d-flex align-items-center gap-2 mb-3"><strong>{cliente.nome}</strong>{novo && <button type="button" className="btn btn-link btn-sm" onClick={() => { setCliente(null); setData('cliente_id', '') }}>trocar</button>}</div>
                        : <div className="mb-3"><BuscaCliente placeholder="Buscar o cliente conveniado" onEscolher={(c) => { setCliente(c); setData('cliente_id', c.id) }} /></div>}
                    {errors.cliente_id && <div className="text-danger small mb-3" style={{ marginTop: -8 }}>{errors.cliente_id}</div>}

                    <Campo as="select" rotulo="Regra" value={data.modo} onChange={(e) => setData('modo', e.target.value)}>
                        <option value="tudo">O convênio paga tudo</option>
                        <option value="tempo">O convênio paga até um tempo (o excedente fica com quem usou)</option>
                        <option value="desconto">Desconto para quem usa</option>
                    </Campo>
                    {data.modo === 'tempo' && (
                        <div className="row g-2">
                            <div className="col-6"><Campo rotulo="Horas" type="number" min="0" value={data.limite_horas} onChange={(e) => setData('limite_horas', e.target.value)} /></div>
                            <div className="col-6"><Campo rotulo="Minutos" type="number" min="0" max="59" value={data.limite_minutos} erro={errors.limite_min} onChange={(e) => setData('limite_minutos', e.target.value)} /></div>
                        </div>
                    )}
                    {data.modo === 'desconto' && (
                        <div className="row g-2">
                            <div className="col-5">
                                <Campo as="select" rotulo="Tipo" value={data.desconto_tipo} onChange={(e) => setData('desconto_tipo', e.target.value)}>
                                    <option value="pct">Porcentagem</option>
                                    <option value="valor">Valor (R$)</option>
                                </Campo>
                            </div>
                            <div className="col-7"><CampoValor rotulo="Desconto" prefixo={data.desconto_tipo === 'pct' ? '%' : 'R$'} value={data.desconto_valor} erro={errors.desconto_valor} onChange={(v) => setData('desconto_valor', v)} /></div>
                        </div>
                    )}
                    </div>
                    <div className="col-lg-6">
                    <fieldset className="mb-3">
                        <legend className="fs-6 fw-semibold">Vale para</legend>
                        <div className="form-check"><input className="form-check-input" type="checkbox" id="ve" checked={data.vale_estadia} onChange={(e) => setData('vale_estadia', e.target.checked)} /><label className="form-check-label" htmlFor="ve">Estadias</label></div>
                        <div className="form-check"><input className="form-check-input" type="checkbox" id="vs" checked={data.vale_servicos} onChange={(e) => setData('vale_servicos', e.target.checked)} /><label className="form-check-label" htmlFor="vs">Lavagens e serviços{data.modo === 'tempo' ? ' (no modo por tempo, o convênio paga o serviço inteiro)' : ''}</label></div>
                        {errors.vale_estadia && <div className="text-danger small">{errors.vale_estadia}</div>}
                    </fieldset>
                    <Campo rotulo="Observações" value={data.observacoes} maxLength={255} onChange={(e) => setData('observacoes', e.target.value)} />
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativo" checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativo">Ativo</label>
                    </div>
                    </div>
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
