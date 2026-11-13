import { Link, useForm } from '@inertiajs/react'
import { useMemo } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { SeloOrdem, descricaoOrdem } from '../../Components/Dinheiro'
import { data as fData, moeda } from '../../lib/formato'
import { usePode } from '../../lib/permissoes'

const hoje = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const centavos = (v) => Math.round(Number(v || 0) * 100)

export default function Receber({ cliente, ordens, formas, servicos, veiculos }) {
    const pode = usePode()
    const contas = ordens.filter((o) => o.tipo !== 'credito')
    const creditos = ordens.filter((o) => o.tipo === 'credito')
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const { data, setData, post, transform, processing, errors } = useForm({
        ordens: contas.map((o) => o.id),
        valor: '',
        forma_pagamento_id: pix?.id ?? '',
        parcelas: 1,
        recebido_em: hoje(),
        sobra: '',
        falta: '',
        servico_id: '',
        veiculo_id: veiculos.length === 1 ? veiculos[0].id : '',
        motivo: '',
        observacao: '',
    })

    // créditos sempre abatem
    const total = useMemo(() => {
        const marcadas = contas.filter((o) => data.ordens.includes(o.id)).reduce((s, o) => s + centavos(o.valor), 0)
        return (marcadas - creditos.reduce((s, o) => s + centavos(o.valor), 0)) / 100
    }, [data.ordens]) // eslint-disable-line react-hooks/exhaustive-deps
    const recebido = data.valor === '' ? null : data.valor
    const dif = recebido === null ? 0 : (centavos(recebido) - centavos(total)) / 100
    const forma = formas.find((f) => f.id === Number(data.forma_pagamento_id))

    function marcar(id) {
        setData('ordens', data.ordens.includes(id) ? data.ordens.filter((x) => x !== id) : [...data.ordens, id])
    }
    function enviar(e) {
        e.preventDefault()
        transform((d) => ({ ...d, sobra: dif > 0 ? d.sobra : '', falta: dif < 0 ? d.falta : '' }))
        post(`/clientes/${cliente.id}/receber`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo="Receber" descricao={`Cliente: ${cliente.nome}`} />
            <form onSubmit={enviar} noValidate>
                <div className="row g-4 mb-3">
                <div className="col-lg-6">
                <div className="card h-100">
                    <div className="card-header">Contas pagas</div>
                    <ul className="list-group list-group-flush">
                        {contas.map((o) => (
                            <li key={o.id} className="list-group-item">
                                <label className="d-flex gap-3 align-items-start" style={{ cursor: 'pointer' }}>
                                    <input type="checkbox" className="form-check-input mt-1" checked={data.ordens.includes(o.id)} onChange={() => marcar(o.id)} />
                                    <span className="flex-grow-1">
                                        <span className="d-block">{descricaoOrdem(o)} <SeloOrdem o={o} /></span>
                                        <span className="small text-muted">Nº {o.numero}{o.placa ? ` · ${o.placa}` : ''} · venc. {fData(o.vencimento || o.data)}</span>
                                    </span>
                                    <span className="text-nowrap">{moeda(o.valor)}</span>
                                </label>
                            </li>
                        ))}
                        {creditos.map((o) => (
                            <li key={o.id} className="list-group-item d-flex gap-3 text-success">
                                <span className="flex-grow-1">{o.descricao} <span className="small text-muted">(abate sempre)</span></span>
                                <span className="text-nowrap">-{moeda(o.valor)}</span>
                            </li>
                        ))}
                    </ul>
                    {errors.ordens && <div className="card-body text-danger small py-2">{errors.ordens}</div>}
                    <div className="card-footer d-flex justify-content-between fw-semibold">
                        <span>Total marcado</span><span>{moeda(total)}</span>
                    </div>
                </div>
                </div>

                <div className="col-lg-6">
                <div className="card h-100">
                    <div className="card-header">Pagamento</div>
                    <div className="card-body">
                        <div className="row g-2">
                            <div className="col-sm-6">
                                <CampoValor rotulo="Valor recebido" value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} autoFocus
                                    ajuda={<button type="button" className="btn btn-link btn-sm p-0" onClick={() => setData('valor', Math.max(0, total))}>Usar o total ({moeda(Math.max(0, total))})</button>} />
                            </div>
                            <div className="col-sm-6">
                                <CampoData rotulo="Data do pagamento" value={data.recebido_em} onChange={(v) => setData('recebido_em', v)} erro={errors.recebido_em} />
                            </div>
                            <div className="col-sm-8">
                                <Campo as="select" rotulo="Forma de pagamento" value={data.forma_pagamento_id} onChange={(e) => setData('forma_pagamento_id', e.target.value)} erro={errors.forma_pagamento_id}>
                                    {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                                </Campo>
                            </div>
                            {forma?.parcelas_max > 1 && (
                                <div className="col-sm-4">
                                    <Campo as="select" rotulo="Parcelas" value={data.parcelas} onChange={(e) => setData('parcelas', e.target.value)}>
                                        {Array.from({ length: forma.parcelas_max }, (_, i) => <option key={i} value={i + 1}>{i + 1}x</option>)}
                                    </Campo>
                                </div>
                            )}
                        </div>

                        {recebido !== null && dif > 0 && (
                            <fieldset className="border rounded p-3 mb-3">
                                <legend className="fs-6 fw-semibold float-none w-auto px-1 mb-0">Recebeu {moeda(dif)} a mais</legend>
                                <div className="form-check"><input className="form-check-input" type="radio" id="s-credito" checked={data.sobra === 'credito'} onChange={() => setData('sobra', 'credito')} /><label className="form-check-label" htmlFor="s-credito">Fica de crédito para o próximo mês</label></div>
                                <div className="form-check"><input className="form-check-input" type="radio" id="s-servico" checked={data.sobra === 'servico'} onChange={() => setData('sobra', 'servico')} /><label className="form-check-label" htmlFor="s-servico">Foi um serviço feito na hora (estadia, lavagem etc.)</label></div>
                                {data.sobra === 'servico' && (
                                    <div className="row g-2 mt-1">
                                        <div className="col-sm-6"><Campo as="select" rotulo="Serviço" value={data.servico_id} onChange={(e) => setData('servico_id', e.target.value)} erro={errors.servico_id}><option value="">Escolha</option><option value="estadia">Estadia avulsa</option>{servicos.map((s) => <option key={s.id} value={s.id}>{s.nome}</option>)}</Campo></div>
                                        <div className="col-sm-6"><Campo as="select" rotulo="Veículo" value={data.veiculo_id} onChange={(e) => setData('veiculo_id', e.target.value)} erro={errors.veiculo_id}><option value="">Sem veículo</option>{veiculos.map((v) => <option key={v.id} value={v.id}>{v.rotulo}</option>)}</Campo></div>
                                    </div>
                                )}
                                {errors.sobra && <div className="text-danger small">{errors.sobra}</div>}
                            </fieldset>
                        )}
                        {recebido !== null && dif < 0 && (
                            <fieldset className="border rounded p-3 mb-3">
                                <legend className="fs-6 fw-semibold float-none w-auto px-1 mb-0">Faltaram {moeda(-dif)}</legend>
                                <div className="form-check"><input className="form-check-input" type="radio" id="f-debito" checked={data.falta === 'debito'} onChange={() => setData('falta', 'debito')} /><label className="form-check-label" htmlFor="f-debito">Fica de débito para a próxima cobrança</label></div>
                                {pode('desconto') && <div className="form-check"><input className="form-check-input" type="radio" id="f-desconto" checked={data.falta === 'desconto'} onChange={() => setData('falta', 'desconto')} /><label className="form-check-label" htmlFor="f-desconto">Dar desconto</label></div>}
                                {data.falta && <Campo rotulo={data.falta === 'desconto' ? 'Motivo do desconto' : 'Motivo (opcional)'} value={data.motivo} onChange={(e) => setData('motivo', e.target.value)} erro={errors.motivo} maxLength={200} className="mt-2 mb-0" />}
                                {errors.falta && <div className="text-danger small">{errors.falta}</div>}
                                <p className="small text-muted mb-0 mt-2">Para pagar só parte das contas, desmarque as que ficam em aberto.</p>
                            </fieldset>
                        )}
                        <Campo rotulo="Observação (opcional)" value={data.observacao} onChange={(e) => setData('observacao', e.target.value)} maxLength={255} className="mb-0" />
                    </div>
                </div>
                </div>
                </div>

                <div className="d-flex gap-2 justify-content-end">
                    <Link href={`/clientes/${cliente.id}`} className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing || recebido === null}>Confirmar recebimento</button>
                </div>
            </form>
        </div>
    )
}
