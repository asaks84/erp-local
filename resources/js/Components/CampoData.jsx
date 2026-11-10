import { useEffect, useId, useState } from 'react'

// Campo de data dd/mm/aaaa (próprio, igual em qualquer navegador). Valor: "aaaa-mm-dd" ou "".
// Com mes=true: mm/aaaa e valor "aaaa-mm".

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

export default function CampoData({ rotulo, value, onChange, erro, ajuda, mes = false, className = '', ...props }) {
    const id = useId()
    const [texto, setTexto] = useState(paraTela(value, mes))
    const [invalido, setInvalido] = useState(false)
    useEffect(() => { setTexto(paraTela(value, mes)) }, [value, mes])

    function mudar(e) {
        const t = mascara(e.target.value, mes)
        setTexto(t)
        const v = paraValor(t, mes)
        setInvalido(t !== '' && v === null && t.replace(/\D/g, '').length === (mes ? 6 : 8))
        if (v !== null) onChange(v)
        else if (t === '') onChange('')
    }

    const msg = erro || (invalido ? 'Data inválida.' : null)
    return (
        <div className={`form-floating mb-3 ${className}`}>
            <input id={id} className={`form-control${msg ? ' is-invalid' : ''}`} placeholder=" " inputMode="numeric"
                value={texto} onChange={mudar} autoComplete="off" {...props} />
            <label htmlFor={id}>{rotulo} <span className="text-muted small">({mes ? 'mm/aaaa' : 'dd/mm/aaaa'})</span></label>
            {msg && <div className="invalid-feedback">{msg}</div>}
            {ajuda && !msg && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
