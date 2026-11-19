import { pacote } from './fila'
import { pessoasComPin } from './pins'

/**
 * Este celular já consegue abrir e trabalhar sem internet? Precisa de três coisas guardadas: o app (página e
 * arquivos, pelo service worker), a cópia do servidor (pacote) e o PIN de quem vai usar (de quando usou com internet).
 * Quando o app não está guardado, devolve o motivo (para dar para resolver).
 */
async function perguntarSw(sw) {
    return new Promise((ok) => {
        const canal = new MessageChannel()
        const relogio = setTimeout(() => ok(null), 5000)
        canal.port1.onmessage = (e) => { clearTimeout(relogio); ok(e.data) }
        sw.postMessage('pronto?', [canal.port2])
    })
}

export async function prontidao() {
    let app = false
    let motivo = null
    if (!('serviceWorker' in navigator) || !window.caches) {
        motivo = 'este navegador não guarda o app (use o Chrome; não use aba anônima)'
    } else if (!window.isSecureContext) {
        motivo = 'o endereço precisa ser https'
    } else {
        try {
            const reg = await navigator.serviceWorker.getRegistration('/pdv')
            const sw = reg?.active || navigator.serviceWorker.controller
            if (window.pdvSwErro) motivo = `não registrou: ${window.pdvSwErro}`
            else if (!reg) motivo = 'o guardador do app não foi instalado ainda'
            else if (!sw) motivo = `o guardador do app está ${reg.installing ? 'instalando' : reg.waiting ? 'esperando' : 'parado'}`
            else {
                const r = await perguntarSw(sw)
                if (!r || typeof r !== 'object') motivo = 'o guardador do app não respondeu (versão antiga: feche e abra o app)'
                else if (!r.pagina) motivo = `a página não foi guardada${r.erro ? ` (${r.erro})` : ''}`
                else if (r.faltando?.length) motivo = `faltam ${r.faltando.length} de ${r.arquivos} arquivos`
                else app = true
                if (!app && r?.versao) motivo += ` · ${r.versao}`
            }
        } catch (e) {
            motivo = e?.message || String(e)
        }
    }
    const p = pacote()
    const pins = pessoasComPin()
    return { app, motivo, pacote: p?.gerado_em ? new Date(p.gerado_em) : null, pins }
}

/*
 * Endereço do service worker com a versão: a hospedagem pode guardar o pdv-sw.js num cache próprio e entregar o
 * antigo para sempre (o celular nunca via a versão nova). Com a versão no endereço, cada versão é um arquivo novo.
 * Mudou o pdv-sw.js? Suba a versão aqui (igual à constante CAIXA de lá).
 */
export const SW_URL = '/pdv-sw.js?v=pdv-app-v4'

export function registrarSw() {
    return navigator.serviceWorker.register(SW_URL, { scope: '/pdv', updateViaCache: 'none' })
}

/** pede para o service worker guardar o app de novo (com internet) e espera terminar um pouco */
export async function prepararAgora() {
    const reg = await registrarSw()
    await reg?.update().catch(() => {})
    const sw = reg?.active || reg?.waiting || reg?.installing || navigator.serviceWorker?.controller
    sw?.postMessage('guardar')
}
