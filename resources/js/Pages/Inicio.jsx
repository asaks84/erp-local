import { Link, usePage } from '@inertiajs/react'
import TituloPagina from '../Components/TituloPagina'
import BuscaCliente from '../Components/BuscaCliente'
import Anotacoes from '../Components/Anotacoes'
import { competencia } from '../Components/Dinheiro'
import { dataHora, moeda } from '../lib/formato'
import { usePode } from '../lib/permissoes'

function Numero({ rotulo, valor, detalhe, href, classe = '' }) {
    const corpo = (
        <div className="card h-100"><div className="card-body">
            <div className="text-muted small">{rotulo}</div>
            <div className={`fs-3 fw-bold ${classe}`}>{valor}</div>
            {detalhe && <div className="small text-muted">{detalhe}</div>}
        </div></div>
    )
    return <div className="col-6 col-lg-3">{href ? <Link href={href} className="text-reset text-decoration-none">{corpo}</Link> : corpo}</div>
}

export default function Inicio({ aberto, recebido, anotacoes, conferencia, pagar }) {
    const { auth, empresa } = usePage().props
    const pode = usePode()
    const primeiroNome = auth.usuario.nome.split(' ')[0]
    return (
        <div className="pagina">
            <TituloPagina titulo={`Olá, ${primeiroNome}`} descricao={empresa?.nome} />

            <div className="alert aviso-neutro">
                Sistema novo em construção: o sistema atual continua sendo o oficial até a virada.
                {conferencia
                    ? <> Os números daqui vêm da importação de {dataHora(conferencia.quando)}{conferencia.situacao === 'ok' ? ', conferida sem diferenças.' : conferencia.situacao === 'diferente' ? ', com diferenças na conferência.' : ', que deu erro.'}{conferencia.situacao !== 'ok' && pode('configuracoes') && <> <Link href="/conferencia">Ver a conferência</Link>.</>}</>
                    : ' Os números daqui vêm da última importação feita do sistema atual.'}
            </div>

            {pagar && (pagar.atrasadas.qtd > 0 || pagar.hoje.qtd > 0) && (
                <div className={`alert ${pagar.atrasadas.qtd ? 'alert-danger' : 'alert-warning'} d-flex flex-wrap align-items-center gap-2`}>
                    <span className="flex-grow-1">
                        <strong>Contas a pagar:</strong>{' '}
                        {[pagar.atrasadas.qtd && `${pagar.atrasadas.qtd} atrasada(s) (${moeda(pagar.atrasadas.valor)})`, pagar.hoje.qtd && `${pagar.hoje.qtd} vence(m) hoje (${moeda(pagar.hoje.valor)})`].filter(Boolean).join(' e ')}.
                    </span>
                    <Link href={pagar.atrasadas.qtd ? '/pagar?estado=atrasadas' : '/pagar?estado=hoje'} className="btn btn-sm btn-outline-dark">Ver contas</Link>
                </div>
            )}

            <BuscaCliente className="mb-4 so-celular" placeholder="Buscar cliente para receber ou lançar (nome, código ou placa)" />

            {(aberto || recebido) && (
                <div className="row g-3 mb-4">
                    {aberto && <Numero rotulo="Em aberto" valor={moeda(aberto.total)} detalhe={`${aberto.clientes} cliente(s)`} href="/contas/abertas" />}
                    {aberto && <Numero rotulo="Já venceu" valor={moeda(aberto.vencido)} detalhe={`${aberto.inadimplentes} cliente(s) com conta vencida`} href="/contas/abertas?ver=vencidas" classe={aberto.vencido > 0 ? 'text-danger' : ''} />}
                    {recebido && <Numero rotulo={`Recebido em ${competencia(recebido.mes)}`} valor={moeda(recebido.total)} detalhe={`${recebido.qtd} conta(s) baixada(s)`} href="/relatorios/recebimentos" />}
                    {recebido && <Numero rotulo="Recebido hoje" valor={moeda(recebido.hoje)} />}
                </div>
            )}

            {/* segunda linha no mesmo grid da primeira: cada bloco ocupa duas colunas */}
            <div className="row g-3 mb-4">
                {recebido?.por_categoria?.length > 0 && (
                    <div className="col-lg-6">
                        <div className="card h-100">
                            <div className="card-header">Recebido no mês, pelo que foi pago</div>
                            <ul className="list-group list-group-flush">
                                {recebido.por_categoria.map((c) => (
                                    <li key={c.tipo} className="list-group-item d-flex justify-content-between"><span>{c.rotulo}</span><strong className={c.total < 0 ? 'text-success' : ''}>{moeda(c.total)}</strong></li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}
                <div className="col-lg-6">
                    <Anotacoes anotacoes={anotacoes} />
                </div>
            </div>

            <div className="d-flex flex-wrap gap-2">
                <Link href="/mensalidades/gerar" className="btn btn-outline-secondary">Gerar mensalidades</Link>
                <Link href="/cobrancas/gerar" className="btn btn-outline-secondary">Emitir cobranças</Link>
                {pode('extrato') && <Link href="/extrato" className="btn btn-outline-secondary">Extrato bancário</Link>}
            </div>
        </div>
    )
}
