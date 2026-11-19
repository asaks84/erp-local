import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react'
import { api } from './api'
import { useVoltar } from './voltar'

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

/**
 * PIN pelo teclado numérico do próprio celular (mais rápido que um teclado na tela). O campo é texto com os
 * dígitos escondidos, para o navegador não oferecer senha salva; confere no servidor ao completar 4 dígitos.
 */
export function TecladoPin({ titulo, onCancelar, onConferido }) {
    const [pin, setPin] = useState('')
    const [erro, setErro] = useState(null)
    const [conferindo, setConferindo] = useState(false)
    const campo = useRef(null)
    useVoltar(onCancelar) // voltar do celular = Cancelar
    // PIN errado: o campo volta a ficar selecionado, com o teclado aberto
    useEffect(() => { if (!conferindo) campo.current?.focus() }, [conferindo])

    const mudar = (valor) => {
        if (conferindo) return
        const novo = valor.replace(/\D/g, '').slice(0, 4)
        setErro(null)
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
            <form className="pdv-pin-caixa" onSubmit={(e) => e.preventDefault()} autoComplete="off">
                <label className="fw-semibold text-center mb-2 d-block" htmlFor="pdv-pin-campo">{titulo}</label>
                {/* as bolinhas são o que aparece; o campo de verdade fica invisível por cima delas, para o teclado do celular abrir */}
                <div className="pdv-pin-pontos">
                    {[0, 1, 2, 3].map((i) => <span key={i} className={i < pin.length ? 'cheio' : ''} />)}
                    <input ref={campo} id="pdv-pin-campo" className="pdv-pin-campo" type="text" inputMode="numeric" pattern="[0-9]*"
                        autoComplete="off" autoCorrect="off" spellCheck="false" maxLength={4} autoFocus value={pin} readOnly={conferindo}
                        onChange={(e) => mudar(e.target.value)} aria-describedby="pdv-pin-erro" />
                </div>
                <div id="pdv-pin-erro" className="pdv-pin-erro text-danger small text-center" aria-live="polite">{conferindo ? <span className="text-muted">Conferindo…</span> : erro}</div>
                <button type="button" className="btn btn-outline-secondary btn-lg w-100 mt-2" onClick={onCancelar}>Cancelar</button>
            </form>
        </div>
    )
}
