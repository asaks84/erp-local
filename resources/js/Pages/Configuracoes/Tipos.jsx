import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'

function Linha({ tipo }) {
    const [editando, setEditando] = useState(false)
    const { data, setData, put, processing, errors } = useForm({ nome: tipo.nome, detalhe: tipo.detalhe || '', ordem: tipo.ordem, ativo: tipo.ativo })
    if (!editando) {
        return (
            <tr className={tipo.ativo ? undefined : 'inativo'}>
                <td className="fw-semibold">{tipo.nome}</td>
                <td className="text-muted">{tipo.detalhe}</td>
                <td>{tipo.veiculos}</td>
                <td>{tipo.ativo ? <span className="selo selo-ok">Ativo</span> : <span className="selo">Inativo</span>}</td>
                <td className="text-end text-nowrap">
                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEditando(true)} aria-label={`Editar ${tipo.nome}`}><Icone nome="lapis" tamanho={16} /></button>
                    {tipo.veiculos === 0 && <button type="button" className="btn btn-sm btn-outline-secondary ms-1" aria-label={`Excluir ${tipo.nome}`}
                        onClick={() => router.delete(`/configuracoes/tipos/${tipo.id}`, { preserveScroll: true })}><Icone nome="lixeira" tamanho={16} /></button>}
                </td>
            </tr>
        )
    }
    return (
        <tr>
            <td><input className={`form-control form-control-sm${errors.nome ? ' is-invalid' : ''}`} value={data.nome} onChange={(e) => setData('nome', e.target.value)} aria-label="Nome" />
                {errors.nome && <div className="invalid-feedback">{errors.nome}</div>}</td>
            <td><input className="form-control form-control-sm" value={data.detalhe} onChange={(e) => setData('detalhe', e.target.value)} aria-label="Detalhe" /></td>
            <td><input className="form-control form-control-sm" type="number" min="0" style={{ width: 70 }} value={data.ordem} onChange={(e) => setData('ordem', e.target.value)} aria-label="Ordem" title="Ordem na lista" /></td>
            <td><div className="form-check form-switch"><input className="form-check-input" type="checkbox" checked={data.ativo} onChange={(e) => setData('ativo', e.target.checked)} aria-label="Ativo" /></div></td>
            <td className="text-end text-nowrap">
                <button type="button" className="btn btn-sm btn-primary" disabled={processing} onClick={() => put(`/configuracoes/tipos/${tipo.id}`, { preserveScroll: true, onSuccess: () => setEditando(false) })}>Salvar</button>
                <button type="button" className="btn btn-sm btn-outline-secondary ms-1" onClick={() => setEditando(false)}>Cancelar</button>
            </td>
        </tr>
    )
}

export default function Tipos({ tipos }) {
    const novo = useForm({ nome: '', detalhe: '', ordem: tipos.length, ativo: true })
    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <div style={{ maxWidth: 900 }}>
            <TituloPagina titulo="Tipos de veículo" descricao="Cada tipo tem seu preço nas lavagens e serviços e sua tabela de estadia. Tipo já usado não é excluído: desative." />
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle">
                        <thead><tr><th>Tipo</th><th>Detalhe</th><th>Veículos</th><th>Situação</th><th /></tr></thead>
                        <tbody>{tipos.map((t) => <Linha key={t.id} tipo={t} />)}</tbody>
                    </table>
                </div>
                <form className="card-footer bg-transparent p-3" onSubmit={(e) => { e.preventDefault(); novo.post('/configuracoes/tipos', { preserveScroll: true, onSuccess: () => novo.reset() }) }}>
                    <div className="row g-2 align-items-start">
                        <div className="col-sm-4">
                            <input className={`form-control${novo.errors.nome ? ' is-invalid' : ''}`} placeholder="Novo tipo (ex.: Autopropelido)" value={novo.data.nome} onChange={(e) => novo.setData('nome', e.target.value)} aria-label="Nome do novo tipo" />
                            {novo.errors.nome && <div className="invalid-feedback">{novo.errors.nome}</div>}
                        </div>
                        <div className="col-sm-5"><input className="form-control" placeholder="Detalhe (opcional)" value={novo.data.detalhe} onChange={(e) => novo.setData('detalhe', e.target.value)} aria-label="Detalhe" /></div>
                        <div className="col-sm-3 d-grid"><button className="btn btn-primary" disabled={novo.processing}>Adicionar</button></div>
                    </div>
                </form>
            </div>
            </div>
        </div>
    )
}
