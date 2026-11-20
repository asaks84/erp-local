import { moeda } from '../lib/formato'

const pct = (v) => `${Number(v).toFixed(2).replace('.', ',')}%`

/**
 * Escolhas de um cliente na NFS-e (o padrão vem do cadastro dele): declarar os dados do cliente, ISSQN retido pelo
 * tomador e retenção de tributos federais (só aparece com retenção na configuração). Mostra a prévia dos valores.
 * esc = { identificar, retido, retem_federal }
 */
export default function EscolhasNota({ cliente: c, valor, esc, setEsc, retFederal, aliquota }) {
    const iss = esc.retido && aliquota ? Math.round(valor * Number(aliquota.iss)) / 100 : 0
    const fed = esc.retem_federal ? Math.round(valor * retFederal) / 100 : 0
    const linha = (r, v, forte) => <div className={`d-flex justify-content-between border-bottom py-1${forte ? ' fw-semibold' : ''}`}><span>{r}</span><span>{v}</span></div>
    const caixa = (k, rotulo, ajuda, desligado, aoMudar) => (
        <div className="form-check mb-2">
            <input className="form-check-input" type="checkbox" id={`esc-${k}-${c.id}`} checked={!!esc[k]} disabled={desligado}
                onChange={(e) => (aoMudar ? aoMudar(e.target.checked) : setEsc({ ...esc, [k]: e.target.checked }))} />
            <label className="form-check-label" htmlFor={`esc-${k}-${c.id}`}>{rotulo}</label>
            <div className="form-text">{ajuda}</div>
        </div>
    )
    return (
        <>
            {caixa('identificar', 'Declarar os dados do cliente na nota',
                c.pode_identificar ? 'Desmarcado, a nota sai como “Tomador/Adquirente não informado”.' : 'Sem CPF/CNPJ válido no cadastro: a nota sai sem tomador.',
                !c.pode_identificar, (v) => setEsc({ identificar: v, retido: v && esc.retido, retem_federal: v && esc.retem_federal }))}
            {caixa('retido', 'ISSQN retido pelo tomador',
                c.pode_reter ? 'Quando o cliente recolhe o ISS no lugar do Local e desconta do pagamento.' : 'Para reter, o cliente precisa de CPF/CNPJ e endereço completo no município (cadastro do cliente).',
                !c.pode_reter || !esc.identificar)}
            {retFederal > 0 && caixa('retem_federal', 'Retenção de tributos federais',
                `Tipo e alíquotas da configuração (${pct(retFederal)}). Marque quando este cliente é obrigado a reter.`, !esc.identificar)}
            {(iss > 0 || fed > 0) && (
                <div className="small">
                    {iss > 0 && linha(`ISSQN retido (${pct(aliquota.iss)})`, moeda(iss))}
                    {fed > 0 && linha(`Tributos federais retidos (${pct(retFederal)})`, moeda(fed))}
                    {linha('Valor líquido da NFS-e', moeda(valor - iss - fed), true)}
                </div>
            )}
        </>
    )
}

/** as escolhas do cliente em etiquetas, para a lista */
export function EtiquetasNota({ esc, retFederal }) {
    return (
        <span className="d-inline-flex flex-wrap gap-1">
            <span className={`selo ${esc.identificar ? 'selo-info' : ''}`}>{esc.identificar ? 'com dados' : 'sem dados'}</span>
            {esc.retido && <span className="selo selo-aviso">ISS retido</span>}
            {retFederal > 0 && esc.retem_federal && <span className="selo selo-aviso">retenção federal</span>}
        </span>
    )
}
