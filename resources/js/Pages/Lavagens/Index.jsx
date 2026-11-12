import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import { moeda } from '../../lib/formato'
import { telefone } from '../../lib/mascaras'

// etapas vêm da configuração da empresa, na ordem: a primeira é a entrada e a última é a entrega
const corDa = (i, total) => (i === 0 || i === total - 1 ? '' : i === total - 2 ? 'selo-ok' : 'selo-aviso')

function Cartao({ c, etapas, onReceber }) {
    const chaves = Object.keys(etapas)
    const i = chaves.indexOf(c.etapa)
    const prox = chaves[i + 1]
    const ant = i > 0 && i < chaves.length - 1 ? chaves[i - 1] : null
    const rot = prox === 'entregue' ? 'Entregar' : etapas[prox]
    const mudar = (etapa) => router.post(`/lavagens/${c.id}/etapa`, { etapa }, { preserveScroll: true })
    return (
        <div className="card mb-2">
            <div className="card-body p-3">
                <div className="d-flex align-items-start gap-2">
                    <div className="flex-grow-1">
                        <div className="fw-bold">{c.placa || 'Sem placa'} <span className="small text-muted fw-normal">#{c.numero}</span></div>
                        <div className="small text-muted">{[c.veiculo || c.tipo, c.cliente ? c.cliente.nome : 'Avulso', c.celular && telefone(c.celular)].filter(Boolean).join(' · ')}</div>
                    </div>
                    <div className="text-end">
                        <div className="fw-semibold">{moeda(c.total)}</div>
                        {c.pago ? <span className="selo selo-ok">Pago</span> : c.paga_no_balcao ? <span className="selo selo-aviso">A receber</span> : <span className="selo">{c.convenio ? 'Convênio' : 'Na conta'}</span>}
                    </div>
                </div>
                <div className="small my-2">{c.servicos.join(' + ')}</div>
                <div className="d-flex flex-wrap gap-1">
                    {prox && <button type="button" className="btn btn-sm btn-primary" onClick={() => mudar(prox)}>{rot}</button>}
                    {c.paga_no_balcao && <button type="button" className="btn btn-sm btn-outline-primary" onClick={() => onReceber(c)}>Receber {moeda(c.em_aberto)}</button>}
                    {ant && <button type="button" className="btn btn-sm btn-link text-muted" onClick={() => mudar(ant)}>Voltar para {etapas[ant].toLowerCase()}</button>}
                </div>
            </div>
        </div>
    )
}

export default function LavagensIndex({ cartoes, etapas, formas }) {
    const [recebendo, setRecebendo] = useState(null)
    const pix = formas.find((f) => f.tipo === 'pix') || formas[0]
    const form = useForm({ forma_pagamento_id: pix?.id ?? '', parcelas: 1 })
    const forma = formas.find((f) => f.id === Number(form.data.forma_pagamento_id))

    return (
        <div className="pagina">
            <TituloPagina titulo="Lavagens">
                <Link href="/lavagens/nova" className="btn btn-primary d-inline-flex align-items-center gap-2"><Icone nome="mais" /> Nova venda</Link>
            </TituloPagina>
            <div className="row g-3">
                {Object.entries(etapas).map(([k, r], idx, todas) => {
                    const lista = cartoes.filter((c) => c.etapa === k)
                    return (
                        <div key={k} className={`col-md-6 ${todas.length <= 3 ? 'col-xl-4' : todas.length === 4 ? 'col-xl-3' : 'col-xl'}`}>
                            <div className="d-flex align-items-center gap-2 mb-2">
                                <span className={`selo ${corDa(idx, todas.length)}`}>{r}</span>
                                <span className="text-muted small">{lista.length}{k === 'entregue' ? ' hoje' : ''}</span>
                            </div>
                            {lista.length === 0 && <p className="text-muted small">Nada aqui.</p>}
                            {lista.map((c) => <Cartao key={c.id} c={c} etapas={etapas} onReceber={setRecebendo} />)}
                        </div>
                    )
                })}
            </div>

            <Janela aberta={!!recebendo} titulo={`Receber · ${recebendo?.placa ?? ''}`} rotuloOk="Confirmar recebimento" onFechar={() => setRecebendo(null)}
                onEnviar={() => form.post(`/lavagens/${recebendo.id}/receber`, { preserveScroll: true, onSuccess: () => setRecebendo(null) })} ocupado={form.processing}>
                <p className="fs-4 fw-bold mb-3">{recebendo && moeda(recebendo.em_aberto)}</p>
                <div className="row g-2">
                    <div className="col-8">
                        <Campo as="select" rotulo="Forma de pagamento" value={form.data.forma_pagamento_id} onChange={(e) => form.setData('forma_pagamento_id', e.target.value)} erro={form.errors.forma_pagamento_id}>
                            {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                        </Campo>
                    </div>
                    {forma?.parcelas_max > 1 && (
                        <div className="col-4">
                            <Campo as="select" rotulo="Parcelas" value={form.data.parcelas} onChange={(e) => form.setData('parcelas', e.target.value)}>
                                {Array.from({ length: forma.parcelas_max }, (_, i) => <option key={i} value={i + 1}>{i + 1}x</option>)}
                            </Campo>
                        </div>
                    )}
                </div>
            </Janela>
        </div>
    )
}
