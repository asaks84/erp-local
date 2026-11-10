import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { moeda } from '../../lib/formato'
import { usePode } from '../../lib/permissoes'

const hoje = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const TITULOS = { servico: 'Lançar lavagem ou serviço', estadia: 'Lançar estadia', debito: 'Lançar débito', credito: 'Lançar crédito' }

export default function Lancar({ cliente, veiculos, servicos, precos, tipo: tipoInicial }) {
    const pode = usePode()
    const { data, setData, post, processing, errors } = useForm({
        tipo: tipoInicial,
        data: hoje(),
        veiculo_id: veiculos.length === 1 ? veiculos[0].id : '',
        servico_id: '',
        adicionais: [],
        valor: '',
        descricao: '',
        motivo: '',
    })
    const veiculo = veiculos.find((v) => v.id === Number(data.veiculo_id))
    const servico = servicos.find((s) => s.id === Number(data.servico_id))
    const preco = (id) => (veiculo ? precos[veiculo.tipo_id]?.[id] ?? null : null)
    const totalServico = servico ? [servico.id, ...data.adicionais].reduce((s, id) => s + Number((id === servico.id && servico.preco_modo === 'ate' && data.valor !== '' ? data.valor : preco(id)) || 0), 0) : 0
    const tipos = [['servico', 'Lavagem/serviço'], ['estadia', 'Estadia'], ...(pode('contas_editar') ? [['debito', 'Débito'], ['credito', 'Crédito']] : [])]

    function adicional(id) {
        setData('adicionais', data.adicionais.includes(id) ? data.adicionais.filter((x) => x !== id) : [...data.adicionais, id])
    }
    function enviar(e) {
        e.preventDefault()
        post(`/clientes/${cliente.id}/lancar`)
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={TITULOS[data.tipo]} descricao={`Cliente: ${cliente.nome}`} />
            <form onSubmit={enviar} className="card" noValidate>
                <div className="card-body">
                    <div className="btn-group w-100 mb-3 flex-wrap" role="group" aria-label="O que lançar">
                        {tipos.map(([v, r]) => (
                            <button key={v} type="button" className={`btn ${data.tipo === v ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setData('tipo', v)}>{r}</button>
                        ))}
                    </div>

                    {data.tipo === 'servico' && (
                        <>
                            {veiculos.length === 0 && <div className="alert alert-warning">O cliente não tem veículo ativo. Cadastre o veículo antes de lançar uma lavagem.</div>}
                            <Campo as="select" rotulo="Veículo" value={data.veiculo_id} onChange={(e) => setData('veiculo_id', e.target.value)} erro={errors.veiculo_id}>
                                <option value="">Escolha</option>
                                {veiculos.map((v) => <option key={v.id} value={v.id}>{v.rotulo}{v.tipo ? ` (${v.tipo})` : ''}</option>)}
                            </Campo>
                            <Campo as="select" rotulo="Serviço" value={data.servico_id} onChange={(e) => setData((d) => ({ ...d, servico_id: e.target.value, adicionais: [], valor: '' }))} erro={errors.servico_id || errors.servico}>
                                <option value="">Escolha</option>
                                {servicos.map((s) => <option key={s.id} value={s.id}>{s.nome}{preco(s.id) !== null ? ` · ${s.preco_modo === 'ate' ? 'até ' : ''}${moeda(preco(s.id))}` : ''}</option>)}
                            </Campo>
                            {servico?.preco_modo === 'ate' && (
                                <CampoValor rotulo={`Valor cobrado (até ${moeda(preco(servico.id))})`} value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} />
                            )}
                            {servico?.adicionais.length > 0 && (
                                <fieldset className="mb-3">
                                    <legend className="fs-6 fw-semibold">Adicionais</legend>
                                    {servico.adicionais.map((a) => (
                                        <div key={a.id} className="form-check">
                                            <input className="form-check-input" type="checkbox" id={`ad-${a.id}`} checked={data.adicionais.includes(a.id)} onChange={() => adicional(a.id)} />
                                            <label className="form-check-label" htmlFor={`ad-${a.id}`}>{a.nome}{preco(a.id) !== null ? ` · ${moeda(preco(a.id))}` : ' · sem preço para este tipo'}</label>
                                        </div>
                                    ))}
                                    {errors.adicionais && <div className="text-danger small">{errors.adicionais}</div>}
                                </fieldset>
                            )}
                            {servico && veiculo && <p className="fw-semibold">Total: {moeda(totalServico)}</p>}
                        </>
                    )}

                    {data.tipo !== 'servico' && (
                        <>
                            <CampoValor rotulo="Valor" value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} autoFocus />
                            <Campo rotulo={data.tipo === 'estadia' ? 'Descrição (vazio = "Estadia avulsa")' : 'Descrição (opcional)'} value={data.descricao} onChange={(e) => setData('descricao', e.target.value)} erro={errors.descricao} maxLength={80} />
                            {data.tipo !== 'estadia' && <Campo rotulo="Motivo" value={data.motivo} onChange={(e) => setData('motivo', e.target.value)} erro={errors.motivo} maxLength={200} />}
                            {data.tipo === 'credito' && <p className="small text-muted">O crédito abate da próxima conta do cliente.</p>}
                        </>
                    )}

                    <CampoData rotulo="Data" value={data.data} onChange={(v) => setData('data', v)} erro={errors.data} />
                </div>
                <div className="card-footer d-flex gap-2 justify-content-end">
                    <Link href={`/clientes/${cliente.id}`} className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Lançar</button>
                </div>
            </form>
        </div>
    )
}
