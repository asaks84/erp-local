import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasFuncionarios from '../../Components/AbasFuncionarios'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoValor from '../../Components/CampoValor'
import Icone from '../../Components/Icone'
import { telefone } from '../../lib/mascaras'
import { moeda } from '../../lib/formato'

const VAZIO = { nome: '', tipo: 'registrado', funcao: '', matricula: '', telefone: '', pix: '', valor_diaria: '', observacao: '', ativo: true }

export default function Funcionarios({ funcionarios, tipos }) {
    const [editando, setEditando] = useState(null)
    const form = useForm(VAZIO)
    const { data, setData, errors } = form
    const abrir = (f) => { form.clearErrors(); form.setData(f ? { ...VAZIO, ...Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v ?? ''])) } : VAZIO); setEditando(f || {}) }
    const salvar = () => {
        const op = { preserveScroll: true, onSuccess: () => setEditando(null) }
        editando.id ? form.put(`/funcionarios/${editando.id}`, op) : form.post('/funcionarios', op)
    }

    return (
        <div className="pagina">
            <AbasFuncionarios />
            <TituloPagina titulo="Funcionários" descricao="Registrados recebem pelo recibo de salário do contador; avulsos, por diária.">
                <button type="button" className="btn btn-primary d-inline-flex align-items-center gap-2" onClick={() => abrir(null)}><Icone nome="mais" /> Novo funcionário</button>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Nome</th><th>Tipo</th><th className="d-none d-md-table-cell">Contato</th><th /></tr></thead>
                        <tbody>
                            {funcionarios.length === 0 && <tr><td colSpan="4" className="text-center text-muted py-4">Nenhum funcionário ainda. Os registrados também são cadastrados ao importar o recibo de salário.</td></tr>}
                            {funcionarios.map((f) => (
                                <tr key={f.id} className={f.ativo ? '' : 'inativo'}>
                                    <td>
                                        <div className="fw-semibold">{f.nome}</div>
                                        <div className="small text-muted">{[f.funcao, f.matricula && `matrícula ${f.matricula}`].filter(Boolean).join(' · ')}</div>
                                        {!f.ativo && <span className="selo">Inativo</span>}
                                    </td>
                                    <td className="small">{f.tipo === 'avulso' ? <>Avulso{f.valor_diaria ? <div className="text-muted">{moeda(f.valor_diaria)} / dia</div> : null}</> : 'Registrado'}</td>
                                    <td className="d-none d-md-table-cell small">{[f.telefone && telefone(f.telefone), f.pix && `Pix: ${f.pix}`].filter(Boolean).join(' · ')}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrir(f)} aria-label={`Editar ${f.nome}`}><Icone nome="lapis" tamanho={16} /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Janela aberta={!!editando} titulo={editando?.id ? 'Alterar funcionário' : 'Novo funcionário'} ocupado={form.processing} onFechar={() => setEditando(null)} onEnviar={salvar}>
                <Campo rotulo="Nome" value={data.nome} onChange={(e) => setData('nome', e.target.value)} erro={errors.nome} maxLength={100} autoFocus style={{ textTransform: 'uppercase' }} />
                <div className="row g-2">
                    <div className="col-sm-6">
                        <Campo as="select" rotulo="Tipo" value={data.tipo} onChange={(e) => setData('tipo', e.target.value)} erro={errors.tipo}>
                            {Object.entries(tipos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                        </Campo>
                    </div>
                    <div className="col-sm-6"><Campo rotulo="Função" value={data.funcao} onChange={(e) => setData('funcao', e.target.value)} erro={errors.funcao} maxLength={60} /></div>
                </div>
                <div className="row g-2">
                    {data.tipo === 'registrado'
                        ? <div className="col-sm-6"><Campo rotulo="Matrícula (Nº Reg. do recibo)" value={data.matricula} onChange={(e) => setData('matricula', e.target.value)} erro={errors.matricula} inputMode="numeric" maxLength={20} /></div>
                        : <div className="col-sm-6"><CampoValor rotulo="Valor da diária" value={data.valor_diaria} onChange={(v) => setData('valor_diaria', v)} erro={errors.valor_diaria} /></div>}
                    <div className="col-sm-6"><Campo rotulo="Telefone" value={telefone(data.telefone)} onChange={(e) => setData('telefone', e.target.value)} erro={errors.telefone} inputMode="tel" /></div>
                </div>
                <Campo rotulo="Chave Pix" value={data.pix} onChange={(e) => setData('pix', e.target.value)} erro={errors.pix} maxLength={120} />
                <Campo rotulo="Observação" value={data.observacao} onChange={(e) => setData('observacao', e.target.value)} erro={errors.observacao} maxLength={255} />
                {editando?.id && (
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" id="func-ativo" checked={!!data.ativo} onChange={(e) => setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="func-ativo">Ativo</label>
                    </div>
                )}
            </Janela>
        </div>
    )
}
