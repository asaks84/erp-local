/*
 * Modo sem internet (8.7): o que o celular guarda e o estado da conexão.
 *
 * - pacote: a cópia que vem do servidor (GET /api/pdv/pacote): tabelas, pátio, mensalistas, quem tem PIN, /eu.
 * - fila: o que foi feito sem internet, esperando para subir (cada item com um código único).
 * - pins: o PIN de quem já usou este celular com internet, guardado só como hash feito aqui (PBKDF2).
 * Tudo no localStorage do próprio aparelho; sem armazenamento (navegação anônima), o modo sem internet não funciona.
 */

const CHAVES = { pacote: 'pdv-pacote', fila: 'pdv-fila', pins: 'pdv-pins', sal: 'pdv-pin-sal', trava: 'pdv-pin-trava', recusadas: 'pdv-fila-recusadas' }

export function ler(nome, padrao = null) {
    try {
        const v = localStorage.getItem(CHAVES[nome])
        return v ? JSON.parse(v) : padrao
    } catch {
        return padrao
    }
}

export function gravar(nome, valor) {
    try {
        if (valor === null || valor === undefined) localStorage.removeItem(CHAVES[nome])
        else localStorage.setItem(CHAVES[nome], JSON.stringify(valor))
        return true
    } catch {
        return false
    }
}

/** esquece tudo (ao desparear: o próximo aparelho/pessoa começa do zero) */
export function limparTudo() {
    Object.keys(CHAVES).forEach((k) => gravar(k, null))
}

// ---------- conexão ----------
// "sem internet" depois de uma falha de rede; volta quando uma chamada ao servidor dá certo e a fila subiu
const ouvintes = new Set()
let semRede = false

export const semInternet = () => semRede

export function marcarSemInternet(v) {
    if (semRede === v) return
    semRede = v
    avisar()
}

/** quem quer saber de mudanças (conexão, fila): devolve a função que para de ouvir */
export function ouvir(fn) {
    ouvintes.add(fn)
    return () => ouvintes.delete(fn)
}

export function avisar() {
    ouvintes.forEach((fn) => { try { fn() } catch { /* tela que já saiu */ } })
}

/** código único de cada ação (o servidor não lança duas vezes o mesmo) */
export function novoUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
    const b = new Uint8Array(16)
    crypto.getRandomValues(b)
    b[6] = (b[6] & 0x0f) | 0x40
    b[8] = (b[8] & 0x3f) | 0x80
    const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}

/** código do QR do ticket gerado no celular (16 letras/números, como o do servidor) */
export function novoCodigo() {
    const letras = 'abcdefghijklmnopqrstuvwxyz0123456789'
    const b = new Uint8Array(16)
    let c
    do {
        crypto.getRandomValues(b)
        c = [...b].map((x) => letras[x % letras.length]).join('')
    } while (/^\d+$/.test(c))
    return c
}
