// Formatos em pt-BR feitos pelo próprio sistema (não dependem do idioma do navegador).

const DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado']
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']
const MESES_CURTOS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']

const doisDigitos = (n) => String(n).padStart(2, '0')

/** "2026-11-09 14:05:00" ou Date → Date (a hora vem do servidor já em São Paulo) */
export function paraData(valor) {
    if (!valor) return null
    if (valor instanceof Date) return valor
    const m = String(valor).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/)
    if (!m) return null
    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0))
}

/** 09/11/2026 */
export function data(valor) {
    const d = paraData(valor)
    return d ? `${doisDigitos(d.getDate())}/${doisDigitos(d.getMonth() + 1)}/${d.getFullYear()}` : ''
}

/** 09/11/2026 14:05 */
export function dataHora(valor) {
    const d = paraData(valor)
    return d ? `${data(d)} ${doisDigitos(d.getHours())}:${doisDigitos(d.getMinutes())}` : ''
}

/** Segunda, 9 nov 2026 */
export function dataExtensa(valor = new Date()) {
    const d = paraData(valor)
    return `${DIAS[d.getDay()]}, ${d.getDate()} ${MESES_CURTOS[d.getMonth()]} ${d.getFullYear()}`
}

/** Novembro/2026 */
export function mesAno(valor) {
    const d = paraData(valor)
    if (!d) return ''
    const mes = MESES[d.getMonth()]
    return `${mes.charAt(0).toUpperCase()}${mes.slice(1)}/${d.getFullYear()}`
}

/** R$ 1.234,56 */
export function moeda(valor) {
    const n = Number(valor || 0)
    const negativo = n < 0
    const [inteiro, centavos] = Math.abs(n).toFixed(2).split('.')
    const comPontos = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
    return `${negativo ? '-' : ''}R$ ${comPontos},${centavos}`
}

/** iniciais para o avatar: "Cauê Lopes" → "CL" */
export function iniciais(nome) {
    const partes = String(nome || '').trim().split(/\s+/).filter(Boolean)
    if (!partes.length) return '?'
    const primeira = partes[0].charAt(0)
    const ultima = partes.length > 1 ? partes[partes.length - 1].charAt(0) : ''
    return (primeira + ultima).toUpperCase()
}
