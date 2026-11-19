import { pacote } from './fila'
import { ler } from './estado'

/**
 * Este celular já consegue abrir e trabalhar sem internet? Precisa de três coisas guardadas: o app (página e
 * arquivos, pelo service worker), a cópia do servidor (pacote) e o PIN de quem vai usar (de quando usou com internet).
 */
export async function prontidao() {
    let app = false
    try {
        const reg = await navigator.serviceWorker?.getRegistration('/pdv')
        const sw = navigator.serviceWorker?.controller || reg?.active
        if (sw) {
            app = await new Promise((ok) => {
                const canal = new MessageChannel()
                const relogio = setTimeout(() => ok(false), 4000)
                canal.port1.onmessage = (e) => { clearTimeout(relogio); ok(!!e.data) }
                sw.postMessage('pronto?', [canal.port2])
            })
        }
    } catch { app = false }
    const p = pacote()
    const pins = (ler('pins', []) || []).map((x) => x.nome)
    return { app, pacote: p?.gerado_em ? new Date(p.gerado_em) : null, pins }
}

/** pede para o service worker guardar o app de novo (com internet) */
export async function prepararAgora() {
    const reg = await navigator.serviceWorker?.register('/pdv-sw.js', { scope: '/pdv' })
    const sw = reg?.active || navigator.serviceWorker?.controller
    sw?.postMessage('guardar')
}
