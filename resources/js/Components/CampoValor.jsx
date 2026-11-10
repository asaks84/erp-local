import { useEffect, useId, useState } from 'react'

// Valor em reais digitado como no caixa: "1.234,56". Valor guardado: número (ou "" vazio).

function paraTela(v) {
    if (v === '' || v === null || v === undefined) return ''
    const [i, c] = Number(v).toFixed(2).split('.')
    return `${i.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${c}`
}

export default function CampoValor({ rotulo, value, onChange, erro, ajuda, className = '', prefixo = 'R$', semMargem = false, ...props }) {
    const id = useId()
    const [texto, setTexto] = useState(paraTela(value))
    useEffect(() => {
        // só reescreve quando o valor muda de fora (não enquanto a pessoa digita)
        const atual = texto === '' ? '' : Number(texto.replace(/\./g, '').replace(',', '.'))
        if (atual !== value && !(value === '' && texto === '')) setTexto(paraTela(value))
    }, [value]) // eslint-disable-line react-hooks/exhaustive-deps

    function mudar(e) {
        let t = e.target.value.replace(/[^\d,]/g, '')
        const partes = t.split(',')
        if (partes.length > 2) t = partes[0] + ',' + partes.slice(1).join('')
        const [inteiro, cent = undefined] = t.split(',')
        const intFmt = (inteiro || '').replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.')
        t = cent === undefined ? intFmt : `${intFmt || '0'},${cent.slice(0, 2)}`
        setTexto(t)
        onChange(t === '' ? '' : Number(t.replace(/\./g, '').replace(',', '.')))
    }

    return (
        <div className={`form-floating ${semMargem ? '' : 'mb-3'} ${className}`}>
            <input id={id} className={`form-control${erro ? ' is-invalid' : ''}`} placeholder=" " inputMode="decimal"
                value={texto} onChange={mudar} onBlur={() => setTexto(paraTela(value))} autoComplete="off" {...props} />
            <label htmlFor={id}>{rotulo}{prefixo ? ` (${prefixo})` : ''}</label>
            {erro && <div className="invalid-feedback">{erro}</div>}
            {ajuda && !erro && <div className="form-text">{ajuda}</div>}
        </div>
    )
}
