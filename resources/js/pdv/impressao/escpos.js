import QRCode from 'qrcode'

/**
 * Comandos ESC/POS (o "idioma" das impressoras térmicas). O ticket é sempre montado aqui;
 * só o caminho até a impressora muda (Bluetooth, app intermediário, rede). Ver docs/07-pdv.md.
 * Acentos pela tabela PC860 (português); sem acento, se a impressora não tiver essa tabela.
 */
const PC860 = {
    'Ç': 0x80, 'ü': 0x81, 'é': 0x82, 'â': 0x83, 'ã': 0x84, 'à': 0x85, 'Á': 0x86, 'ç': 0x87, 'ê': 0x88, 'Ê': 0x89, 'è': 0x8a, 'Í': 0x8b,
    'Ô': 0x8c, 'ì': 0x8d, 'Ã': 0x8e, 'Â': 0x8f, 'É': 0x90, 'À': 0x91, 'È': 0x92, 'ô': 0x93, 'õ': 0x94, 'ò': 0x95, 'Ú': 0x96, 'ù': 0x97,
    'Ì': 0x98, 'Õ': 0x99, 'Ü': 0x9a, 'Ù': 0x9d, 'Ó': 0x9f, 'á': 0xa0, 'í': 0xa1, 'ó': 0xa2, 'ú': 0xa3, 'ñ': 0xa4, 'Ñ': 0xa5, 'ª': 0xa6, 'º': 0xa7,
}
const ESC = 0x1b
const GS = 0x1d

export const semAcento = (t) => t.normalize('NFD').replace(/[̀-ͯ]/g, '')

export class Ticket {
    constructor({ colunas = 48, acentos = true, qrImagem = false } = {}) {
        this.colunas = colunas
        this.acentos = acentos
        this.qrImagem = qrImagem
        this.bytes = []
        this.cmd(ESC, 0x40) // inicia
        if (acentos) this.cmd(ESC, 0x74, 3) // tabela PC860
    }

    cmd(...b) { this.bytes.push(...b); return this }

    texto(t) {
        for (const ch of String(t)) {
            const c = ch.charCodeAt(0)
            if (c < 0x80) this.bytes.push(c)
            else if (this.acentos && PC860[ch] !== undefined) this.bytes.push(PC860[ch])
            else { const s = semAcento(ch); for (const x of s) if (x.charCodeAt(0) < 0x80) this.bytes.push(x.charCodeAt(0)) }
        }
        return this
    }

    linha(t = '') { return this.texto(t).cmd(0x0a) }
    alinhar(a) { return this.cmd(ESC, 0x61, { esquerda: 0, centro: 1, direita: 2 }[a] ?? 0) }
    negrito(sim = true) { return this.cmd(ESC, 0x45, sim ? 1 : 0) }
    tamanho(largura = 1, altura = 1) { return this.cmd(GS, 0x21, ((largura - 1) << 4) | (altura - 1)) }
    traco(ch = '-') { return this.linha(ch.repeat(this.colunas)) }
    avancar(n = 3) { return this.cmd(ESC, 0x64, n) }
    cortar() { return this.cmd(GS, 0x56, 0x42, 0x03) }

    /** duas colunas: rótulo à esquerda, valor à direita */
    par(rotulo, valor) {
        const v = String(valor)
        const espaco = Math.max(1, this.colunas - rotulo.length - v.length)
        return this.linha(rotulo + ' '.repeat(espaco) + v)
    }

    /** caixa em branco (ex.: espaço para o carimbo do convênio) */
    caixa(linhas = 4) {
        const largura = this.colunas
        this.linha('+' + '-'.repeat(largura - 2) + '+')
        for (let i = 0; i < linhas; i++) this.linha('|' + ' '.repeat(largura - 2) + '|')
        return this.linha('+' + '-'.repeat(largura - 2) + '+')
    }

    /** QR Code: comando nativo da impressora (GS ( k) ou, se ela não tiver, como imagem */
    qr(conteudo, modulo = 6) {
        if (this.qrImagem) return this.qrComoImagem(conteudo, modulo)
        const dados = Array.from(new TextEncoder().encode(conteudo))
        const n = dados.length + 3
        this.cmd(GS, 0x28, 0x6b, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00)          // modelo 2
        this.cmd(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x43, modulo)              // tamanho do módulo
        this.cmd(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x45, 0x31)                // correção de erro M
        this.cmd(GS, 0x28, 0x6b, n & 0xff, (n >> 8) & 0xff, 0x31, 0x50, 0x30, ...dados)
        return this.cmd(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x51, 0x30)         // imprime
    }

    qrComoImagem(conteudo, escala = 6) {
        const q = QRCode.create(conteudo, { errorCorrectionLevel: 'M' })
        const n = q.modules.size
        const margem = 2
        const lado = (n + margem * 2) * escala
        const largura = Math.ceil(lado / 8)
        const dados = []
        for (let y = 0; y < lado; y++) {
            for (let bx = 0; bx < largura; bx++) {
                let byte = 0
                for (let bit = 0; bit < 8; bit++) {
                    const x = bx * 8 + bit
                    const mx = Math.floor(x / escala) - margem
                    const my = Math.floor(y / escala) - margem
                    const preto = mx >= 0 && my >= 0 && mx < n && my < n && q.modules.get(my, mx)
                    if (preto) byte |= 0x80 >> bit
                }
                dados.push(byte)
            }
        }
        return this.cmd(GS, 0x76, 0x30, 0x00, largura & 0xff, (largura >> 8) & 0xff, lado & 0xff, (lado >> 8) & 0xff, ...dados).cmd(0x0a)
    }

    pronto() { return new Uint8Array(this.bytes) }
}
