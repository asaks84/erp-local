import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import Campo from './Campo'
import CampoData from './CampoData'
import Icone from './Icone'
import { data as fData } from '../lib/formato'

const hoje = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function Prazo({ para }) {
    if (!para) return null
    const h = hoje()
    const [classe, texto] = para < h ? ['text-danger', `atrasada (${fData(para)})`] : para === h ? ['text-warning-emphasis', 'hoje'] : ['text-muted', fData(para)]
    return <span className={`small ${classe}`}>{texto}</span>
}

function Linha({ a }) {
    const [editando, setEditando] = useState(false)
    const form = useForm({ texto: a.texto, para: a.para ?? '' })
    const op = { preserveScroll: true, preserveState: true }

    if (editando) {
        return (
            <li className="list-group-item">
                <form onSubmit={(e) => { e.preventDefault(); form.put(`/anotacoes/${a.id}`, { ...op, onSuccess: () => setEditando(false) }) }}>
                    <Campo rotulo="Anotação" value={form.data.texto} onChange={(e) => form.setData('texto', e.target.value)} erro={form.errors.texto} autoFocus maxLength={500} />
                    <div className="d-flex gap-2">
                        <CampoData rotulo="Para quando" value={form.data.para} onChange={(v) => form.setData('para', v)} erro={form.errors.para} className="flex-grow-1" />
                        <button type="submit" className="btn btn-primary align-self-stretch mb-3" disabled={form.processing}>Salvar</button>
                        <button type="button" className="btn btn-outline-secondary align-self-stretch mb-3" onClick={() => { form.reset(); setEditando(false) }}>Cancelar</button>
                    </div>
                </form>
            </li>
        )
    }
    return (
        <li className="list-group-item d-flex align-items-start gap-2">
            <input className="form-check-input mt-1 flex-shrink-0" type="checkbox" checked={a.feita} aria-label={a.feita ? 'Marcar como não feita' : 'Marcar como feita'}
                onChange={() => router.post(`/anotacoes/${a.id}/alternar`, {}, op)} />
            <div className="flex-grow-1 text-break" style={{ minWidth: 0 }}>
                <span className={a.feita ? 'text-decoration-line-through text-muted' : ''}>{a.texto}</span>
                {!a.feita && <div><Prazo para={a.para} /></div>}
            </div>
            {!a.feita && <button type="button" className="btn btn-sm btn-link text-muted p-0" onClick={() => setEditando(true)} aria-label="Editar anotação"><Icone nome="lapis" tamanho={16} /></button>}
            <button type="button" className="btn btn-sm btn-link text-muted p-0" onClick={() => router.delete(`/anotacoes/${a.id}`, op)} aria-label="Apagar anotação"><Icone nome="lixeira" tamanho={16} /></button>
        </li>
    )
}

/** Anotações do Início: tarefas do usuário (só ele vê). */
export default function Anotacoes({ anotacoes }) {
    const form = useForm({ texto: '', para: '' })
    const [comData, setComData] = useState(false)
    const pendentes = anotacoes.filter((a) => !a.feita)
    const feitas = anotacoes.filter((a) => a.feita)
    const [verFeitas, setVerFeitas] = useState(false)

    function salvar(e) {
        e.preventDefault()
        form.post('/anotacoes', { preserveScroll: true, onSuccess: () => { form.reset(); setComData(false) } })
    }

    return (
        <div className="card h-100">
            <div className="card-header d-flex align-items-center">
                <span className="flex-grow-1">Anotações</span>
                <span className="small text-muted">{pendentes.length ? `${pendentes.length} a fazer` : 'tudo feito'}</span>
            </div>
            <div className="card-body pb-0">
                <form onSubmit={salvar}>
                    <div className="d-flex gap-2">
                        <Campo rotulo="Nova anotação (ex.: ligar para a contabilidade)" value={form.data.texto} onChange={(e) => form.setData('texto', e.target.value)} erro={form.errors.texto} maxLength={500} className="flex-grow-1" />
                        <button type="submit" className="btn btn-primary align-self-stretch mb-3" disabled={form.processing}>Anotar</button>
                    </div>
                    {comData
                        ? <CampoData rotulo="Para quando" value={form.data.para} onChange={(v) => form.setData('para', v)} erro={form.errors.para} />
                        : <button type="button" className="btn btn-link btn-sm px-0 mt-n2 mb-2" onClick={() => setComData(true)}>Colocar data</button>}
                </form>
            </div>
            <ul className="list-group list-group-flush">
                {pendentes.map((a) => <Linha key={a.id} a={a} />)}
                {pendentes.length === 0 && <li className="list-group-item text-muted small">Nada anotado para fazer.</li>}
                {feitas.length > 0 && (
                    <li className="list-group-item">
                        <button type="button" className="btn btn-link btn-sm px-0" onClick={() => setVerFeitas(!verFeitas)}>{verFeitas ? 'Esconder' : 'Ver'} as feitas ({feitas.length})</button>
                    </li>
                )}
                {verFeitas && feitas.map((a) => <Linha key={a.id} a={a} />)}
            </ul>
        </div>
    )
}
