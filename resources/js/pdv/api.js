// Conversa com a API do PDV (/api/pdv). O token do aparelho fica guardado no próprio celular.
export const VERSAO = '0.4.0'

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

export async function api(metodo, caminho, dados) {
    const t = token.get()
    let r
    try {
        r = await fetch(`/api/pdv${caminho}`, {
            method: metodo,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Versao-App': VERSAO, ...(t ? { Authorization: `Bearer ${t}` } : {}) },
            body: dados ? JSON.stringify(dados) : undefined,
        })
    } catch {
        throw new ErroApi('Sem conexão com o servidor. Confira a internet do celular.', 0, { semInternet: true })
    }
    const j = await r.json().catch(() => ({}))
    if (!r.ok) {
        const campos = j.errors || {}
        const msg = r.status === 429 ? 'Muitas tentativas seguidas. Aguarde um minuto.'
            : j.erro || Object.values(campos)[0]?.[0] || 'Não deu certo. Tente de novo.'
        throw new ErroApi(msg, r.status, { campos, parear: !!j.parear })
    }
    return j
}
