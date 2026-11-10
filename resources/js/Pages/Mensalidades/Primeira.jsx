import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import CampoValor from '../../Components/CampoValor'
import { competencia } from '../../Components/Dinheiro'
import { data as fData, moeda } from '../../lib/formato'
import { usePode } from '../../lib/permissoes'

export default function Primeira({ contrato, cliente, veiculo, proporcional, proximo_vencimento, opcoes, livres, ja_geradas, de, fila }) {
    const pode = usePode()
    const mudaValor = pode('mensalidade_valor')
    const { data, setData, post, processing, errors } = useForm({ opcao: 'prop_proximo', valor: proporcional.valor, de })
    const prop = mudaValor && data.valor !== '' ? data.valor : proporcional.valor

    const resumo = {
        proporcional: [[`Proporcional ${proporcional.de} a ${proporcional.ate}`, prop, 'hoje']],
        prop_proximo: [[`Proporcional ${proporcional.de} a ${proporcional.ate}`, prop, 'hoje'], ['Próximo mês', contrato.valor, fData(proximo_vencimento)]],
        integral: [['Mês cheio', contrato.valor, 'hoje']],
        proximo: [['Próximo mês (este mês é cortesia)', contrato.valor, fData(proximo_vencimento)]],
        nenhuma: [],
    }[data.opcao]

    return (
        <div className="estreito">
            <TituloPagina titulo="Primeira cobrança" descricao={`${cliente.nome} · ${veiculo} · ${moeda(contrato.valor)} por mês (${contrato.periodo})`} />
            <form onSubmit={(e) => { e.preventDefault(); post(`/mensalidades/${contrato.id}/primeira-cobranca`) }} className="card" noValidate>
                <div className="card-body">
                    {ja_geradas.length > 0 && (
                        <div className="alert aviso-neutro small">
                            Já existe na conta: {ja_geradas.map((o) => `${o.descricao} ${competencia(o.competencia)} (${moeda(o.valor)})`).join('; ')}. O mesmo mês não é lançado duas vezes.
                        </div>
                    )}
                    <p className="text-muted small">Entrou hoje: {proporcional.dias} dia(s) até o fim do mês (mês comercial de 30 dias). Vencimento do cliente: dia {cliente.dia_vencimento}.</p>
                    <fieldset className="mb-3">
                        <legend className="fs-6 fw-semibold">Como fica</legend>
                        {Object.entries(opcoes).map(([k, r]) => {
                            const bloqueada = !mudaValor && !livres.includes(k)
                            return (
                                <div key={k} className="form-check">
                                    <input className="form-check-input" type="radio" id={`op-${k}`} checked={data.opcao === k} disabled={bloqueada} onChange={() => setData('opcao', k)} />
                                    <label className="form-check-label" htmlFor={`op-${k}`}>{r}{bloqueada ? ' (só com permissão)' : ''}</label>
                                </div>
                            )
                        })}
                        {errors.opcao && <div className="text-danger small">{errors.opcao}</div>}
                    </fieldset>

                    {mudaValor && ['proporcional', 'prop_proximo'].includes(data.opcao) && (
                        <CampoValor rotulo="Valor do proporcional" value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} ajuda={`Calculado: ${moeda(proporcional.valor)}`} />
                    )}

                    {resumo.length > 0 ? (
                        <ul className="list-group mb-0">
                            {resumo.map(([d, v, venc]) => (
                                <li key={d} className="list-group-item d-flex justify-content-between"><span>{d} <span className="small text-muted">· vence {venc}</span></span><strong>{moeda(v)}</strong></li>
                            ))}
                        </ul>
                    ) : <p className="mb-0 text-muted">Nada é lançado agora. A mensalidade entra na geração do mês.</p>}
                </div>
                <div className="card-footer d-flex gap-2 justify-content-end">
                    <Link href={`/clientes/${cliente.id}`} className="btn btn-outline-secondary">Decidir depois</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>Confirmar{fila > 0 ? ' e ir para a fila' : ''}</button>
                </div>
            </form>
        </div>
    )
}
