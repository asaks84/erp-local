// PDV: service worker. É registrado como /pdv-sw.js?v=<CAIXA> (offline/prontidao.js): mudou aqui, suba a versão nos dois.
// Guarda o app no celular para ele abrir e funcionar sem internet (etapa 8.7).
// - a página /pdv: tenta a internet primeiro (versão nova); sem internet, abre a guardada;
// - os arquivos do app (/build, imagens, manifesto): guardados ao instalar, pela lista do Vite, e servidos daqui;
// - a API (/api) nunca é guardada: sem internet quem responde é o próprio app (fila e cópia do servidor).
const CAIXA = 'pdv-app-v4'
const PAGINA = '/pdv'
const FIXOS = [PAGINA, '/pdv.webmanifest', '/imagens/logo-local.png', '/imagens/logo-local-escuro.png', '/imagens/favicon-96.png', '/imagens/pdv-192.png']

/** lista do que o app precisa, a partir do manifesto do Vite (entrada do PDV e tudo que ela importa) */
async function listaDoApp() {
    const m = await fetch('/build/manifest.json', { cache: 'no-store' }).then((r) => r.json())
    const urls = new Set()
    const visto = new Set()
    const fila = ['resources/js/pdv/main.jsx']
    while (fila.length) {
        const k = fila.pop()
        if (visto.has(k) || !m[k]) continue
        visto.add(k)
        const x = m[k]
        urls.add(`/build/${x.file}`)
        for (const f of [...(x.css || []), ...(x.assets || [])]) urls.add(`/build/${f}`)
        for (const i of [...(x.imports || []), ...(x.dynamicImports || [])]) fila.push(i)
    }
    return [...urls]
}

let ultimoErro = null

async function guardar() {
    const caixa = await caches.open(CAIXA)
    // a página e as imagens primeiro, cada uma por conta própria (uma falha não impede as outras)
    await Promise.all(FIXOS.map((u) => caixa.add(new Request(u, { cache: 'reload' })).catch((x) => { ultimoErro = `${u}: ${x?.message || x}` })))
    let app = []
    try {
        app = await listaDoApp()
    } catch {
        return // sem a lista do Vite: os arquivos ficam guardados conforme o app usa
    }
    await Promise.all(app.map((u) => caixa.add(new Request(u, { cache: 'reload' })).catch(() => {})))
    // versões antigas dos arquivos do app saem (cada publicação muda os nomes)
    const atuais = new Set(app)
    for (const r of await caixa.keys()) {
        const p = new URL(r.url).pathname
        if (p.startsWith('/build/') && !atuais.has(p)) await caixa.delete(r)
    }
}

self.addEventListener('install', (e) => {
    e.waitUntil(guardar().catch(() => {}))
    self.skipWaiting()
})

self.addEventListener('activate', (e) => e.waitUntil((async () => {
    for (const n of await caches.keys()) if (n.startsWith('pdv-app-') && n !== CAIXA) await caches.delete(n)
    await self.clients.claim()
})()))

// o app pede para guardar de novo depois de abrir com internet (pega a versão publicada agora)
self.addEventListener('message', (e) => {
    if (e.data === 'guardar') e.waitUntil(guardar().catch(() => {}))
})

// o app pergunta se já dá para abrir sem internet: responde o que está guardado e o que falta
self.addEventListener('message', (e) => {
    if (e.data !== 'pronto?' || !e.ports?.[0]) return
    e.waitUntil((async () => {
        const r = { versao: CAIXA, pagina: !!(await caches.match(PAGINA)), arquivos: null, faltando: [], erro: ultimoErro }
        try {
            const app = (await listaDoApp()).filter((x) => /\.(js|css)$/.test(x))
            r.arquivos = app.length
            for (const u of app) if (!(await caches.match(u))) r.faltando.push(u)
        } catch (x) {
            r.erro = `lista do app: ${x?.message || x}`
        }
        e.ports[0].postMessage(r)
    })())
})

self.addEventListener('fetch', (e) => {
    const r = e.request
    if (r.method !== 'GET') return
    const u = new URL(r.url)
    if (u.origin !== self.location.origin || u.pathname.startsWith('/api/')) return

    if (r.mode === 'navigate') {
        if (!u.pathname.startsWith('/pdv')) return
        e.respondWith(fetch(r).then(async (resp) => {
            if (resp.ok) {
                const copia = resp.clone()
                caches.open(CAIXA).then((c) => c.put(PAGINA, copia))
                return resp
            }
            // servidor fora do ar (erro 5xx): abre a página guardada, como sem internet
            return resp.status >= 500 ? (await caches.match(PAGINA)) || resp : resp
        }).catch(async () => (await caches.match(PAGINA)) || Response.error()))
        return
    }

    if (u.pathname.startsWith('/build/') || u.pathname.startsWith('/imagens/') || u.pathname === '/pdv.webmanifest') {
        e.respondWith(caches.match(r, { ignoreSearch: true }).then((guardado) => guardado || fetch(r).then((resp) => {
            if (resp.ok && u.pathname.startsWith('/build/')) {
                const copia = resp.clone()
                caches.open(CAIXA).then((c) => c.put(r, copia))
            }
            return resp
        })))
    }
})
