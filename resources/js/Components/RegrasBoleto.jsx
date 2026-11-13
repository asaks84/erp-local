import Campo from './Campo'
import CampoValor from './CampoValor'
import { moeda } from '../lib/formato'

// Regras do boleto do Inter (multa, juros, desconto, baixa): usadas em Configurações › Banco Inter
// e nas regras particulares do cliente.

export const TIPOS = {
    multa: [['nenhuma', 'Sem multa'], ['percentual', 'Percentual (%)'], ['valor', 'Valor fixo (R$)']],
    juros: [['nenhum', 'Sem juros'], ['percentual_mes', 'Percentual ao mês (%)'], ['valor_dia', 'Valor por dia (R$)']],
    desconto: [['nenhum', 'Sem desconto'], ['percentual', 'Percentual da mensalidade (%)'], ['valor', 'Valor fixo por mensalidade (R$)']],
}

export function Regra({ nome, rotulo, form, ajuda }) {
    const tipo = form.data[`boleto_${nome}_tipo`]
    const sem = tipo === TIPOS[nome][0][0]
    const pct = tipo.startsWith('percentual')
    return (
        <div className="row g-2">
            <div className="col-sm-6">
                <Campo as="select" rotulo={rotulo} value={tipo} onChange={(e) => form.setData(`boleto_${nome}_tipo`, e.target.value)} erro={form.errors[`boleto_${nome}_tipo`]}>
                    {TIPOS[nome].map(([v, r]) => <option key={v} value={v}>{r}</option>)}
                </Campo>
            </div>
            <div className="col-sm-6">
                {!sem && (pct
                    ? <Campo rotulo={tipo === 'percentual_mes' ? '% ao mês' : '%'} type="number" step="0.01" min="0" value={form.data[`boleto_${nome}_valor`]} onChange={(e) => form.setData(`boleto_${nome}_valor`, e.target.value)} erro={form.errors[`boleto_${nome}_valor`]} ajuda={ajuda} />
                    : <CampoValor rotulo={tipo === 'valor_dia' ? 'R$ por dia de atraso' : 'Valor (R$)'} value={form.data[`boleto_${nome}_valor`]} onChange={(v) => form.setData(`boleto_${nome}_valor`, v)} erro={form.errors[`boleto_${nome}_valor`]} />)}
            </div>
        </div>
    )
}


const num = (v) => Number(v || 0).toLocaleString('pt-BR', { maximumFractionDigits: 2 })

/** texto curto de cada grupo: "Multa 2%", "Juros 1% ao mês", "Desconto R$ 10,00 até 5 dias antes", "Baixa 30 dias após o vencimento" */
export function textoRegra(grupo, r) {
    if (grupo === 'multa') return { nenhuma: 'Sem multa', percentual: `Multa ${num(r.boleto_multa_valor)}%`, valor: `Multa ${moeda(r.boleto_multa_valor)}` }[r.boleto_multa_tipo]
    if (grupo === 'juros') return { nenhum: 'Sem juros', percentual_mes: `Juros ${num(r.boleto_juros_valor)}% ao mês`, valor_dia: `Juros ${moeda(r.boleto_juros_valor)} por dia` }[r.boleto_juros_tipo]
    if (grupo === 'desconto') {
        if (r.boleto_desconto_tipo === 'nenhum') return 'Sem desconto'
        const v = r.boleto_desconto_tipo === 'percentual' ? `${num(r.boleto_desconto_valor)}%` : moeda(r.boleto_desconto_valor)
        return `Desconto ${v} por mensalidade paga até o vencimento`
    }
    return `Baixa ${r.boleto_baixa_dias} dia(s) após o vencimento`
}

const GRUPOS = [['multa', 'Multa'], ['juros', 'Juros'], ['desconto', 'Desconto'], ['baixa', 'Baixa automática']]

/** regras que valem para o cliente: as particulares dele por cima das da empresa */
export function regrasDoCliente(empresa, particulares) {
    return { ...empresa, ...(particulares || {}) }
}

export { GRUPOS }
