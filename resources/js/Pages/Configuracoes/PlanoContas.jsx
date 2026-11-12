import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import Icone from '../../Components/Icone'

/** Configurações › Plano de contas: árvore de receitas (1) e despesas (2). */
export default function PlanoContas({ contas }) {
    const [janela, setJanela] = useState(null) // { modo: 'nova'|'editar', conta }
    const form = useForm({ nome: '', ativa: true, pai_id: '' })
    const op = { preserveScroll: true }
    const abrirNova = (pai) => { form.clearErrors(); form.setData({ nome: '', ativa: true, pai_id: pai.id }); setJanela({ modo: 'nova', conta: pai }) }
    const abrirEditar = (c) => { form.clearErrors(); form.setData({ nome: c.nome, ativa: c.ativa, pai_id: '' }); setJanela({ modo: 'editar', conta: c }) }
    const salvar = () => {
        const fim = { ...op, onSuccess: () => setJanela(null) }
        janela.modo === 'nova' ? form.post('/configuracoes/plano-contas', fim) : form.put(`/configuracoes/plano-contas/${janela.conta.id}`, fim)
    }
    const irmas = (c) => contas.filter((x) => x.pai_id === c.pai_id)

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <div style={{ maxWidth: 820 }}>
                <TituloPagina titulo="Plano de contas" descricao="Onde cada receita e cada despesa é classificada. É a base do resultado do mês. Contas do sistema (receitas das ordens e folha) não se apagam; as outras você cria, renomeia, reordena e desativa." />
                <div className="card">
                    <ul className="list-group list-group-flush">
                        {contas.map((c) => {
                            const lista = irmas(c)
                            const pos = lista.findIndex((x) => x.id === c.id)
                            return (
                                <li key={c.id} className={`list-group-item d-flex align-items-center gap-2${c.ativa ? '' : ' inativo'}`} style={{ paddingLeft: `${1 + c.nivel * 1.5}rem` }}>
                                    <span className={`text-muted small ${c.nivel === 0 ? 'fw-bold' : ''}`} style={{ minWidth: '3.5rem' }}>{c.codigo}</span>
                                    <span className={`flex-grow-1 ${c.nivel <= 1 ? 'fw-semibold' : ''}`}>{c.nome}{!c.ativa && <span className="selo ms-2">Desativada</span>}</span>
                                    {c.nivel > 0 && <>
                                        <button type="button" className="btn btn-sm btn-link p-0 lh-1" disabled={pos === 0} onClick={() => router.post(`/configuracoes/plano-contas/${c.id}/mover`, { direcao: 'subir' }, op)} aria-label={`Subir ${c.nome}`}>▲</button>
                                        <button type="button" className="btn btn-sm btn-link p-0 lh-1" disabled={pos === lista.length - 1} onClick={() => router.post(`/configuracoes/plano-contas/${c.id}/mover`, { direcao: 'descer' }, op)} aria-label={`Descer ${c.nome}`}>▼</button>
                                    </>}
                                    {c.nivel < 3 && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrirNova(c)} aria-label={`Nova subconta em ${c.nome}`}><Icone nome="mais" tamanho={16} /></button>}
                                    {c.nivel > 0 && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrirEditar(c)} aria-label={`Editar ${c.nome}`}><Icone nome="lapis" tamanho={16} /></button>}
                                    {c.nivel > 0 && !c.sistema && !c.tem_filhas && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.delete(`/configuracoes/plano-contas/${c.id}`, op)} aria-label={`Apagar ${c.nome}`}><Icone nome="lixeira" tamanho={16} /></button>}
                                </li>
                            )
                        })}
                    </ul>
                </div>
            </div>

            <Janela aberta={!!janela} titulo={janela?.modo === 'nova' ? `Nova conta em ${janela?.conta.codigo} ${janela?.conta.nome}` : 'Alterar conta'} ocupado={form.processing} onFechar={() => setJanela(null)} onEnviar={salvar}>
                <Campo rotulo="Nome" value={form.data.nome} onChange={(e) => form.setData('nome', e.target.value)} erro={form.errors.nome} maxLength={60} autoFocus />
                {janela?.modo === 'editar' && !janela.conta.sistema && (
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" id="pc-ativa" checked={!!form.data.ativa} onChange={(e) => form.setData('ativa', e.target.checked)} />
                        <label className="form-check-label" htmlFor="pc-ativa">Ativa (aparece para lançar)</label>
                    </div>
                )}
            </Janela>
        </div>
    )
}
