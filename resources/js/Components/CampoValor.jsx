import { useId } from 'react'

/**
 * Valor em reais (ou %) com máscara de maquininha: sempre mostra 0,00 e cada número digitado entra pelos
 * centavos (1 → 0,01 → 0,15 → 1,50 → 15,00). Valor guardado: número.
 * opcional: zerado vira "" (campo vazio no servidor, ex.: desconto, preço por tamanho, taxa por parcela).
 */

export function paraTela(v) {
    const [i, c] = (Number(v) || 0).toFixed(2).split('.')
    return `${i.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${c}`
}

/** número a partir do que foi digitado, entrando pelos centavos */
export function deDigitos(texto, maxDigitos = 9) {
    const d = String(texto).replace(/\D/g, '').replace(/^0+/, '').slice(0, maxDigitos)
    return Number(d || 0) / 100
}

/** mantém o cursor no fim (os números entram pela direita) */
export function cursorNoFim(el) {
    requestAnimationFrame(() => { try { const n = el.value.length; el.setSelectionRange(n, n) } catch { /* campo sem seleção */ } })
}

export default function CampoValor({ rotulo, value, onChange, erro, ajuda, className = '', prefixo = 'R$', semMargem = false, opcional = false, ...props }) {
    const id = useId()

    function mudar(e) {
        const v = deDigitos(e.target.value)
        onChange(v === 0 && opcional ? '' : v)
        cursorNoFim(e.target)
    }

    return (
        <div className={`form-floating ${semMargem ? '' : 'mb-3'} ${className}`}>
            <input id={id} className={`form-control campo-valor${erro ? ' is-invalid' : ''}`} placeholder=" " inputMode="numeric" type="text"
                value={paraTela(value)} onChange={mudar} onFocus={(e) => cursorNoFim(e.target)} onClick={(e) => cursorNoFim(e.target)} autoComplete="off" {...props} />
            <label htmlFor={id}>{rotulo}{prefixo ? ` (${prefixo})` : ''}</label>
            {erro && <div className="invalid-feedback">{erro}</div>}
            {ajuda && !erro && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
