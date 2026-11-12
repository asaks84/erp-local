import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import Icone from './Icone'

// Campo de data dd/mm/aaaa (próprio, igual em qualquer navegador). Valor: "aaaa-mm-dd" ou "".
// Com mes=true: mm/aaaa e valor "aaaa-mm". O botão do calendário abre um seletor próprio, em pt-BR
// (o do navegador sai no idioma do computador); dá para digitar ou escolher.

function paraTela(valor, mes) {
    if (!valor) return ''
    const [a, m, d] = String(valor).split('-')
    return mes ? `${m}/${a}` : `${d}/${m}/${a}`
}

function mascara(texto, mes) {
    const d = texto.replace(/\D/g, '').slice(0, mes ? 6 : 8)
    if (mes) return d.replace(/^(\d{2})(\d)/, '$1/$2')
    return d.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2})\/(\d{2})(\d)/, '$1/$2/$3')
}

function paraValor(texto, mes) {
    const d = texto.replace(/\D/g, '')
    if (mes) {
        if (d.length !== 6) return null
        const m = +d.slice(0, 2)
        return m >= 1 && m <= 12 ? `${d.slice(2)}-${d.slice(0, 2)}` : null
    }
    if (d.length !== 8) return null
    const dia = +d.slice(0, 2), m = +d.slice(2, 4), a = +d.slice(4)
    const data = new Date(a, m - 1, dia)
    return data.getFullYear() === a && data.getMonth() === m - 1 && data.getDate() === dia ? `${d.slice(4)}-${d.slice(2, 4)}-${d.slice(0, 2)}` : null
}

const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro']
const SEMANA = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S']
const dd = (n) => String(n).padStart(2, '0')
const hojeIso = () => { const d = new Date(); return `${d.getFullYear()}-${dd(d.getMonth() + 1)}-${dd(d.getDate())}` }

/** calendário (ou grade de meses, com mes=true) que abre embaixo do campo */
function Calendario({ valor, mes, ancora, aoEscolher, aoFechar }) {
    const base = valor || hojeIso()
    const [ano, setAno] = useState(+base.slice(0, 4))
    const [m, setM] = useState(+base.slice(5, 7) - 1)
    const caixa = useRef(null)
    const [pos, setPos] = useState({ top: -9999, left: 0 })
    // fixo na tela, perto do campo (não fica cortado dentro de tabela com rolagem nem de janela); acompanha a rolagem
    const posicionar = useCallback(() => {
        if (!caixa.current || !ancora) return
        const r = ancora.getBoundingClientRect()
        const w = caixa.current.offsetWidth, h = caixa.current.offsetHeight
        const left = Math.max(8, Math.min(r.right - w, window.innerWidth - w - 8))
        const top = r.bottom + 4 + h > window.innerHeight && r.top - 4 - h > 0 ? r.top - 4 - h : r.bottom + 4
        setPos({ top, left })
    }, [ancora])
    useLayoutEffect(posicionar, [posicionar, ano, m])
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
    const hoje = hojeIso()
    const mudarMes = (n) => { const d = new Date(ano, m + n, 1); setAno(d.getFullYear()); setM(d.getMonth()) }

    if (mes) {
        return (
            <div ref={caixa} className="campo-data-calendario" style={pos} role="dialog" aria-label="Escolher mês">
                <div className="cabeca">
                    <button type="button" className="btn btn-sm btn-link" onClick={() => setAno(ano - 1)} aria-label="Ano anterior">‹</button>
                    <strong>{ano}</strong>
                    <button type="button" className="btn btn-sm btn-link" onClick={() => setAno(ano + 1)} aria-label="Próximo ano">›</button>
                </div>
                <div className="meses">
                    {MESES.map((nome, i) => {
                        const v = `${ano}-${dd(i + 1)}`
                        return <button key={v} type="button" className={`btn btn-sm${v === valor ? ' btn-primary' : v === hoje.slice(0, 7) ? ' btn-outline-primary' : ' btn-light'}`} onClick={() => aoEscolher(v)}>{nome.slice(0, 3)}</button>
                    })}
                </div>
            </div>
        )
    }
    const primeiro = new Date(ano, m, 1).getDay()
    const total = new Date(ano, m + 1, 0).getDate()
    const casas = [...Array(primeiro).fill(null), ...Array.from({ length: total }, (_, i) => i + 1)]
    return (
        <div ref={caixa} className="campo-data-calendario" style={pos} role="dialog" aria-label="Escolher data">
            <div className="cabeca">
                <button type="button" className="btn btn-sm btn-link" onClick={() => mudarMes(-1)} aria-label="Mês anterior">‹</button>
                <strong>{MESES[m].charAt(0).toUpperCase() + MESES[m].slice(1)} {ano}</strong>
                <button type="button" className="btn btn-sm btn-link" onClick={() => mudarMes(1)} aria-label="Próximo mês">›</button>
            </div>
            <div className="dias">
                {SEMANA.map((s, i) => <span key={i} className="semana">{s}</span>)}
                {casas.map((d, i) => {
                    if (!d) return <span key={`v${i}`} />
                    const v = `${ano}-${dd(m + 1)}-${dd(d)}`
                    return <button key={v} type="button" className={`dia${v === valor ? ' escolhido' : ''}${v === hoje ? ' hoje' : ''}`} onClick={() => aoEscolher(v)} aria-label={`${d} de ${MESES[m]} de ${ano}`}>{d}</button>
                })}
            </div>
            <div className="rodape">
                <button type="button" className="btn btn-sm btn-link" onClick={() => aoEscolher(hoje)}>Hoje</button>
            </div>
        </div>
    )
}

