import { Link, useForm } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import { data as fData, moeda } from '../../lib/formato'
import { placa as mPlaca, telefone } from '../../lib/mascaras'

export default function Nova({ tipos, servicos, precos, convenios, formas }) {
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const { data, setData, post, processing, errors } = useForm({
        placa: '', tipo_veiculo_id: '', celular: '', servico_id: '', adicionais: [], valor: '', convenio_id: '',
        destino: 'entrega', forma_pagamento_id: pix?.id ?? '', parcelas: 1,
    })
    const [info, setInfo] = useState(null) // {veiculo, avulso} ou null

    useEffect(() => {
        const p = data.placa.replace(/[^A-Za-z0-9]/g, '')
        if (p.length < 7) { setInfo(null); return }
        const t = setTimeout(() => {
            fetch(`/lavagens/placa?placa=${encodeURIComponent(p)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then((r) => r.json()).then((j) => {
                    setInfo(j)
                    if (!j.veiculo && j.avulso) setData((d) => ({ ...d, tipo_veiculo_id: d.tipo_veiculo_id || j.avulso.tipo_id || '', celular: d.celular || j.avulso.celular || '' }))
                    if (j.veiculo?.cliente_id) setData((d) => ({ ...d, destino: 'conta' }))
                }).catch(() => {})
        }, 250)
        return () => clearTimeout(t)
    }, [data.placa]) // eslint-disable-line react-hooks/exhaustive-deps

    const veiculo = info?.veiculo
    const tipoId = veiculo ? veiculo.tipo_id : Number(data.tipo_veiculo_id) || null
    const servico = servicos.find((s) => s.id === Number(data.servico_id))
    const preco = (id) => (tipoId ? precos[tipoId]?.[id] ?? null : null)
    const total = servico ? [servico.id, ...data.adicionais].reduce((s, id) => s + Number((id === servico.id && servico.preco_modo === 'ate' && data.valor !== '' ? data.valor : preco(id)) || 0), 0) : 0
    const convenio = convenios.find((c) => c.id === Number(data.convenio_id))
    const forma = formas.find((f) => f.id === Number(data.forma_pagamento_id))
    const marcar = (id) => setData('adicionais', data.adicionais.includes(id) ? data.adicionais.filter((x) => x !== id) : [...data.adicionais, id])

    return (
        <div className="estreito">
            <TituloPagina titulo="Nova venda" descricao="Lavagem ou serviço, com o preço da tabela pelo tipo do veículo." />
            <form className="card" onSubmit={(e) => { e.preventDefault(); post('/lavagens') }} noValidate>
                <div className="card-body">
                    <Campo rotulo="Placa" value={mPlaca(data.placa)} onChange={(e) => setData('placa', e.target.value)} erro={errors.placa} autoFocus autoComplete="off" style={{ textTransform: 'uppercase' }} />
                    {veiculo && <p className="small mt-n2">{veiculo.descricao || veiculo.tipo} · {veiculo.cliente || 'sem cliente'} {veiculo.mensalista && <span className="selo selo-marca ms-1">Mensalista</span>}</p>}
                    {info && !veiculo && (
                        <>
                            <p className="small text-muted mt-n2">{info.avulso ? `Avulso: já veio ${info.avulso.vezes} vez(es), a última em ${fData(info.avulso.ultima)}.` : 'Placa sem cadastro: venda avulsa.'}</p>
                            <div className="row g-2">
                                <div className="col-sm-6">
                                    <Campo as="select" rotulo="Tipo do veículo" value={data.tipo_veiculo_id} onChange={(e) => setData('tipo_veiculo_id', e.target.value)} erro={errors.tipo_veiculo_id}>
                                        <option value="">Escolha</option>
                                        {tipos.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
                                    </Campo>
                                </div>
                                <div className="col-sm-6"><Campo rotulo="Celular (para avisar)" value={telefone(data.celular)} onChange={(e) => setData('celular', e.target.value)} inputMode="tel" /></div>
                            </div>
                        </>
                    )}

                    <Campo as="select" rotulo="Serviço" value={data.servico_id} onChange={(e) => setData((d) => ({ ...d, servico_id: e.target.value, adicionais: [], valor: '' }))} erro={errors.servico_id || errors.servico}>
                        <option value="">Escolha</option>
                        {servicos.map((s) => {
                            const sem = tipoId && preco(s.id) === null
                            return <option key={s.id} value={s.id} disabled={sem}>{s.nome}{sem ? ' · sem preço para este tipo' : preco(s.id) !== null ? ` · ${s.preco_modo === 'ate' ? 'até ' : ''}${moeda(preco(s.id))}` : ''}</option>
                        })}
                    </Campo>
                    {servico?.preco_modo === 'ate' && preco(servico.id) !== null && <CampoValor rotulo={`Valor cobrado (até ${moeda(preco(servico.id))})`} value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} />}
                    {servico?.adicionais.length > 0 && (
                        <fieldset className="mb-3">
                            <legend className="fs-6 fw-semibold">Adicionais</legend>
                            {servico.adicionais.map((a) => (
                                <div key={a.id} className="form-check">
                                    <input className="form-check-input" type="checkbox" id={`ad-${a.id}`} checked={data.adicionais.includes(a.id)} onChange={() => marcar(a.id)} disabled={!!tipoId && preco(a.id) === null} />
                                    <label className="form-check-label" htmlFor={`ad-${a.id}`}>{a.nome}{preco(a.id) !== null ? ` · ${moeda(preco(a.id))}` : tipoId ? ' · sem preço para este tipo' : ''}</label>
                                </div>
                            ))}
                            {errors.adicionais && <div className="text-danger small">{errors.adicionais}</div>}
                        </fieldset>
                    )}
                    {convenios.length > 0 && (
                        <Campo as="select" rotulo="Convênio" value={data.convenio_id} onChange={(e) => setData('convenio_id', e.target.value)} erro={errors.convenio_id}>
                            <option value="">Sem convênio</option>
                            {convenios.map((c) => <option key={c.id} value={c.id}>{c.nome} · {c.resumo}</option>)}
                        </Campo>
                    )}
                    {servico && tipoId && <p className="fw-semibold fs-5">Total: {moeda(total)}{convenio && <span className="small text-muted fw-normal"> · {convenio.resumo} (aplicado ao salvar)</span>}</p>}

                    <div className="btn-group w-100 mb-3 flex-wrap" role="group" aria-label="Quando paga">
                        {[['entrega', 'Paga na entrega'], ['na_hora', 'Paga agora'], ...(veiculo?.cliente_id ? [['conta', 'Na conta do cliente']] : [])].map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${data.destino === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setData('destino', v)}>{r}</button>
                        ))}
                    </div>
                    {errors.destino && <div className="text-danger small mb-2">{errors.destino}</div>}
                    {data.destino === 'na_hora' && (
                        <div className="row g-2">
                            <div className="col-8">
                                <Campo as="select" rotulo="Forma de pagamento" value={data.forma_pagamento_id} onChange={(e) => setData('forma_pagamento_id', e.target.value)} erro={errors.forma_pagamento_id}>
                                    {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                </Campo>
                            </div>
                            {forma?.parcelas_max > 1 && (
                                <div className="col-4">
                                    <Campo as="select" rotulo="Parcelas" value={data.parcelas} onChange={(e) => setData('parcelas', e.target.value)}>
                                        {Array.from({ length: forma.parcelas_max }, (_, i) => <option key={i} value={i + 1}>{i + 1}x</option>)}
                                    </Campo>
                                </div>
                            )}
                        </div>
                    )}
                </div>
                <div className="card-footer d-flex gap-2 justify-content-end">
                    <Link href="/lavagens" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Lançar venda</button>
                </div>
            </form>
        </div>
    )
}
