import { gravar, ler } from './estado'

/*
 * PIN sem internet (8.7). O servidor nunca manda PIN para o celular. Quando alguém digita o PIN com internet e o
 * servidor confirma, o celular guarda um hash feito aqui (PBKDF2 com um sal deste aparelho) ligado à pessoa.
 * Sem internet, o PIN digitado é conferido contra esses hashes. Quem nunca usou este celular com internet não
 * entra sem internet.
 *
 * Limite honesto: um PIN tem só 10 mil combinações. Quem pegar o celular desbloqueado e souber mexer nas
 * ferramentas do navegador consegue descobrir os PINs guardados (o PBKDF2 só deixa isso lento). Por isso o pacote
 * avisa quem saiu ou trocou de PIN (o hash antigo é apagado) e o celular trava depois de 5 erros, como no servidor.
 */

const ITERACOES = 60000
const ERROS_PARA_TRAVAR = 5
const TRAVA_MS = 60000

function sal() {
    let s = ler('sal')
    if (!s) {
        const b = new Uint8Array(16)
        crypto.getRandomValues(b)
        s = [...b].map((x) => x.toString(16).padStart(2, '0')).join('')
        gravar('sal', s)
    }
    return s
}

async function hash(pin) {
    if (!crypto?.subtle) throw new Error('Este navegador não confere PIN sem internet.')
    const base = await crypto.subtle.importKey('raw', new TextEncoder().encode(pin), 'PBKDF2', false, ['deriveBits'])
    const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: new TextEncoder().encode(sal()), iterations: ITERACOES }, base, 256)
    return [...new Uint8Array(bits)].map((x) => x.toString(16).padStart(2, '0')).join('')
}

/** o servidor confirmou o PIN: guarda para conferir sem internet (só o hash) */
export async function lembrarPin(pin, usuario) {
    if (!usuario?.id || !/^\d{4}$/.test(String(pin))) return
    try {
        const h = await hash(String(pin))
        const lista = (ler('pins', []) || []).filter((p) => p.id !== usuario.id && p.h !== h)
        lista.push({ h, id: usuario.id, nome: usuario.nome, nivel: usuario.nivel ?? null, gerente: !!usuario.gerente, pin_desde: usuario.pin_desde ?? null })
        gravar('pins', lista)
    } catch { /* sem criptografia no navegador: só não funciona sem internet */ }
}

/** o pacote novo diz quem tem PIN: apaga quem saiu, ficou inativo ou trocou de PIN */
export function podarPins(usuarios) {
    if (!Array.isArray(usuarios)) return
    const vale = new Map(usuarios.map((u) => [u.id, u]))
    const lista = (ler('pins', []) || []).filter((p) => {
        const u = vale.get(p.id)
        return u && (u.pin_desde ?? null) === (p.pin_desde ?? null)
    }).map((p) => ({ ...p, nome: vale.get(p.id).nome, gerente: !!vale.get(p.id).gerente }))
    gravar('pins', lista)
}

/** confere o PIN sem internet: devolve a pessoa ou lança o erro (com a trava de 5 tentativas) */
export async function conferirPinLocal(pin) {
    const trava = ler('trava', { erros: 0, ate: 0 }) || { erros: 0, ate: 0 }
    if (trava.ate > Date.now()) throw new Error(`Muitos PINs errados. Aguarde ${Math.ceil((trava.ate - Date.now()) / 1000)} segundos.`)
    const lista = ler('pins', []) || []
    const h = /^\d{4}$/.test(String(pin)) ? await hash(String(pin)) : null
    const p = h && lista.find((x) => x.h === h)
    if (!p) {
        const erros = trava.erros + 1
        const travou = erros >= ERROS_PARA_TRAVAR
        gravar('trava', { erros: travou ? 0 : erros, ate: travou ? Date.now() + TRAVA_MS : 0 })
        throw new Error(travou ? 'Muitos PINs errados. O aparelho ficou travado por 1 minuto.'
            : lista.length ? 'PIN incorreto (sem internet, só vale o PIN de quem já usou este celular com internet).'
                : 'Sem internet ninguém usou o PIN neste celular ainda. Use o PIN uma vez com internet.')
    }
    if (trava.erros) gravar('trava', { erros: 0, ate: 0 })
    return { id: p.id, nome: p.nome, nivel: p.nivel, gerente: p.gerente, pin_desde: p.pin_desde }
}
