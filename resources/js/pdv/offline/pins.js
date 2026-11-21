import { gravar, ler } from './estado.js'

/*
 * PIN sem internet (8.7, revisto na auditoria de 21/11/2026). O celular nunca recebe nada que permita descobrir um PIN:
 * - a cópia do sistema traz, de quem tem PIN nesta unidade, só uma "dica" (o começo da versão do PIN feita com
 *   PBKDF2 e o sal da empresa, Pins::dica). Cada dica vale para dezenas de PINs (gerente, para centenas). Serve para
 *   pegar erro de digitação e mostrar o nome de quem está operando;
 * - o PIN digitado vai junto com cada ação da fila, trancado com a chave pública do servidor (RSA): só o servidor
 *   abre, confere o PIN de verdade e descobre quem fez. PIN errado ou de outra pessoa vira pendência para o gerente.
 * Se dois PINs caírem na mesma dica, o celular mostra os dois nomes e o servidor resolve quem foi.
 */

const ERROS_PARA_TRAVAR = 5
const TRAVA_MS = 60000

/** mesma conta do servidor (Pins::hashOffline): PBKDF2-SHA256, 32 bytes em hexadecimal */
export async function hashOffline(pin, sal, rodadas) {
    const base = await crypto.subtle.importKey('raw', new TextEncoder().encode(String(pin)), 'PBKDF2', false, ['deriveBits'])
    const bits = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: new TextEncoder().encode(sal), iterations: rodadas }, base, 256)
    return [...new Uint8Array(bits)].map((x) => x.toString(16).padStart(2, '0')).join('')
}

const b64 = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)))

/**
 * Tranca o PIN para o servidor (RSA-OAEP com SHA-1, o padrão do openssl_private_decrypt do PHP). Leva o uuid da ação:
 * a prova não serve para outra ação. Sem a chave (cópia antiga) devolve null e a ação vira pendência no servidor.
 */
export async function trancarPin(pin, uuid, chave = ler('pacote')?.pin_chave) {
    if (!chave || !crypto?.subtle) return null
    const der = Uint8Array.from(atob(chave), (c) => c.charCodeAt(0))
    const k = await crypto.subtle.importKey('spki', der, { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['encrypt'])
    return b64(await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, k, new TextEncoder().encode(JSON.stringify({ p: String(pin), u: uuid }))))
}

/** quem tem PIN conhecido sem internet (para a tela Este aparelho) */
export function pessoasComPin() {
    const nomes = new Set(((ler('pacote')?.usuarios) || []).filter((u) => u.dica).map((u) => u.nome))
    for (const p of ler('pins', []) || []) if (p.dica) nomes.add(p.nome)
    return [...nomes]
}

/** o servidor confirmou o PIN: guarda a dica dele (para quem ainda não tem dica na cópia do sistema) */
export async function lembrarPin(pin, usuario) {
    const pac = ler('pacote')
    if (!usuario?.id || !/^\d{4}$/.test(String(pin)) || !pac?.pin_sal || !crypto?.subtle) return
    try {
        const h = await hashOffline(String(pin), pac.pin_sal, pac.pin_rodadas || 100000)
        const lista = (ler('pins', []) || []).filter((p) => p.dica && p.id !== usuario.id)
        lista.push({ dica: h.slice(0, usuario.gerente ? 1 : 2), id: usuario.id, nome: usuario.nome, nivel: usuario.nivel ?? null, gerente: !!usuario.gerente, pin_desde: usuario.pin_desde ?? null })
        gravar('pins', lista)
    } catch { /* sem criptografia no navegador: só não funciona sem internet */ }
}

/** o pacote novo diz quem tem PIN: apaga quem saiu, ficou inativo ou trocou de PIN (e o formato antigo, sem dica) */
export function podarPins(usuarios) {
    if (!Array.isArray(usuarios)) return
    const vale = new Map(usuarios.map((u) => [u.id, u]))
    const lista = (ler('pins', []) || []).filter((p) => {
        const u = vale.get(p.id)
        return p.dica && u && (u.pin_desde ?? null) === (p.pin_desde ?? null)
    }).map((p) => ({ ...p, nome: vale.get(p.id).nome, gerente: !!vale.get(p.id).gerente }))
    gravar('pins', lista)
}

/** quem pode ser, pela dica (sem internet). Devolve a pessoa ou lança o erro (com a trava de 5 tentativas) */
export async function conferirPinLocal(pin) {
    const trava = ler('trava', { erros: 0, ate: 0 }) || { erros: 0, ate: 0 }
    if (trava.ate > Date.now()) throw new Error(`Muitos PINs errados. Aguarde ${Math.ceil((trava.ate - Date.now()) / 1000)} segundos.`)
    const valido = /^\d{4}$/.test(String(pin))
    const pac = ler('pacote')
    const daCopia = (pac?.usuarios || []).filter((u) => u.dica)
    const ids = new Set(daCopia.map((u) => u.id))
    const conhecidos = [...daCopia, ...(ler('pins', []) || []).filter((p) => p.dica && !ids.has(p.id))]
    let achados = []
    if (valido && conhecidos.length && pac?.pin_sal && crypto?.subtle) {
        const h = await hashOffline(String(pin), pac.pin_sal, pac.pin_rodadas || 100000)
        achados = conhecidos.filter((x) => h.startsWith(x.dica))
    }
    if (!achados.length) {
        const erros = trava.erros + 1
        const travou = erros >= ERROS_PARA_TRAVAR
        gravar('trava', { erros: travou ? 0 : erros, ate: travou ? Date.now() + TRAVA_MS : 0 })
        throw new Error(travou ? 'Muitos PINs errados. O aparelho ficou travado por 1 minuto.'
            : conhecidos.length ? 'PIN incorreto.'
                : 'Este celular ainda não conhece nenhum PIN. Abra o PDV com internet uma vez.')
    }
    if (trava.erros) gravar('trava', { erros: 0, ate: 0 })
    if (achados.length > 1) {
        // a mesma dica serve para mais de uma pessoa: o servidor descobre quem foi pelo PIN trancado
        return { id: null, nome: achados.map((x) => x.nome).join(' ou '), nivel: null, gerente: false, pin_desde: null }
    }
    const u = achados[0]
    return { id: u.id, nome: u.nome, nivel: u.nivel ?? null, gerente: !!u.gerente, pin_desde: u.pin_desde ?? null }
}
