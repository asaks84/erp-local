import { useEffect, useState } from 'react'
import { ouvir, semInternet } from './estado'
import { aEnviar, rodada } from './fila'

/** estado da conexão e da fila, atualizado quando muda */
export function useRede() {
    const ler = () => ({ sem: semInternet(), fila: aEnviar() })
    const [r, setR] = useState(ler)
    useEffect(() => ouvir(() => setR(ler())), [])
    return r
}

/**
 * Faixa no alto da tela: "Sem internet · 3 para enviar" (o PDV segue funcionando para estadias) ou "Enviando…".
 * Tocar tenta enviar na hora.
 */
export default function AvisoRede() {
    const { sem, fila } = useRede()
    const [tentando, setTentando] = useState(false)
    if (!sem && !fila) return null
    const tentar = () => { setTentando(true); rodada().finally(() => setTentando(false)) }
    return (
        <button type="button" className={`pdv-rede${sem ? ' pdv-rede-sem' : ''}`} onClick={tentar} disabled={tentando} aria-live="polite">
            <span className="pdv-rede-ponto" aria-hidden="true" />
            {sem ? 'Sem internet' : 'Enviando'}
            {fila > 0 && ` · ${fila} para enviar`}
            {sem && <span className="fw-normal">{tentando ? ' · tentando…' : ' · tocar para tentar'}</span>}
        </button>
    )
}
