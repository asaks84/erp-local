import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'

/** Mensagens de sucesso/erro que o servidor manda depois de salvar. */
export default function Avisos() {
    const { flash } = usePage().props
    const [fechado, setFechado] = useState(false)
    useEffect(() => setFechado(false), [flash?.sucesso, flash?.erro])
    if (fechado || (!flash?.sucesso && !flash?.erro)) return null
    return (
        <div className={`alert ${flash.erro ? 'alert-danger' : 'alert-success'} d-flex align-items-start gap-2 mt-3`} role="status">
            <div className="flex-grow-1">{flash.erro || flash.sucesso}</div>
            <button type="button" className="btn-close" aria-label="Fechar" onClick={() => setFechado(true)} />
        </div>
    )
}
