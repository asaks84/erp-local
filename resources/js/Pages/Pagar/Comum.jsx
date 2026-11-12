import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'

export const ESTADOS = { aberta: ['A pagar', ''], hoje: ['Vence hoje', 'selo-aviso'], atrasada: ['Atrasada', 'selo-erro'], paga: ['Paga', 'selo-ok'], cancelada: ['Cancelada', ''] }

export function SeloDespesa({ estado }) {
    const [r, c] = ESTADOS[estado] || [estado, '']
    return <span className={`selo ${c}`}>{r}</span>
}

const hoje = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
export { hoje }

/** campos do pagamento (data, valor pago, forma, de onde saiu, comprovante), usados no lançamento e na janela Pagar */
export function CamposPagamento({ form, formas, contasFinanceiras, valorPrevisto, comComprovante = true }) {
    const { data, setData, errors } = form
    const escolherForma = (id) => {
        const f = formas.find((x) => x.id === Number(id))
        setData((d) => ({ ...d, forma_pagamento_id: id, conta_financeira_id: f?.conta_financeira_id ?? d.conta_financeira_id }))
    }
    return (
        <div className="row g-2">
            <div className="col-sm-6"><CampoData rotulo="Pago em" value={data.pago_em} onChange={(v) => setData('pago_em', v)} erro={errors.pago_em} /></div>
            <div className="col-sm-6"><CampoValor rotulo="Valor pago" value={data.valor_pago} onChange={(v) => setData('valor_pago', v)} erro={errors.valor_pago}
                ajuda={valorPrevisto ? 'Vazio = o valor da conta. Com juros ou desconto, informe o que pagou.' : null} /></div>
            <div className="col-sm-6">
                <Campo as="select" rotulo="Forma de pagamento" value={data.forma_pagamento_id} onChange={(e) => escolherForma(e.target.value)} erro={errors.forma_pagamento_id}>
                    <option value="">Escolha</option>
                    {formas.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                </Campo>
            </div>
            <div className="col-sm-6">
                <Campo as="select" rotulo="Saiu de" value={data.conta_financeira_id} onChange={(e) => setData('conta_financeira_id', e.target.value)} erro={errors.conta_financeira_id}>
                    <option value="">Escolha</option>
                    {contasFinanceiras.map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}
                </Campo>
            </div>
            {comComprovante && <div className="col-12"><CampoArquivo form={form} /></div>}
        </div>
    )
}

export function CampoArquivo({ form, rotulo = 'Comprovante (PDF ou foto, até 5 MB)' }) {
    return (
        <div className="mb-3">
            <label className="form-label small">{rotulo}</label>
            <input type="file" className={`form-control${form.errors.comprovante ? ' is-invalid' : ''}`} accept=".pdf,image/*"
                onChange={(e) => form.setData('comprovante', e.target.files[0] || null)} />
            {form.errors.comprovante && <div className="invalid-feedback">{form.errors.comprovante}</div>}
        </div>
    )
}
