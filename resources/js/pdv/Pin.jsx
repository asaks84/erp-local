import { createContext, useCallback, useContext, useRef, useState } from 'react'
import { api } from './api'

/**
 * PIN de quem está atendendo. Toda ação pede o PIN (no Local, "a cada ação"); com memória ligada na
 * gestão, o PIN fica guardado na memória do app (nunca no armazenamento) pelos minutos escolhidos.
 */
const Ctx = createContext(null)

export function PinProvider({ memoriaMin, children }) {
    const [pedido, setPedido] = useState(null) // { titulo, resolver, rejeitar }
    const lembrado = useRef(null)              // { pin, usuario, ate }

    const pedirPin = useCallback((titulo = 'Digite seu PIN') => {
        const l = lembrado.current
        if (memoriaMin > 0 && l && l.ate > Date.now()) return Promise.resolve({ pin: l.pin, usuario: l.usuario })
        return new Promise((resolver, rejeitar) => setPedido({ titulo, resolver, rejeitar }))
    }, [memoriaMin])

    const esquecer = useCallback(() => { lembrado.current = null }, [])

    return (
        <Ctx.Provider value={{ pedirPin, esquecer }}>
            {children}
            {pedido && (
                <TecladoPin
                    titulo={pedido.titulo}
                    onCancelar={() => { pedido.rejeitar(new Error('cancelado')); setPedido(null) }}
                    onConferido={(pin, usuario) => {
                        if (memoriaMin > 0) lembrado.current = { pin, usuario, ate: Date.now() + memoriaMin * 60000 }
                        pedido.resolver({ pin, usuario })
                        setPedido(null)
                    }}
                />
            )}
        </Ctx.Provider>
    )
}

export const usePin = () => useContext(Ctx)

/** teclado numérico grande; confere o PIN no servidor ao completar 4 dígitos */
export function TecladoPin({ titulo, onCancelar, onConferido }) {
    const [pin, setPin] = useState('')
    const [erro, setErro] = useState(null)
    const [conferindo, setConferindo] = useState(false)

    const digitar = (d) => {
        if (conferindo) return
        setErro(null)
        const novo = (pin + d).slice(0, 4)
        setPin(novo)
        if (novo.length === 4) {
            setConferindo(true)
            api('POST', '/pin/conferir', { pin: novo })
                .then((j) => onConferido(novo, j.usuario))
                .catch((e) => { setErro(e.message); setPin('') })
                .finally(() => setConferindo(false))
        }
    }

    return (
        <div className="pdv-pin" role="dialog" aria-modal="true" aria-label={titulo}>
            <div className="pdv-pin-caixa">
                <div className="fw-semibold text-center mb-2">{titulo}</div>
                <div className="pdv-pin-pontos" aria-live="polite">
                    {[0, 1, 2, 3].map((i) => <span key={i} className={i < pin.length ? 'cheio' : ''} />)}
                </div>
                <div className="pdv-pin-erro text-danger small text-center">{conferindo ? <span className="text-muted">Conferindo…</span> : erro}</div>
                <div className="pdv-teclado">
                    {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => <button key={d} type="button" onClick={() => digitar(d)}>{d}</button>)}
                    <button type="button" className="pdv-tecla-texto" onClick={onCancelar}>Cancelar</button>
                    <button type="button" onClick={() => digitar('0')}>0</button>
                    <button type="button" className="pdv-tecla-texto" onClick={() => { setPin(pin.slice(0, -1)); setErro(null) }} aria-label="Apagar">⌫</button>
                </div>
            </div>
        </div>
    )
}
