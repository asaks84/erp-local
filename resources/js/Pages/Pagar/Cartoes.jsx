import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPagar from '../../Components/AbasPagar'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import Icone from '../../Components/Icone'
import { data as fData, mesAno, moeda } from '../../lib/formato'
import { SeloDespesa } from './Comum'

const VAZIO = { nome: '', tipo: 'empresa', dia_vencimento: '', fornecedor_id: '', ativo: true }

export default function Cartoes({ cartoes, tipos, fornecedores, faturas }) {
    const [editando, setEditando] = useState(null)
    const form = useForm(VAZIO)
    const ativos = cartoes.filter((c) => c.ativo)
    const imp = useForm({ cartao_id: ativos.length === 1 ? ativos[0].id : '', arquivo: null, vencimento: '' })
    const abrir = (c) => { form.clearErrors(); form.setData(c ? { ...VAZIO, ...Object.fromEntries(Object.entries(c).map(([k, v]) => [k, v ?? ''])) } : VAZIO); setEditando(c || {}) }
    const salvar = () => {
        const op = { preserveScroll: true, onSuccess: () => setEditando(null) }
        editando.id ? form.put(`/pagar/cartoes/${editando.id}`, op) : form.post('/pagar/cartoes', op)
    }
    const importar = (e) => { e.preventDefault(); imp.post('/pagar/cartoes/importar', { forceFormData: true }) }

    return (
        <div className="pagina">
            <AbasPagar />
            <TituloPagina titulo="Cartões de crédito" descricao="Importe a fatura (OFX ou CSV do banco) e classifique as compras. A fatura vira uma conta a pagar; cada compra conta no resultado na sua conta.">
                <button type="button" className="btn btn-outline-primary d-inline-flex align-items-center gap-2" onClick={() => abrir(null)}><Icone nome="mais" /> Novo cartão</button>
            </TituloPagina>

            {cartoes.length === 0 ? (
                <div className="alert alert-info">Cadastre o cartão primeiro (ex.: "Nubank da empresa"). Se você às vezes paga coisas da empresa com um cartão seu, cadastre ele também, como "Pessoal (do sócio)".</div>
            ) : (
                <form className="card mb-3" onSubmit={importar} noValidate>
                    <div className="card-header">Importar fatura</div>
                    <div className="card-body">
                        <div className="row g-2">
                            <div className="col-md-4">
                                <Campo as="select" rotulo="Cartão" value={imp.data.cartao_id} onChange={(e) => imp.setData('cartao_id', e.target.value)} erro={imp.errors.cartao_id}>
                                    <option value="">Escolha</option>
                                    {ativos.map((c) => <option key={c.id} value={c.id}>{c.nome}{c.tipo === 'pessoal' ? ' (pessoal)' : ''}</option>)}
                                </Campo>
                            </div>
                            <div className="col-md-5">
                                <div className="mb-3">
                                    <input type="file" className={`form-control form-control-lg${imp.errors.arquivo ? ' is-invalid' : ''}`} accept=".ofx,.csv,.txt" onChange={(e) => imp.setData('arquivo', e.target.files[0] || null)} aria-label="Arquivo da fatura" />
                                    {imp.errors.arquivo ? <div className="invalid-feedback">{imp.errors.arquivo}</div> : <div className="form-text">No app do banco: fatura › exportar em OFX (ou CSV).</div>}
                                </div>
                            </div>
                            <div className="col-md-3"><CampoData rotulo="Vencimento" value={imp.data.vencimento} onChange={(v) => imp.setData('vencimento', v)} erro={imp.errors.vencimento} ajuda="Vazio: pelo nome do arquivo ou o dia do cartão." /></div>
                        </div>
                    </div>
                    <div className="card-footer text-end"><button type="submit" className="btn btn-primary" disabled={imp.processing || !imp.data.arquivo}>Importar</button></div>
                </form>
            )}

            {cartoes.length > 0 && (
                <div className="d-flex flex-wrap gap-2 mb-3">
                    {cartoes.map((c) => (
                        <button key={c.id} type="button" className={`btn btn-sm btn-light border${c.ativo ? '' : ' opacity-50'}`} onClick={() => abrir(c)}>
                            {c.nome} · {c.tipo === 'pessoal' ? 'pessoal' : 'da empresa'}{c.dia_vencimento ? ` · vence dia ${c.dia_vencimento}` : ''} <Icone nome="lapis" tamanho={14} />
                        </button>
                    ))}
                </div>
            )}

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Fatura</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Valor</th><th>Compras</th><th className="d-none d-md-table-cell">Conta</th></tr></thead>
                        <tbody>
                            {faturas.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma fatura importada.</td></tr>}
                            {faturas.map((f) => (
                                <tr key={f.id} role="button" onClick={() => router.visit(`/pagar/faturas/${f.id}`)}>
                                    <td><Link href={`/pagar/faturas/${f.id}`} className="fw-semibold text-body text-decoration-none">{f.cartao}</Link><div className="small text-muted">{mesAno(f.fechamento)}{f.tipo === 'pessoal' ? ' · cartão pessoal' : ''}</div></td>
                                    <td className="d-none d-md-table-cell">{fData(f.vencimento)}</td>
                                    <td className="text-end text-nowrap">{moeda(f.total)}</td>
                                    <td>{f.sem_conta > 0 ? <span className="selo selo-aviso">{f.sem_conta} sem conta</span> : <span className="selo selo-ok">Classificadas</span>}<div className="small text-muted">{f.itens} lançamento{f.itens === 1 ? '' : 's'}</div></td>
                                    <td className="d-none d-md-table-cell">{f.pelo_socio ? <span className="selo">Paga pelo sócio</span> : f.estado ? <SeloDespesa estado={f.estado} /> : <span className="small text-muted">nada da empresa</span>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Janela aberta={!!editando} titulo={editando?.id ? 'Alterar cartão' : 'Novo cartão'} ocupado={form.processing} onFechar={() => setEditando(null)} onEnviar={salvar}>
                <Campo rotulo="Nome" value={form.data.nome} onChange={(e) => form.setData('nome', e.target.value)} erro={form.errors.nome} maxLength={60} autoFocus placeholder="Ex.: Nubank da empresa" />
                <Campo as="select" rotulo="De quem é" value={form.data.tipo} onChange={(e) => form.setData('tipo', e.target.value)} erro={form.errors.tipo}
                    ajuda={form.data.tipo === 'pessoal' ? 'Só entram as compras que você marcar como da empresa. Elas contam no resultado, mas não saem do caixa (quem pagou foi você).' : 'A empresa paga a fatura: ela vira uma conta a pagar pelo total.'}>
                    {Object.entries(tipos).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </Campo>
                <div className="row g-2">
                    <div className="col-sm-5"><Campo rotulo="Dia do vencimento" type="number" min="1" max="31" value={form.data.dia_vencimento} onChange={(e) => form.setData('dia_vencimento', e.target.value)} erro={form.errors.dia_vencimento} /></div>
                    <div className="col-sm-7">
                        <Campo as="select" rotulo="Fornecedor (opcional)" value={form.data.fornecedor_id} onChange={(e) => form.setData('fornecedor_id', e.target.value)} erro={form.errors.fornecedor_id}>
                            <option value="">Nenhum</option>
                            {fornecedores.map((f) => <option key={f.id} value={f.id}>{f.nome}</option>)}
                        </Campo>
                    </div>
                </div>
                {editando?.id && (
                    <div className="form-check form-switch">
                        <input className="form-check-input" type="checkbox" id="cartao-ativo" checked={!!form.data.ativo} onChange={(e) => form.setData('ativo', e.target.checked)} />
                        <label className="form-check-label" htmlFor="cartao-ativo">Ativo</label>
                    </div>
                )}
            </Janela>
        </div>
    )
}
