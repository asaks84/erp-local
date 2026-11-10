import { data, dataHora, moeda } from '../lib/formato'

/** data e hora do pagamento; sem hora conhecida (importado, 00:00 ou 12:00 do extrato) mostra só a data */
export function quando(v) {
    return /\s(00:00|12:00)(:00)?$/.test(String(v || '')) ? data(v) : dataHora(v)
}

// Rótulos e selos do dinheiro (ordens, cobranças, pagamentos), iguais em todas as telas.

export const TIPOS_ORDEM = { mensalidade: 'Mensalidade', servico: 'Lavagem/serviço', estadia: 'Estadia', debito: 'Débito', credito: 'Crédito' }

/** "2026-11" → "11/2026" */
export function competencia(c) {
    if (!c) return ''
    const [a, m] = String(c).split('-')
    return `${m}/${a}`
}

/** descrição da ordem com o mês da mensalidade */
export function descricaoOrdem(o) {
    return o.tipo === 'mensalidade' && o.competencia && !/\d{2}\/\d{2}/.test(o.descricao) ? `${o.descricao} ${competencia(o.competencia)}` : o.descricao
}

export function SeloOrdem({ o }) {
    if (o.situacao === 'paga') return <span className="selo selo-ok">Paga</span>
    if (o.situacao === 'cancelada') return <span className="selo" title={o.motivo_cancelamento || undefined}>Cancelada</span>
    if (o.tipo === 'credito') return <span className="selo selo-marca">Crédito</span>
    if (o.vencida) return <span className="selo selo-erro">Vencida</span>
    return <span className="selo selo-aviso">Em aberto</span>
}

const COBRANCA = {
    aberta: ['Em aberto', 'selo-aviso'],
    parcial: ['Parcialmente paga', 'selo-marca'],
    paga: ['Paga', 'selo-ok'],
    levada: ['Levada para a próxima', ''],
    cancelada: ['Cancelada', ''],
}
export function SeloCobranca({ situacao }) {
    const [r, c] = COBRANCA[situacao] || [situacao, '']
    return <span className={`selo ${c}`}>{r}</span>
}

/** valor com sinal: crédito aparece negativo */
export function ValorOrdem({ o }) {
    const v = o.tipo === 'credito' ? -Math.abs(o.valor) : o.valor
    return <span className={`text-nowrap ${v < 0 ? 'text-success' : ''}`}>{moeda(v)}</span>
}
