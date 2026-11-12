/**
 * Leitura de boletos, guias e recibos de salário.
 * 1º o servidor tenta o texto do PDF (instantâneo e exato). Se o PDF é imagem (impressão,
 * digitalização) ou é uma foto, o OCR roda aqui no navegador (tesseract.js, arquivos em /ocr)
 * e o texto lido vai para o servidor interpretar. Nada é gravado: só preenche o formulário.
 */
export const INVERTIDO = '### INVERTIDO ###'

const token = () => decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')

async function enviar(url, corpo) {
    const r = await fetch(url, {
        method: 'POST', body: corpo, credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token() },
    })
    const j = await r.json().catch(() => ({}))
    if (!r.ok) throw new Error(Object.values(j.errors || {})[0]?.[0] || j.message || 'Não deu para ler o documento.')
    return j
}

/** páginas do PDF (até 4) ou a foto, em canvas com resolução boa para o OCR */
async function imagens(arquivo) {
    if (/pdf$/i.test(arquivo.type) || /\.pdf$/i.test(arquivo.name)) {
        const pdfjs = await import('pdfjs-dist')
        const { default: worker } = await import('pdfjs-dist/build/pdf.worker.min.mjs?url')
        pdfjs.GlobalWorkerOptions.workerSrc = worker
        const pdf = await pdfjs.getDocument({ data: await arquivo.arrayBuffer() }).promise
        const telas = []
        for (let i = 1; i <= Math.min(pdf.numPages, 4); i++) {
            const pagina = await pdf.getPage(i)
            const base = pagina.getViewport({ scale: 1 })
            const vp = pagina.getViewport({ scale: Math.min(4, 2400 / base.width) }) // ~2400 px de largura (≈ 290 dpi no A4)
            const c = document.createElement('canvas')
            c.width = vp.width
            c.height = vp.height
            const ctx = c.getContext('2d')
            ctx.fillStyle = '#fff'
            ctx.fillRect(0, 0, c.width, c.height)
            await pagina.render({ canvasContext: ctx, viewport: vp }).promise
            telas.push(c)
        }
        return telas
    }
    const bmp = await createImageBitmap(arquivo, { imageOrientation: 'from-image' })
    const escala = Math.min(1, 3000 / Math.max(bmp.width, bmp.height))
    const c = document.createElement('canvas')
    c.width = Math.round(bmp.width * escala)
    c.height = Math.round(bmp.height * escala)
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height)
    return [c]
}

function invertida(c) {
    const n = document.createElement('canvas')
    n.width = c.width
    n.height = c.height
    const ctx = n.getContext('2d')
    ctx.filter = 'invert(1) grayscale(1)'
    ctx.drawImage(c, 0, 0)
    return n
}

/** OCR do arquivo; aoProgredir(0..1) */
export async function ocr(arquivo, aoProgredir) {
    const telas = await imagens(arquivo)
    const { createWorker } = await import('tesseract.js')
    const raiz = new URL('/ocr/', window.location.origin).href
    let feitas = 0
    const total = telas.length + 1
    const w = await createWorker('por', 1, {
        workerPath: raiz + 'worker.min.js', corePath: raiz, langPath: raiz.replace(/\/$/, ''), gzip: true, workerBlobURL: false,
        logger: (m) => { if (m.status === 'recognizing text') aoProgredir?.(Math.min(0.99, (feitas + m.progress) / total)) },
    })
    try {
        let texto = ''
        for (const c of telas) {
            texto += (await w.recognize(c)).data.text + '\n'
            feitas++
        }
        // caixas de fundo escuro (o vencimento da guia do FGTS) só saem com as cores invertidas
        if (/FGTS/i.test(texto)) {
            texto += `\n${INVERTIDO}\n` + (await w.recognize(invertida(telas[0]))).data.text
        }
        aoProgredir?.(1)
        return texto
    } finally {
        await w.terminate()
    }
}

/**
 * Lê um arquivo pelo endereço do servidor (que interpreta). aoEtapa('texto' | 'ocr', progresso).
 * Devolve a resposta do servidor, com por_ocr = true quando precisou do OCR.
 */
export async function lerArquivo(url, arquivo, aoEtapa) {
    aoEtapa?.('texto', 0)
    const fd = new FormData()
    fd.append('arquivo', arquivo)
    const j = await enviar(url, fd)
    if (!j.precisa_ocr) return j
    aoEtapa?.('ocr', 0)
    const texto = await ocr(arquivo, (p) => aoEtapa?.('ocr', p))
    const fd2 = new FormData()
    fd2.append('texto', texto)
    return { ...(await enviar(url, fd2)), por_ocr: true }
}

/** lê um código de barras ou linha digitável colado */
export async function lerCodigo(url, codigo) {
    const fd = new FormData()
    fd.append('texto', codigo)
    return enviar(url, fd)
}
