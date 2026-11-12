import { usePage } from '@inertiajs/react'
import { dataHora } from '../lib/formato'

/**
 * Cabeçalho que só aparece na impressão dos relatórios (padrão do sistema atual):
 * logo à esquerda, título e "Emitido em" à direita, linha grossa embaixo.
 */
export default function CabecalhoImpressao({ titulo, detalhe }) {
    const { empresa } = usePage().props
    return (
        <div className="imp-topo d-none d-print-flex">
            <img src={empresa?.logo_url || '/imagens/logo-local.png'} alt={empresa?.nome || ''} />
            <div className="text-end">
                <strong>{titulo}</strong>
                <span>Emitido em {dataHora(new Date())}</span>
                {detalhe && <span>{detalhe}</span>}
            </div>
        </div>
    )
}
