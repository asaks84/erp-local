import { useEffect, useState } from 'react'
import Campo from './Campo'

/** campo de data e hora "dd/mm/aaaa hh:mm" guardado como "aaaa-mm-dd hh:mm" */
export default function CampoDataHora({ rotulo, value, onChange, erro }) {
    const tela = (v) => (v ? `${v.slice(8, 10)}/${v.slice(5, 7)}/${v.slice(0, 4)} ${v.slice(11, 16)}` : '')
    const [texto, setTexto] = useState(tela(value))
    useEffect(() => setTexto(tela(value)), [value])
    function mudar(e) {
        const d = e.target.value.replace(/\D/g, '').slice(0, 12)
        const t = d.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2})\/(\d{2})(\d)/, '$1/$2/$3').replace(/^(\d{2}\/\d{2}\/\d{4})(\d)/, '$1 $2').replace(/(\d{2}\/\d{2}\/\d{4} \d{2})(\d)/, '$1:$2')
        setTexto(t)
        if (d.length === 12) onChange(`${d.slice(4, 8)}-${d.slice(2, 4)}-${d.slice(0, 2)} ${d.slice(8, 10)}:${d.slice(10, 12)}`)
    }
    return <Campo rotulo={`${rotulo} (dd/mm/aaaa hh:mm)`} value={texto} onChange={mudar} erro={erro} inputMode="numeric" />
}
