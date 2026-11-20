// Chamadas JSON às rotas da gestão (sessão + XSRF), para telas que trabalham sem recarregar (ex.: emissão de notas
// em lote, uma de cada vez). Erro de validação (422) volta como { situacao: 'erro', mensagem }.

const token = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')

export async function postarJson(url, dados) {
    try {
        const r = await fetch(url, {
            method: 'POST', credentials: 'same-origin', body: JSON.stringify(dados),
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token() },
        })
        const j = await r.json().catch(() => ({}))
        if (!r.ok) return { situacao: 'erro', mensagem: j.mensagem || j.message || `Erro ${r.status}` }
        return j
    } catch {
        return { situacao: 'erro', mensagem: 'Sem conexão com o sistema.' }
    }
}

/** mostra o popup de aviso do topo (ok, aviso, erro) sem precisar de visita */
export function avisar(tipo, texto) {
    window.dispatchEvent(new CustomEvent('aviso-local', { detail: { tipo, texto } }))
}
