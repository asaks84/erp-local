import { Link, useForm } from '@inertiajs/react'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { moeda } from '../../lib/formato'
import { CampoArquivo, CamposPagamento, hoje } from './Comum'

export default function PagarForm({ despesa, contas, fornecedores, formas, contas_financeiras }) {
    const nova = !despesa
    const pix = formas.find((x) => /pix/i.test(x.nome)) || formas[0]
    const form = useForm(nova
        ? { descricao: '', fornecedor_id: '', conta_id: '', competencia: hoje().slice(0, 7), vencimento: '', valor: '', parcelas: 1, observacao: '',
            pago: false, pago_em: hoje(), valor_pago: '', forma_pagamento_id: pix?.id ?? '', conta_financeira_id: pix?.conta_financeira_id ?? '', comprovante: null }
        : { descricao: despesa.descricao, fornecedor_id: despesa.fornecedor_id ?? '', conta_id: despesa.conta_id, competencia: despesa.competencia,
            vencimento: despesa.vencimento, valor: despesa.valor, observacao: despesa.observacao ?? '', comprovante: null })
    const { data, setData, errors, processing } = form

    const escolherFornecedor = (id) => {
        const fo = fornecedores.find((x) => x.id === Number(id))
        setData((d) => ({ ...d, fornecedor_id: id, conta_id: d.conta_id || fo?.conta_padrao_id || '' }))
    }
    const escolherVencimento = (v) => setData((d) => ({ ...d, vencimento: v, competencia: nova && v && d.competencia === hoje().slice(0, 7) ? v.slice(0, 7) : d.competencia }))
    const parcelas = Number(data.parcelas) || 1

    function salvar(e) {
        e.preventDefault()
        if (nova) form.post('/pagar', { forceFormData: true })
        else form.transform((d) => ({ ...d, _method: 'put' })).post(`/pagar/${despesa.id}`, { forceFormData: true })
    }

    return (
        <div className="estreito">
            <TituloPagina titulo={nova ? 'Nova conta a pagar' : 'Alterar conta a pagar'} descricao={nova ? 'Lance a conta quando ela chegar. Se já pagou, marque "Já está paga".' : null} />
            <form className="card" onSubmit={salvar} noValidate>
                <div className="card-body">
                    <Campo rotulo="Descrição" value={data.descricao} onChange={(e) => setData('descricao', e.target.value)} erro={errors.descricao} maxLength={120} autoFocus={nova} placeholder="Ex.: Energia de novembro" />
                    <div className="row g-2">
                        <div className="col-md-6">
                            <Campo as="select" rotulo="Fornecedor" value={data.fornecedor_id} onChange={(e) => escolherFornecedor(e.target.value)} erro={errors.fornecedor_id}>
                                <option value="">Sem fornecedor</option>
                                {fornecedores.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
                            </Campo>
                            <div className="form-text mt-n2 mb-2"><Link href="/fornecedores">Cadastrar fornecedor</Link></div>
                        </div>
                        <div className="col-md-6">
                            <Campo as="select" rotulo="Conta (plano de contas)" value={data.conta_id} onChange={(e) => setData('conta_id', e.target.value)} erro={errors.conta_id}>
                                <option value="">Escolha</option>
                                {contas.map((c) => <option key={c.id} value={c.id}>{c.rotulo}</option>)}
                            </Campo>
                        </div>
                    </div>
                    <div className="row g-2">
                        <div className="col-sm-4"><CampoValor rotulo={parcelas > 1 ? 'Valor total' : 'Valor'} value={data.valor} onChange={(v) => setData('valor', v)} erro={errors.valor} /></div>
                        <div className="col-sm-4"><CampoData rotulo={parcelas > 1 ? '1º vencimento' : 'Vencimento'} value={data.vencimento} onChange={escolherVencimento} erro={errors.vencimento} /></div>
                        <div className="col-sm-4"><CampoData mes rotulo="Referente ao mês" value={data.competencia} onChange={(v) => setData('competencia', v)} erro={errors.competencia} /></div>
                    </div>
                    {nova && (
                        <div className="row g-2">
                            <div className="col-sm-4">
                                <Campo as="select" rotulo="Parcelas" value={data.parcelas} onChange={(e) => setData('parcelas', e.target.value)} erro={errors.parcelas}>
                                    {Array.from({ length: 24 }, (_, i) => <option key={i} value={i + 1}>{i === 0 ? 'À vista' : `${i + 1}x`}</option>)}
                                </Campo>
                            </div>
                            {parcelas > 1 && Number(data.valor) > 0 && <div className="col-sm-8 d-flex align-items-center small text-muted mb-3">{parcelas}x de {moeda(Math.floor(Number(data.valor) / parcelas * 100) / 100)}, uma por mês a partir do 1º vencimento.</div>}
                        </div>
                    )}
                    <Campo rotulo="Observação" value={data.observacao} onChange={(e) => setData('observacao', e.target.value)} erro={errors.observacao} maxLength={255} />
                    <CampoArquivo form={form} rotulo={nova ? 'Boleto, nota ou comprovante (PDF ou foto, até 5 MB)' : 'Trocar o arquivo anexado (PDF ou foto, até 5 MB)'} />

                    {nova && (
                        <>
                            <div className="form-check form-switch mb-3">
                                <input className="form-check-input" type="checkbox" id="pago" checked={data.pago} onChange={(e) => setData('pago', e.target.checked)} />
                                <label className="form-check-label" htmlFor="pago">Já está paga{parcelas > 1 ? ' (a 1ª parcela)' : ''}</label>
                            </div>
                            {data.pago && <CamposPagamento form={form} formas={formas} contasFinanceiras={contas_financeiras} valorPrevisto comComprovante={false} />}
                        </>
                    )}
                </div>
                <div className="card-footer d-flex gap-2 justify-content-end">
                    <Link href="/pagar" className="btn btn-outline-secondary">Voltar</Link>
                    <button type="submit" className="btn btn-primary" disabled={processing}>{nova ? 'Lançar conta' : 'Salvar'}</button>
                </div>
            </form>
        </div>
    )
}
