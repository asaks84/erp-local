import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'

/** Mensagens de sucesso/erro/aviso que o servidor manda depois de salvar. */
export default function Avisos() {
    const { flash } = usePage().props
    const [fechados, setFechados] = useState({})
    useEffect(() => setFechados({}), [flash?.sucesso, flash?.erro, flash?.aviso])
    const lista = [
        ['erro', 'alert-danger'],
        ['sucesso', 'alert-success'],
        ['aviso', 'alert-warning'],
    ].filter(([k]) => flash?.[k] && !fechados[k])
    if (!lista.length) return null
    return (
        <>
            {lista.map(([k, classe]) => (
                <div key={k} className={`alert ${classe} d-flex align-items-start gap-2 mt-3`} role="status">
                    <div className="flex-grow-1">{flash[k]}</div>
                    <button type="button" className="btn-close" aria-label="Fechar" onClick={() => setFechados((f) => ({ ...f, [k]: true }))} />
                </div>
            ))}
        </>
    )
}
