import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import Icone from './Icone'

/**
 * Busca de cliente enquanto digita (nome, código, CPF/CNPJ ou placa).
 * Sem onEscolher: abre a ficha do cliente. Com onEscolher: devolve o cliente escolhido.
 */
export default function BuscaCliente({ onEscolher, placeholder = 'Buscar cliente (nome, código ou placa)', autoFocus, className = '', compacta = false }) {
    const [texto, setTexto] = useState('')
    const [lista, setLista] = useState([])
    const [aberta, setAberta] = useState(false)
    const [ativo, setAtivo] = useState(-1)
    const tempo = useRef(null)
    const caixa = useRef(null)

    useEffect(() => {
        clearTimeout(tempo.current)
        if (texto.trim().length < 2 && !/^\d+$/.test(texto.trim())) { setLista([]); return }
        tempo.current = setTimeout(() => {
            fetch(`/busca/clientes?q=${encodeURIComponent(texto.trim())}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then((l) => { setLista(l); setAberta(true); setAtivo(l.length ? 0 : -1) })
                .catch(() => {})
        }, 250)
        return () => clearTimeout(tempo.current)
    }, [texto])

    useEffect(() => {
        const fora = (e) => { if (caixa.current && !caixa.current.contains(e.target)) setAberta(false) }
        document.addEventListener('pointerdown', fora)
        return () => document.removeEventListener('pointerdown', fora)
    }, [])

    function escolher(c) {
        setAberta(false)
        setTexto(onEscolher ? c.nome : '')
        if (onEscolher) onEscolher(c)
        else router.visit(`/clientes/${c.id}`)
    }

    function tecla(e) {
        if (!aberta || !lista.length) return
        if (e.key === 'ArrowDown') { e.preventDefault(); setAtivo((a) => Math.min(a + 1, lista.length - 1)) }
        if (e.key === 'ArrowUp') { e.preventDefault(); setAtivo((a) => Math.max(a - 1, 0)) }
        if (e.key === 'Enter' && ativo >= 0) { e.preventDefault(); escolher(lista[ativo]) }
        if (e.key === 'Escape') setAberta(false)
    }

    return (
        <div className={`busca-cliente ${className}`} ref={caixa}>
            <Icone nome="busca" tamanho={16} className="busca-icone" />
            <input type="search" className={`form-control ${compacta ? 'busca-compacta' : ''}`} placeholder={placeholder} value={texto}
                onChange={(e) => setTexto(e.target.value)} onKeyDown={tecla} onFocus={() => lista.length && setAberta(true)}
                autoFocus={autoFocus} aria-label={placeholder} role="combobox" aria-expanded={aberta} aria-autocomplete="list" />
            {aberta && (texto.trim().length >= 2 || /^\d+$/.test(texto.trim())) && (
                <ul className="busca-lista" role="listbox">
                    {lista.length === 0 && <li className="busca-vazio">Nenhum cliente encontrado.</li>}
                    {lista.map((c, i) => (
                        <li key={c.id} role="option" aria-selected={i === ativo} className={i === ativo ? 'ativo' : undefined}
                            onPointerDown={(e) => { e.preventDefault(); escolher(c) }} onMouseEnter={() => setAtivo(i)}>
                            <strong>{c.nome}</strong>
                            <small>{c.detalhe}</small>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}
