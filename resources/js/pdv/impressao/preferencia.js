import { imprimirBluetooth, imprimirRawbt } from './transporte'

/**
 * Como este celular imprime: o caminho que deu certo no "Teste de impressão" (Bluetooth direto, RawBT ou
 * Android) e as opções da bobina. Fica no próprio celular.
 */
const CHAVE = 'pdv-impressora'
const PADRAO = { via: null, colunas: 48, acentos: true, qrImagem: false }

export const preferencia = {
    get() {
        try { return { ...PADRAO, ...JSON.parse(localStorage.getItem(CHAVE) || '{}') } } catch { return { ...PADRAO } }
    },
    set(p) {
        try { localStorage.setItem(CHAVE, JSON.stringify({ ...this.get(), ...p })) } catch { /* sem armazenamento: pergunta de novo */ }
    },
}

export const NOMES_VIA = { bluetooth: 'Bluetooth direto', rawbt: 'app RawBT', android: 'impressão do Android' }

/** imprime pelos bytes ESC/POS; no caminho "Android", imprime a prévia (.pdv-ticket) que estiver na tela */
export async function imprimir(montar) {
    const p = preferencia.get()
    if (p.via === 'bluetooth') return imprimirBluetooth(montar(p))
    if (p.via === 'rawbt') return imprimirRawbt(montar(p))
    window.print()
    return null
}
