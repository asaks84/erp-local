import { Link, router, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { data as fData, moeda } from '../../lib/formato'

const hoje = () => new Date().toISOString().slice(0, 10)

export default function ServicoForm({ servico, tipos, unidades, adicionais_disponiveis }) {
    const novo = !servico
    const { data, setData, post, put, processing, errors } = useForm({
        codigo: servico?.codigo || '', nome: servico?.nome || '', papel: servico?.papel || 'principal', preco_modo: servico?.preco_modo || 'fixo',
        detalhe: servico?.detalhe || '', ativo: servico ? servico.ativo : true,
        codigo_servico_municipal: servico?.codigo_servico_municipal || '', aliquota_iss: servico?.aliquota_iss ?? '',
        adicionais: servico?.adicionais || [], unidade_id: servico?.unidade_id || '', vale_a_partir: hoje(),
        precos: Object.fromEntries(tipos.map((t) => [t.id, servico?.precos?.[t.id] ?? ''])),
    })

    function salvar(e) {
        e.preventDefault()
        novo ? post('/configuracoes/servicos') : put(`/configuracoes/servicos/${servico.id}`)
    }

    const nomeTipo = (id) => tipos.find((t) => t.id === id)?.nome || id
    return (
        <div className="estreito">
            <TituloPagina titulo={novo ? 'Novo serviço' : `Serviço: ${servico.nome}`} />
            <form onSubmit={salvar} className="card" noValidate>
                <div className="card-body">
                    <div className="row g-2">
                        <div className="col-4"><Campo rotulo="Código" value={data.codigo} erro={errors.codigo} maxLength={10} onChange={(e) => setData('codigo', e.target.value.toUpperCase())} /></div>
                        <div className="col-8"><Campo rotulo="Nome" value={data.nome} erro={errors.nome} maxLength={60} onChange={(e) => setData('nome', e.target.value)} /></div>
                    </div>
                    <div className="row g-2">
                        <div className="col-sm-6">
                            <Campo as="select" rotulo="Papel" value={data.papel} onChange={(e) => setData('papel', e.target.value)}>
                                <option value="principal">Principal (lavagem, polimento…)</option>
                                <option value="adicional">Adicional (acompanha um principal)</option>
                            </Campo>
                        </div>
                        <div className="col-sm-6">
                            <Campo as="select" rotulo="Preço" value={data.preco_modo} onChange={(e) => setData('preco_modo', e.target.value)}
                                ajuda={data.preco_modo === 'ate' ? 'Na venda, o operador digita o valor, até o máximo da tabela.' : null}>
                                <option value="fixo">Fixo (o da tabela)</option>
                                <option value="ate">Até (valor máximo)</option>
                            </Campo>
                        </div>
                    </div>
                    <Campo rotulo="Detalhe (o que inclui)" value={data.detalhe} erro={errors.detalhe} maxLength={160} onChange={(e) => setData('detalhe', e.target.value)} />

                    {data.papel === 'principal' && adicionais_disponiveis.length > 0 && (
                        <fieldset className="mb-3">
                            <legend className="fs-6 fw-semibold">Adicionais que podem acompanhar</legend>
                            <div className="d-flex flex-wrap gap-3">
                                {adicionais_disponiveis.map((a) => (
                                    <div className="form-check" key={a.id}>
                                        <input className="form-check-input" type="checkbox" id={`ad-${a.id}`} checked={data.adicionais.includes(a.id)}
                                            onChange={(e) => setData('adicionais', e.target.checked ? [...data.adicionais, a.id] : data.adicionais.filter((x) => x !== a.id))} />
                                        <label className="form-check-label" htmlFor={`ad-${a.id}`}>{a.nome}</label>
                                    </div>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    <h3 className="fs-6 fw-semibold mt-3">Preço por tipo de veículo</h3>
                    {unidades.length > 1 && !novo && (
                        <Campo as="select" rotulo="Preços de" value={data.unidade_id}
                            onChange={(e) => router.get(`/configuracoes/servicos/${servico.id}`, { unidade: e.target.value || undefined }, { preserveScroll: true })}>
                            <option value="">Empresa (todas as unidades)</option>
                            {unidades.map((u) => <option key={u.id} value={u.id}>Exceção da unidade {u.nome}</option>)}
                        </Campo>
                    )}
                    <div className="row g-2">
                        {tipos.map((t) => (
                            <div className="col-6 col-sm-4" key={t.id}>
                                <CampoValor rotulo={t.nome} value={data.precos[t.id]} erro={errors[`precos.${t.id}`]} onChange={(v) => setData('precos', { ...data.precos, [t.id]: v })} />
                            </div>
                        ))}
                    </div>
                    <p className="small text-muted">Vazio = serviço não oferecido para esse tipo.</p>
                    <CampoData rotulo="Preços valem a partir de" value={data.vale_a_partir} erro={errors.vale_a_partir} onChange={(v) => setData('vale_a_partir', v)}
                        ajuda="Para reajuste agendado, escolha a data futura: até lá vale o preço atual." />
                    {servico?.agendados?.length > 0 && (
                        <div className="alert aviso-neutro small">
                            <strong>Mudanças agendadas:</strong>
                            <ul className="mb-0">{servico.agendados.map((a, i) => <li key={i}>{nomeTipo(a.tipo_veiculo_id)}: {moeda(a.valor)} a partir de {fData(a.vigencia_inicio)}</li>)}</ul>
                        </div>
                    )}

                    <details className="mb-3">
                        <summary className="text-muted">Dados fiscais (para a nota fiscal, no futuro)</summary>
                        <div className="row g-2 mt-2">
                            <div className="col-sm-6"><Campo rotulo="Código de serviço municipal" value={data.codigo_servico_municipal} maxLength={20} onChange={(e) => setData('codigo_servico_municipal', e.target.value)} /></div>
                            <div className="col-sm-6"><CampoValor rotulo="Alíquota de ISS" prefixo="%" value={data.aliquota_iss} erro={errors.aliquota_iss} onChange={(v) => setData('aliquota_iss', v)} /></div>
                        </div>
                    </details>
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" role="switch" id="ativo" checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="ativo">Ativo (aparece na venda)</label>
                    </div>
                </div>
                <div className="card-footer bg-transparent d-flex gap-2 justify-content-end p-3">
                    <Link href="/configuracoes/servicos" className="btn btn-outline-secondary">Cancelar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                </div>
            </form>
        </div>
    )
}
