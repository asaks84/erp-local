import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'

function Linha({ e, primeira, ultima }) {
    const [editando, setEditando] = useState(false)
    const form = useForm({ nome: e.nome, ativa: e.ativa })
    const op = { preserveScroll: true }
    const mover = (direcao) => router.post(`/configuracoes/etapas/${e.id}/mover`, { direcao }, op)

    return (
        <li className={`list-group-item d-flex align-items-center gap-2${e.ativa ? '' : ' inativo'}`}>
            {!e.fixa ? (
                <div className="d-flex flex-column">
                    <button type="button" className="btn btn-sm btn-link p-0 lh-1" disabled={primeira} onClick={() => mover('subir')} aria-label={`Subir ${e.nome}`}>▲</button>
                    <button type="button" className="btn btn-sm btn-link p-0 lh-1" disabled={ultima} onClick={() => mover('descer')} aria-label={`Descer ${e.nome}`}>▼</button>
                </div>
            ) : <span style={{ width: 14 }} />}
            {editando ? (
                <form className="d-flex flex-grow-1 gap-2 align-items-center flex-wrap" onSubmit={(ev) => { ev.preventDefault(); form.put(`/configuracoes/etapas/${e.id}`, { ...op, onSuccess: () => setEditando(false) }) }}>
                    <div className="flex-grow-1">
                        <input className={`form-control form-control-sm${form.errors.nome ? ' is-invalid' : ''}`} value={form.data.nome} onChange={(ev) => form.setData('nome', ev.target.value)} aria-label="Nome da etapa" autoFocus maxLength={40} />
                        {form.errors.nome && <div className="invalid-feedback">{form.errors.nome}</div>}
                    </div>
                    {!e.fixa && <div className="form-check form-switch mb-0"><input className="form-check-input" type="checkbox" id={`at-${e.id}`} checked={form.data.ativa} onChange={(ev) => form.setData('ativa', ev.target.checked)} /><label className="form-check-label small" htmlFor={`at-${e.id}`}>Ativa</label></div>}
                    <button type="submit" className="btn btn-sm btn-primary" disabled={form.processing}>Salvar</button>
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => { form.reset(); setEditando(false) }}>Cancelar</button>
                </form>
            ) : (
                <>
                    <div className="flex-grow-1">
                        <span className="fw-semibold">{e.nome}</span>
                        {e.fixa && <span className="small text-muted ms-2">{e.chave === 'entregue' ? 'sempre a última' : 'sempre a primeira (entrada)'}</span>}
                    </div>
                    {e.ativa ? <span className="selo selo-ok">Registrada</span> : <span className="selo">Não registrada</span>}
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEditando(true)} aria-label={`Editar ${e.nome}`}><Icone nome="lapis" tamanho={16} /></button>
                    {!e.fixa && !e.usada && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.delete(`/configuracoes/etapas/${e.id}`, op)} aria-label={`Apagar ${e.nome}`}><Icone nome="lixeira" tamanho={16} /></button>}
                </>
            )}
        </li>
    )
}

export default function Etapas({ etapas }) {
    const novo = useForm({ nome: '' })
    const meio = etapas.filter((e) => !e.fixa)
    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <div style={{ maxWidth: 760 }}>
                <TituloPagina titulo="Etapas da lavagem" descricao="O que é registrado no quadro de lavagens, em ordem. Cada mudança de etapa fica no histórico com quem registrou. Desative o que você não quer registrar." />
                <div className="card">
                    <ul className="list-group list-group-flush">
                        {etapas.map((e) => <Linha key={e.id} e={e} primeira={meio[0]?.id === e.id} ultima={meio[meio.length - 1]?.id === e.id} />)}
                    </ul>
                    <form className="card-footer bg-transparent p-3" onSubmit={(ev) => { ev.preventDefault(); novo.post('/configuracoes/etapas', { preserveScroll: true, onSuccess: () => novo.reset() }) }}>
                        <div className="d-flex gap-2 align-items-start">
                            <div className="flex-grow-1">
                                <input className={`form-control${novo.errors.nome ? ' is-invalid' : ''}`} placeholder="Nova etapa (ex.: Polimento)" value={novo.data.nome} onChange={(ev) => novo.setData('nome', ev.target.value)} aria-label="Nome da nova etapa" maxLength={40} />
                                {novo.errors.nome && <div className="invalid-feedback">{novo.errors.nome}</div>}
                            </div>
                            <button type="submit" className="btn btn-primary" disabled={novo.processing}>Adicionar</button>
                        </div>
                        <div className="form-text">A nova etapa entra antes de Entregue; use as setas para mudar a ordem.</div>
                    </form>
                </div>
            </div>
        </div>
    )
}