export default function CampoData({ rotulo, value, onChange, erro, ajuda, mes = false, className = '', ...props }) {
    const id = useId()
    const [texto, setTexto] = useState(paraTela(value, mes))
    const [invalido, setInvalido] = useState(false)
    const [aberto, setAberto] = useState(false)
    const caixaCampo = useRef(null)
    useEffect(() => { setTexto(paraTela(value, mes)) }, [value, mes])

    function mudar(e) {
        const t = mascara(e.target.value, mes)
        setTexto(t)
        const v = paraValor(t, mes)
        setInvalido(t !== '' && v === null && t.replace(/\D/g, '').length === (mes ? 6 : 8))
        if (v !== null) onChange(v)
        else if (t === '') onChange('')
    }
    const fechar = useCallback(() => setAberto(false), [])
    const escolher = (v) => { setAberto(false); setInvalido(false); setTexto(paraTela(v, mes)); onChange(v) }

    const msg = erro || (invalido ? 'Data inválida.' : null)
    return (
        <div ref={caixaCampo} className={`form-floating campo-data mb-3 ${className}`}>
            <input id={id} className={`form-control${msg ? ' is-invalid' : ''}`} placeholder=" " inputMode="numeric"
                value={texto} onChange={mudar} autoComplete="off" {...props} />
            <label htmlFor={id}>{rotulo} <span className="text-muted small">({mes ? 'mm/aaaa' : 'dd/mm/aaaa'})</span></label>
            {!props.disabled && !props.readOnly && (
                <button type="button" className="campo-data-botao" onClick={() => setAberto((a) => !a)} aria-label={mes ? 'Abrir seletor de mês' : 'Abrir calendário'} aria-expanded={aberto} tabIndex={-1}>
                    <Icone nome="calendario" tamanho={18} />
                </button>
            )}
            {aberto && createPortal(<Calendario valor={paraValor(texto, mes) || value || ''} mes={mes} ancora={caixaCampo.current} aoEscolher={escolher} aoFechar={fechar} />, document.body)}
            {msg && <div className="invalid-feedback">{msg}</div>}
            {ajuda && !msg && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
