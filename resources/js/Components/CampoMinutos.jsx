import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import Icone from './Icone'

// Campo de minutos com seletor de tempo: o campo continua guardando minutos (dá para digitar),
// e o botão do relógio abre um seletor em horas e minutos que converte (12 h → 720 min).

const ATALHOS = [15, 30, 60, 120, 720, 1440]
const texto = (min) => (min < 60 ? `${min} min` : `${Math.floor(min / 60)}h${min % 60 ? String(min % 60).padStart(2, '0') : ''}`)

function Seletor({ valor, ancora, aoEscolher, aoFechar }) {
    const atual = Number(valor) || 0
    const [h, setH] = useState(String(Math.floor(atual / 60)))
    const [m, setM] = useState(String(atual % 60))
    const caixa = useRef(null)
    const [pos, setPos] = useState({ top: -9999, left: 0 })
    // fixo na tela, perto do campo (não fica cortado dentro de cartão ou janela); acompanha a rolagem
    const posicionar = useCallback(() => {
        if (!caixa.current || !ancora) return
        const r = ancora.getBoundingClientRect()
        const w = caixa.current.offsetWidth, hh = caixa.current.offsetHeight
        const left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8))
        const top = r.bottom + 4 + hh > window.innerHeight && r.top - 4 - hh > 0 ? r.top - 4 - hh : r.bottom + 4
        setPos({ top, left })
    }, [ancora])
    useLayoutEffect(posicionar, [posicionar])
    useEffect(() => {
        window.addEventListener('scroll', posicionar, true)
        window.addEventListener('resize', posicionar)
        return () => { window.removeEventListener('scroll', posicionar, true); window.removeEventListener('resize', posicionar) }
    }, [posicionar])
    useEffect(() => {
        const fora = (e) => { if (caixa.current && !caixa.current.contains(e.target) && !e.target.closest?.('.campo-data-botao')) aoFechar() }
        const tecla = (e) => { if (e.key === 'Escape') aoFechar() }
        document.addEventListener('pointerdown', fora)
        document.addEventListener('keydown', tecla)
        return () => { document.removeEventListener('pointerdown', fora); document.removeEventListener('keydown', tecla) }
    }, [aoFechar])

    const so = (v, max) => { const d = v.replace(/\D/g, '').slice(0, 3); return d === '' ? '' : String(Math.min(+d, max)) }
    const total = (Number(h) || 0) * 60 + (Number(m) || 0)
    const usar = (e) => { e?.preventDefault(); aoEscolher(total) }

    return (
        <div ref={caixa} className="campo-data-calendario campo-tempo" style={pos} role="dialog" aria-label="Escolher tempo">
            <div className="d-flex align-items-end gap-2">
                <label className="flex-fill">
                    <span className="small text-muted">Horas</span>
                    <input className="form-control" inputMode="numeric" value={h} autoFocus onFocus={(e) => e.target.select()}
                        onChange={(e) => setH(so(e.target.value, 999))} onKeyDown={(e) => e.key === 'Enter' && usar(e)} />
                </label>
                <span className="pb-2">:</span>
                <label className="flex-fill">
                    <span className="small text-muted">Minutos</span>
                    <input className="form-control" inputMode="numeric" value={m} onFocus={(e) => e.target.select()}
                        onChange={(e) => setM(so(e.target.value, 59))} onKeyDown={(e) => e.key === 'Enter' && usar(e)} />
                </label>
            </div>
            <div className="atalhos">
                {ATALHOS.map((a) => <button key={a} type="button" className={`btn btn-sm ${a === total ? 'btn-primary' : 'btn-light'}`} onClick={() => aoEscolher(a)}>{texto(a)}</button>)}
            </div>
            <div className="d-flex align-items-center justify-content-between mt-2">
                <span className="small text-muted">= {total} min</span>
                <button type="button" className="btn btn-sm btn-primary" onClick={usar}>Usar</button>
            </div>
        </div>
    )
}

export default function CampoMinutos({ rotulo, value, onChange, erro, ajuda, className = '', ...props }) {
    const id = useId()
    const [aberto, setAberto] = useState(false)
    const caixaCampo = useRef(null)
    const fechar = useCallback(() => setAberto(false), [])
    const escolher = (min) => { setAberto(false); onChange(min === 0 && props.min > 0 ? '' : String(min)) }

    return (
        <div ref={caixaCampo} className={`form-floating campo-data mb-3 ${className}`}>
            <input id={id} type="number" className={`form-control${erro ? ' is-invalid' : ''}`} placeholder=" "
                value={value ?? ''} onChange={(e) => onChange(e.target.value)} aria-invalid={erro ? 'true' : undefined} {...props} />
            <label htmlFor={id}>{rotulo}</label>
            {!props.disabled && !props.readOnly && (
                <button type="button" className="campo-data-botao" onClick={() => setAberto((a) => !a)} aria-label="Abrir seletor de tempo" aria-expanded={aberto} tabIndex={-1}>
                    <Icone nome="relogio" tamanho={18} />
                </button>
            )}
            {aberto && createPortal(<Seletor valor={value} ancora={caixaCampo.current} aoEscolher={escolher} aoFechar={fechar} />, document.body)}
            {erro && <div className="invalid-feedback">{erro}</div>}
            {ajuda && !erro && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
