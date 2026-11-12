import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPagar from '../../Components/AbasPagar'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import Icone from '../../Components/Icone'
import { telefone } from '../../lib/mascaras'

const VAZIO = { nome: '', documento: '', telefone: '', email: '', pix: '', conta_padrao_id: '', observacao: '', ativo: true }

export default function Fornecedores({ fornecedores, contas }) {
    const [editando, setEditando] = useState(null) // null fechado, {} novo, {id..} edição
    const form = useForm(VAZIO)
    const abrir = (f) => { form.clearErrors(); form.setData(f ? { ...VAZIO, ...Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v ?? ''])) } : VAZIO); setEditando(f || {}) }
    const salvar = () => {
        const op = { preserveScroll: true, onSuccess: () => setEditando(null) }
        editando.id ? form.put(`/fornecedores/${editando.id}`, op) : form.post('/fornecedores', op)
    }
    const { data, setData, errors } = form

    return (
        <div className="pagina">
            <AbasPagar />
            <TituloPagina titulo="Fornecedores" descricao="Quem a empresa paga. A conta padrão já vem escolhida quando você lança uma conta desse fornecedor.">
                <button type="button" className="btn btn-primary d-inline-flex align-items-center gap-2" onClick={() => abrir(null)}><Icone nome="mais" /> Novo fornecedor</button>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Nome</th><th className="d-none d-md-table-cell">Contato</th><th className="d-none d-md-table-cell">Conta padrão</th><th /></tr></thead>
                        <tbody>
                            {fornecedores.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum fornecedor ainda.</td></tr>}
                            {fornecedores.map((f) => (
                                <tr key={f.id} className={f.ativo ? '' : 'inativo'}>
                                    <td><div className="fw-semibold">{f.nome}</div>{!f.ativo && <span className="selo">Inativo</span>}{f.pix && <div className="small text-muted">Pix: {f.pix}</div>}</td>
                                    <td className="d-none d-md-table-cell small">{[f.telefone && telefone(f.telefone), f.email].filter(Boolean).join(' · ')}</td>
                                    <td className="d-none d-md-table-cell small text-muted">{f.conta_padrao_id ? f.conta : ''}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrir(f)} aria-label={`Editar ${f.nome}`}><Icone nome="lapis" tamanho={16} /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Janela aberta={!!editando} titulo={editando?.id ? 'Alterar fornecedor' : 'Novo fornecedor'} ocupado={form.processing} onFechar={() => setEditando(null)} onEnviar={salvar}>
                <Campo rotulo="Nome" value={data.nome} onChange={(e) => setData('nome', e.target.value)} erro={errors.nome} maxLength={100} autoFocus style={{ textTransform: 'uppercase' }} />
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="CPF ou CNPJ (opcional)" value={data.documento} onChange={(e) => setData('documento', e.target.value)} erro={errors.documento} inputMode="numeric" /></div>
                    <div className="col-sm-6"><Campo rotulo="Telefone" value={telefone(data.telefone)} onChange={(e) => setData('telefone', e.target.value)} erro={errors.telefone} inputMode="tel" /></div>
                </div>
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="E-mail" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} erro={errors.email} /></div>
                    <div className="col-sm-6"><Campo rotulo="Chave Pix" value={data.pix} onChange={(e) => setData('pix', e.target.value)} erro={errors.pix} /></div>
                </div>
                <Campo as="select" rotulo="Conta padrão (plano de contas)" value={data.conta_padrao_id} onChange={(e) => setData('conta_padrao_id', e.target.value)} erro={errors.conta_padrao_id}>
                    <option value="">Nenhuma</option>
                    {contas.map((c) => <option key={c.id} value={c.id}>{c.rotulo}</option>)}
                </Campo>
                <Campo rotulo="Observação" value={data.observacao} onChange={(e) => setData('observacao', e.target.value)} erro={errors.observacao} maxLength={255} />
                {editando?.id && (
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" id="forn-ativo" checked={!!data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="forn-ativo">Ativo</label>
                    </div>
                )}
            </Janela>
        </div>
    )
}
