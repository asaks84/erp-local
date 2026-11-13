import { Link, useForm } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Campo from '../../Components/Campo'
import BoletoCobranca from '../../Components/BoletoCobranca'
import { SeloCobranca, competencia } from '../../Components/Dinheiro'
import { data as fData, dataHora, moeda } from '../../lib/formato'
import { usePode } from '../../lib/permissoes'

const AGORA = { aberta: ['Em aberto', 'selo-aviso'], paga: ['Paga', 'selo-ok'], cancelada: ['Cancelada', ''], levada: ['Na cobrança seguinte', ''], removida: ['Removida', ''] }

function Grupo({ titulo, itens }) {
    if (!itens.length) return null
    return (
        <>
            <tr className="table-light"><td colSpan="5" className="fw-semibold small text-uppercase text-muted">{titulo}</td></tr>
            {itens.map((i) => {
                const [r, c] = AGORA[i.agora] || [i.agora, '']
                return (
                    <tr key={`${i.grupo}-${i.numero}`}>
                        <td className="text-muted">{i.numero}</td>
                        <td>{i.descricao}{i.placa && <div className="small text-muted">{i.placa}</div>}</td>
                        <td className="d-none d-md-table-cell">{i.tipo === 'credito' ? '—' : fData(i.vencimento || i.data)}</td>
                        <td className={`text-end text-nowrap ${i.valor < 0 ? 'text-success' : ''}`}>{moeda(i.valor)}</td>
                        <td><span className={`selo ${c}`}>{r}</span></td>
                    </tr>
                )
            })}
        </>
    )
}

export default function CobrancaShow({ cobranca, cliente, itens, boletos = [], inter = false, em_aberto: emAberto = 0, falta_cadastro: faltaCadastro = [], regras_sugerido: regrasSugerido = true }) {
    const pode = usePode()
    const [cancelar, setCancelar] = useState(false)
    const form = useForm({ motivo: '' })

    return (
        <div className="pagina" style={{ maxWidth: 900 }}>
            <TituloPagina titulo={`Cobrança ${cobranca.numero}`} descricao={`${cliente.nome} · ${competencia(cobranca.competencia)}`}>
                <a href={`/cobrancas/imprimir?ids[]=${cobranca.id}`} target="_blank" rel="noopener" className="btn btn-outline-secondary d-inline-flex align-items-center gap-2"><Icone nome="impressora" tamanho={16} /> Imprimir</a>
                {pode('contas_editar') && cobranca.situacao === 'aberta' && <button type="button" className="btn btn-outline-danger" onClick={() => setCancelar(true)}>Cancelar</button>}
            </TituloPagina>
            <div className="d-flex flex-wrap gap-3 mb-3 align-items-center">
                <SeloCobranca situacao={cobranca.situacao} />
                <span>Vencimento <strong>{fData(cobranca.vencimento)}</strong></span>
                <span>Total <strong>{moeda(cobranca.total)}</strong></span>
                <span className="text-muted small">Emitida em {dataHora(cobranca.emitida_em)}</span>
                <Link href={`/clientes/${cliente.id}`} className="ms-auto">Abrir cliente</Link>
            </div>
            {cobranca.situacao === 'cancelada' && <div className="alert alert-secondary">Cancelada: {cobranca.motivo_cancelamento}</div>}
            <BoletoCobranca cobranca={cobranca} boletos={boletos} inter={inter} emAberto={emAberto} faltaCadastro={faltaCadastro} clienteId={cliente.id} regrasSugerido={regrasSugerido} />
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Nº</th><th>Descrição</th><th className="d-none d-md-table-cell">Vencimento</th><th className="text-end">Valor</th><th>Agora</th></tr></thead>
                        <tbody>
                            <Grupo titulo="Saldo anterior" itens={itens.filter((i) => i.grupo === 'saldo_anterior')} />
                            <Grupo titulo="Deste mês" itens={itens.filter((i) => i.grupo === 'atual')} />
                        </tbody>
                        <tfoot><tr><td colSpan="3" className="text-end fw-semibold">Total</td><td className="text-end fw-bold text-nowrap">{moeda(cobranca.total)}</td><td /></tr></tfoot>
                    </table>
                </div>
            </div>
            <p className="small text-muted mt-2">Os valores são os da emissão. A coluna "Agora" mostra como cada conta está hoje.</p>

            <Janela aberta={cancelar} titulo={`Cancelar cobrança ${cobranca.numero}`} perigo rotuloOk="Cancelar cobrança" onFechar={() => setCancelar(false)}
                onEnviar={() => form.post(`/cobrancas/${cobranca.id}/cancelar`, { onSuccess: () => setCancelar(false) })} ocupado={form.processing}>
                <p>As contas continuam em aberto e podem entrar numa nova cobrança.</p>
                <Campo rotulo="Motivo" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} erro={form.errors.motivo} maxLength={200} autoFocus />
            </Janela>
        </div>
    )
}
