import { gravar, ler } from './estado.js'

/*
 * PIN sem internet (8.7). O servidor nunca manda o PIN. Duas fontes, nesta ordem:
 * 1. a cópia do sistema traz, de quem tem PIN nesta unidade, uma versão do PIN feita no servidor com PBKDF2 e o sal
 *    da empresa (Pins::hashOffline): PIN novo ou trocado vale sem internet na próxima cópia;
 * 2. quem usou o PIN neste celular com internet fica guardado aqui também (PBKDF2 com o sal do aparelho), para quem
 *    ainda não tem a versão do servidor (PIN de antes dela, até o primeiro uso com internet).
 *
 * Limite honesto: um PIN tem só 10 mil combinações. Quem pegar o celular desbloqueado e souber mexer nas
 * ferramentas do navegador consegue descobrir os PINs da unidade (o PBKDF2 só deixa isso lento). Por isso a cópia
 * tira quem saiu ou trocou de PIN e o celular trava depois de 5 erros, como no servidor.
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

/** mesma conta do servidor (Pins::hashOffline): PBKDF2-SHA256, 32 bytes em hexadecimal */
export async function hashOffline(pin, sal, rodadas) {
    const base = await crypto.subtle.importKey('raw', new TextEncoder().encode(String(pin)), 'PBKDF2', false, ['deriveBits'])
    const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: new TextEncoder().encode(sal), iterations: rodadas }, base, 256)
    return [...new Uint8Array(bits)].map((x) => x.toString(16).padStart(2, '0')).join('')
}

/** quem tem PIN conhecido sem internet (para a tela Este aparelho) */
export function pessoasComPin() {
    const nomes = new Set(((ler('pacote')?.usuarios) || []).filter((u) => u.h).map((u) => u.nome))
    for (const p of ler('pins', []) || []) nomes.add(p.nome)
    return [...nomes]
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
    const valido = /^\d{4}$/.test(String(pin))
    // 1) versão do servidor, na cópia do sistema
    const pac = ler('pacote')
    const daCopia = (pac?.usuarios || []).filter((u) => u.h)
    if (valido && daCopia.length && pac.pin_sal && crypto?.subtle) {
        const h = await hashOffline(String(pin), pac.pin_sal, pac.pin_rodadas || 100000)
        const u = daCopia.find((x) => x.h === h)
        if (u) {
            if (trava.erros) gravar('trava', { erros: 0, ate: 0 })
            return { id: u.id, nome: u.nome, nivel: u.nivel ?? null, gerente: !!u.gerente, pin_desde: u.pin_desde ?? null }
        }
    }
    // 2) quem usou o PIN neste celular com internet
    const lista = ler('pins', []) || []
    const h = valido && lista.length ? await hash(String(pin)) : null
    const p = h && lista.find((x) => x.h === h)
    if (!p) {
        const erros = trava.erros + 1
        const travou = erros >= ERROS_PARA_TRAVAR
        gravar('trava', { erros: travou ? 0 : erros, ate: travou ? Date.now() + TRAVA_MS : 0 })
        throw new Error(travou ? 'Muitos PINs errados. O aparelho ficou travado por 1 minuto.'
            : lista.length || daCopia.length ? 'PIN incorreto.'
                : 'Este celular ainda não conhece nenhum PIN. Abra o PDV com internet uma vez.')
    }
    if (trava.erros) gravar('trava', { erros: 0, ate: 0 })
    return { id: p.id, nome: p.nome, nivel: p.nivel, gerente: p.gerente, pin_desde: p.pin_desde }
}
