import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPagar from '../../Components/AbasPagar'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import Icone from '../../Components/Icone'
import { moeda } from '../../lib/formato'
import { competencia } from '../../Components/Dinheiro'
import { hoje } from './Comum'

const VAZIO = { descricao: '', fornecedor_id: '', conta_id: '', valor: '', dia_vencimento: 10, inicio: hoje().slice(0, 7), fim: '', ativa: true }

export default function Recorrentes({ recorrentes, contas, fornecedores }) {
    const [editando, setEditando] = useState(null)
    const form = useForm(VAZIO)
    const abrir = (r) => { form.clearErrors(); form.setData(r ? { ...VAZIO, ...Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v ?? ''])) } : VAZIO); setEditando(r || {}) }
    const salvar = () => {
        const op = { preserveScroll: true, onSuccess: () => setEditando(null) }
        editando.id ? form.put(`/pagar/fixas/${editando.id}`, op) : form.post('/pagar/fixas', op)
    }
    const { data, setData, errors } = form
    const escolherFornecedor = (id) => {
        const fo = fornecedores.find((x) => x.id === Number(id))
        setData((d) => ({ ...d, fornecedor_id: id, conta_id: d.conta_id || fo?.conta_padrao_id || '' }))
    }

    return (
        <div className="pagina">
            <AbasPagar />
            <TituloPagina titulo="Contas fixas" descricao="Aluguel, energia, contador... Cada uma vira uma conta a pagar todo mês (até o mês seguinte ao atual), com o valor previsto. O valor de cada mês pode ser ajustado na conta.">
                <button type="button" className="btn btn-primary d-inline-flex align-items-center gap-2" onClick={() => abrir(null)}><Icone nome="mais" /> Nova conta fixa</button>
            </TituloPagina>
            <div className="card">
                <div className="table-responsive">
                    <table className="table table-hover align-middle mb-0">
                        <thead><tr><th>Conta</th><th className="text-end">Valor previsto</th><th>Vence</th><th className="d-none d-md-table-cell">Período</th><th /></tr></thead>
                        <tbody>
                            {recorrentes.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhuma conta fixa ainda.</td></tr>}
                            {recorrentes.map((r) => (
                                <tr key={r.id} className={r.ativa ? '' : 'inativo'}>
                                    <td><div className="fw-semibold">{r.descricao}</div><div className="small text-muted">{[r.fornecedor, r.conta].filter(Boolean).join(' · ')}</div>{!r.ativa && <span className="selo">Desativada</span>}</td>
                                    <td className="text-end">{moeda(r.valor)}</td>
                                    <td className="text-nowrap">todo dia {r.dia_vencimento}</td>
                                    <td className="d-none d-md-table-cell small">desde {competencia(r.inicio)}{r.fim ? ` até ${competencia(r.fim)}` : ''}</td>
                                    <td className="text-end"><button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => abrir(r)} aria-label={`Editar ${r.descricao}`}><Icone nome="lapis" tamanho={16} /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Janela aberta={!!editando} titulo={editando?.id ? 'Alterar conta fixa' : 'Nova conta fixa'} ocupado={form.processing} onFechar={() => setEditando(null)} onEnviar={salvar}>
                <Campo rotulo="Descrição" value={data.descricao} onChange={(e) => setData('descricao', e.target.value)} erro={errors.descricao} maxLength={120} autoFocus placeholder="Ex.: Aluguel" />
                <Campo as="select" rotulo="Fornecedor" value={data.fornecedor_id} onChange={(e) => escolherFornecedor(e.target.value)} erro={errors.fornecedor_id}>
                    <option value="">Sem fornecedor</option>
                    {fornecedores.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                </Campo>
                <Campo as="select" rotulo="Conta (plano de contas)" value={data.conta_id} onChange={(e) => setData('conta_id', e.target.value)} erro={errors.conta_id}>
                    <option value="">Escolha</option>
                    {contas.map((c) => <option key={c.id} value={c.id}>{c.rotulo}</option>)}
                </Campo>
                <div className="row g-2">
                    <div className="col-sm-6"><CampoValor rotulo="Valor previsto" value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} /></div>
                    <div className="col-sm-6">
                        <Campo as="select" rotulo="Dia do vencimento" value={data.dia_vencimento} onChange={(e) => setData('dia_vencimento', e.target.value)} erro={errors.dia_vencimento}>
                            {Array.from({ length: 31 }, (_, i) => <option key={i} value={i + 1}>{i + 1}</option>)}
                        </Campo>
                    </div>
                </div>
                <div className="row g-2">
                    <div className="col-sm-6"><CampoData mes rotulo="Começa em" value={data.inicio} onChange={(v) => setData('inicio', v)} erro={errors.inicio} /></div>
                    <div className="col-sm-6"><CampoData mes rotulo="Termina em (opcional)" value={data.fim} onChange={(v) => setData('fim', v)} erro={errors.fim} /></div>
                </div>
                {editando?.id && (
                    <>
                        <div className="form-check form-switch">
                            <input className="form-check-input" type="checkbox" id="fixa-ativa" checked={!!data.ativa} onChange={(e) => setData('ativa', e.target.checked)} />
                            <label className="form-check-label" htmlFor="fixa-ativa">Ativa (gera a conta todo mês)</label>
                        </div>
                        <p className="small text-muted mt-2 mb-0">A mudança vale para os próximos meses. Contas já geradas não mudam: altere-as em Contas.</p>
                    </>
                )}
            </Janela>
        </div>
    )
}
