import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import AbasPatio from '../../Components/AbasPatio'
import Janela from '../../Components/Janela'
import { usePode } from '../../lib/permissoes'

function Linha({ p, onResolver }) {
    return (
        <li className="list-group-item py-3">
            <div className="d-flex flex-wrap justify-content-between gap-2">
                <div>
                    <strong>{p.tipo}</strong>{p.placa && <> · {p.placa}</>}{p.ticket && <span className="text-muted"> · ticket {p.ticket}</span>}
                    {p.numero_impresso && p.numero_impresso !== p.ticket && <span className="text-muted"> (impresso {p.numero_impresso})</span>}
                    <div className="small text-muted">Feita no celular em {p.feita_em}{p.pessoa && ` por ${p.pessoa}`}{p.aparelho && ` · ${p.aparelho}`}{p.cobrado && ` · cobrado ${p.cobrado}`}</div>
                </div>
                {onResolver && <button type="button" className="btn btn-sm btn-outline-primary align-self-start" onClick={() => onResolver(p)}>Resolver</button>}
            </div>
            <div className={`mt-2${p.situacao === 'pendente' ? ' text-danger-emphasis' : ''}`}>{p.mensagem}</div>
            {p.situacao === 'resolvida' && <div className="small mt-1"><span className="selo selo-ok me-1">Resolvida</span>{p.observacao} <span className="text-muted">({p.resolvida_por}, {p.resolvida_em})</span></div>}
        </li>
    )
}

/**
 * Operação › Pátio › Pendências do PDV (8.7): o que o celular fez sem internet e não fechou quando a internet voltou.
 * O dinheiro de uma saída que ficou pendente pode estar no caixa sem lançamento: confira e lance na mão.
 */
export default function PatioPendencias({ pendentes, resolvidas }) {
    const pode = usePode()
    const resolve = pode('patio_corrigir')
    const [aberta, setAberta] = useState(null)
    const form = useForm({ observacao: '' })
    const enviar = () => form.post(`/patio/pendencias/${aberta.id}/resolver`, { preserveScroll: true, onSuccess: () => { setAberta(null); form.reset() } })

    return (
        <div className="pagina">
            <AbasPatio />
            <TituloPagina titulo="Pendências do PDV" descricao="Ações feitas no celular sem internet que não fecharam quando a internet voltou. Acerte na mão (no pátio, em Recebimentos ou no caixa) e marque como resolvida." />
            {pendentes.length === 0
                ? <div className="card"><div className="card-body text-muted">Nada pendente.</div></div>
                : <ul className="list-group mb-4">{pendentes.map((p) => <Linha key={p.id} p={p} onResolver={resolve ? setAberta : null} />)}</ul>}
            {resolvidas.length > 0 && (
                <>
                    <h2 className="h6 text-muted mt-4">Resolvidas nos últimos 30 dias</h2>
                    <ul className="list-group">{resolvidas.map((p) => <Linha key={p.id} p={p} />)}</ul>
                </>
            )}
            {aberta && (
                <Janela aberta titulo={`Resolver: ${aberta.tipo}${aberta.placa ? ` · ${aberta.placa}` : ''}`} rotuloOk="Marcar como resolvida" onFechar={() => setAberta(null)} onEnviar={enviar} ocupado={form.processing}>
                    <p className="small">{aberta.mensagem}</p>
                    <label className="form-label w-100">O que foi feito
                        <textarea className={`form-control mt-1${form.errors.observacao ? ' is-invalid' : ''}`} rows={3} maxLength={300} autoFocus value={form.data.observacao}
                            onChange={(e) => form.setData('observacao', e.target.value)} placeholder="Ex.: recebimento lançado à mão em Recebimentos" />
                        {form.errors.observacao && <div className="invalid-feedback">{form.errors.observacao}</div>}
                    </label>
                </Janela>
            )}
        </div>
    )
}
