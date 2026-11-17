/**
 * Caminhos até a impressora. O ticket (bytes ESC/POS) é o mesmo em todos.
 * - Bluetooth pelo navegador: só impressoras BLE (Chrome no Android).
 * - RawBT: app intermediário instalado no celular; imprime em Bluetooth clássico, USB e rede.
 */
const SERVICOS = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
]

let lembrada = null // impressora escolhida nesta sessão (não pergunta de novo)

const esperar = (ms) => new Promise((r) => setTimeout(r, ms))

async function escrever(car, bytes, pedaco) {
    for (let i = 0; i < bytes.length; i += pedaco) {
        const parte = bytes.slice(i, i + pedaco)
        if (car.properties.writeWithoutResponse) await car.writeValueWithoutResponse(parte)
        else await car.writeValueWithResponse(parte)
        await esperar(15)
    }
}

export const temBluetooth = () => typeof navigator !== 'undefined' && !!navigator.bluetooth

export async function imprimirBluetooth(bytes, { escolherOutra = false } = {}) {
    if (!temBluetooth()) throw new Error('Este navegador não acessa Bluetooth. Use o Chrome no Android (no iPhone não funciona).')
    if (!lembrada || escolherOutra) {
        lembrada = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICOS })
    }
    const gatt = await lembrada.gatt.connect()
    try {
        let car = null
        for (const s of await gatt.getPrimaryServices()) {
            const cs = await s.getCharacteristics()
            car = cs.find((c) => c.properties.writeWithoutResponse || c.properties.write)
            if (car) break
        }
        if (!car) throw new Error('A impressora conectou, mas não aceitou dados. Ela pode não ser BLE: tente pelo RawBT.')
        try {
            await escrever(car, bytes, 120)
        } catch {
            await escrever(car, bytes, 20) // impressoras com pacote pequeno
        }
        return lembrada.name || 'impressora'
    } finally {
        setTimeout(() => { try { gatt.disconnect() } catch { /* já desconectou */ } }, 800)
    }
}

export function imprimirRawbt(bytes) {
    let bin = ''
    bytes.forEach((b) => { bin += String.fromCharCode(b) })
    window.location.href = `intent:base64,${btoa(bin)}#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;`
}
