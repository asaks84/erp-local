import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import Janela from './Janela'
import Campo from './Campo'
import { GRUPOS, Regra, regrasDoCliente, textoRegra } from './RegrasBoleto'
import { usePode } from '../lib/permissoes'

const TODOS = { multa: true, juros: true, desconto: true, baixa: true }

/** Ficha do cliente: regras do boleto — as da empresa ou específicas dele (ativado/desativado). */
export default function RegrasBoletoCliente({ cliente, empresa }) {
    const pode = usePode()
    const [aberta, setAberta] = useState(false)
    const especificas = !!cliente.regras_boleto
    const valem = regrasDoCliente(empresa, cliente.regras_boleto)
    const form = useForm({ ...valem, especificas })
    const abrir = () => { form.setData({ ...valem, especificas }); form.clearErrors(); setAberta(true) }
    const salvar = () => form.transform((d) => ({ ...d, particular: d.especificas ? TODOS : {} }))
        .put(`/clientes/${cliente.id}/regras-boleto`, { preserveScroll: true, onSuccess: () => setAberta(false) })

    return (
        <div className="card mt-4">
            <div className="card-header d-flex align-items-center gap-2">
                <span className="flex-grow-1">Regras do boleto</span>
                {especificas ? <span className="selo selo-marca">Específicas</span> : <span className="selo">Da empresa</span>}
                {pode('configuracoes') && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={abrir}>Alterar</button>}
            </div>
            <ul className="list-group list-group-flush small">
                {GRUPOS.map(([g]) => <li key={g} className="list-group-item bg-transparent">{textoRegra(g, valem)}</li>)}
            </ul>

            <Janela aberta={aberta} titulo={`Regras do boleto · ${cliente.nome}`} rotuloOk="Salvar" onFechar={() => setAberta(false)} onEnviar={salvar} ocupado={form.processing}>
                <div className="d-flex flex-wrap gap-4 mb-3">
                    <div className="form-check">
                        <input className="form-check-input" type="radio" name="rb-modo" id="rb-empresa" checked={!form.data.especificas} onChange={() => form.setData({ ...empresa, especificas: false })} />
                        <label className="form-check-label" htmlFor="rb-empresa">Regras da empresa</label>
                    </div>
                    <div className="form-check">
                        <input className="form-check-input" type="radio" name="rb-modo" id="rb-especificas" checked={form.data.especificas} onChange={() => form.setData('especificas', true)} />
                        <label className="form-check-label" htmlFor="rb-especificas">Regras específicas deste cliente</label>
                    </div>
                </div>
                {!form.data.especificas ? (
                    <>
                        <p className="small text-muted mb-2">Segue Configurações › Banco Inter, inclusive quando elas mudarem:</p>
                        <ul className="small mb-0">{GRUPOS.map(([g]) => <li key={g}>{textoRegra(g, empresa)}</li>)}</ul>
                    </>
                ) : (
                    <>
                        <p className="small text-muted">Valem só para este cliente, nos próximos boletos. Mudanças nas regras da empresa não mudam estas.</p>
                        <Regra nome="multa" rotulo="Multa por atraso" form={form} />
                        <Regra nome="juros" rotulo="Juros por atraso" form={form} />
                        <Regra nome="desconto" rotulo="Desconto de pontualidade (só mensalidades)" form={form} />
                        <Campo rotulo="Baixa automática (dias depois do vencimento)" type="number" min="0" max="60" value={form.data.boleto_baixa_dias} onChange={(e) => form.setData('boleto_baixa_dias', e.target.value)} erro={form.errors.boleto_baixa_dias} />
                    </>
                )}
            </Janela>
        </div>
    )
}
