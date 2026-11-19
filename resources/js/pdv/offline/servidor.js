// Conversa crua com a API do PDV (/api/pdv). O token do aparelho fica guardado no próprio celular.
export const VERSAO = '0.7.0'

const CHAVE = 'pdv-token'

export const token = {
    get() { try { return localStorage.getItem(CHAVE) } catch { return null } },
    set(v) { try { if (v) localStorage.setItem(CHAVE, v); else localStorage.removeItem(CHAVE) } catch { /* sem armazenamento: pareia de novo */ } },
}

export class ErroApi extends Error {
    constructor(mensagem, status, extra = {}) {
        super(mensagem)
        this.status = status
        Object.assign(this, extra)
    }
}

/** sem resposta em tanto tempo, desiste (internet ruim conta como sem internet) */
const ESPERA_MS = 12000

export async function chamar(metodo, caminho, dados, { espera = ESPERA_MS } = {}) {
    const t = token.get()
    const parar = typeof AbortController !== 'undefined' ? new AbortController() : null
    const relogio = parar ? setTimeout(() => parar.abort(), espera) : null
    let r
    try {
        r = await fetch(`/api/pdv${caminho}`, {
            method: metodo,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Versao-App': VERSAO, ...(t ? { Authorization: `Bearer ${t}` } : {}) },
            body: dados ? JSON.stringify(dados) : undefined,
            signal: parar?.signal,
        })
    } catch {
        throw new ErroApi('Sem conexão com o servidor. Confira a internet do celular.', 0, { semInternet: true })
    } finally {
        if (relogio) clearTimeout(relogio)
    }
    const j = await r.json().catch(() => ({}))
    if (!r.ok) {
        // servidor fora do ar / em manutenção também conta como sem internet
        if (r.status >= 502 && r.status <= 504) throw new ErroApi('O servidor não respondeu. Tente de novo em instantes.', r.status, { semInternet: true })
        const campos = j.errors || {}
        const msg = r.status === 429 ? 'Muitas tentativas seguidas. Aguarde um minuto.'
            : j.erro || Object.values(campos)[0]?.[0] || 'Não deu certo. Tente de novo.'
        throw new ErroApi(msg, r.status, { campos, parear: !!j.parear })
    }
    return j
}
