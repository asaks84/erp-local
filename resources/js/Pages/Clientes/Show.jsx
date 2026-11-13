import { Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import TituloPagina from '../../Components/TituloPagina'
import Icone from '../../Components/Icone'
import Janela from '../../Components/Janela'
import Confirmar from '../../Components/Confirmar'
import { Modal, Button } from 'react-bootstrap'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoValor from '../../Components/CampoValor'
import { data as fData, mesAno, moeda } from '../../lib/formato'
import { cep as mCep, telefone } from '../../lib/mascaras'
import { usePode } from '../../lib/permissoes'
import ContasCliente from '../../Components/ContasCliente'
import RegrasBoletoCliente from '../../Components/RegrasBoletoCliente'

const hoje = () => {
    const d = new Date()
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
const mesAtual = () => hoje().slice(0, 7)

function SituacaoContrato({ c }) {
    if (c.situacao === 'futuro') return <span className="selo selo-aviso">Começa em {fData(c.inicio)}</span>
    if (c.situacao === 'encerrado') return <span className="selo">Encerrada em {fData(c.fim)}</span>
    if (c.fim) return <span className="selo selo-aviso">Termina em {fData(c.fim)}</span>
    return <span className="selo selo-ok">Vigente</span>
}

function taxaTexto(p) {
    if (!p.taxa_valor) return 'sem taxa'
    return p.taxa_tipo === 'pct' ? `taxa de ${String(p.taxa_valor).replace('.', ',')}%` : `taxa de ${moeda(p.taxa_valor)}`
}

/** Ações na mensalidade: nova, reajustar, encerrar, pausar. */
function AcoesMensalidade({ veiculo, contrato, periodos, pausaPadrao, unidades }) {
    const pode = usePode()
    const [janela, setJanela] = useState(null)
    const nova = useForm({ periodo: periodos[0] || '', valor: '', inicio: hoje(), unidade_id: unidades[0]?.id || '' })
    const reajuste = useForm({ valor: contrato ? contrato.valor : '', a_partir: '', periodo: contrato?.periodo || '' })
    const fim = useForm({ data: hoje(), motivo: '' })
    const pausa = useForm({ de: mesAtual(), ate: mesAtual(), taxa_tipo: pausaPadrao.tipo, taxa_valor: Number(pausaPadrao.valor) || 0, motivo: '' })
    const fechar = () => setJanela(null)
    const opcoes = { preserveScroll: true, onSuccess: fechar }

    return (
        <>
            <div className="d-flex flex-wrap gap-2">
                {!contrato && veiculo.ativo && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setJanela('nova')}>+ Mensalidade</button>}
                {contrato && contrato.situacao !== 'encerrado' && (
                    <>
                        {pode('mensalidade_valor') && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setJanela('reajuste')}>Reajustar</button>}
                        <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setJanela('pausa')}>Pausar</button>
                        {contrato.fim
                            ? <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.post(`/mensalidades/${contrato.id}/desfazer-encerramento`, {}, { preserveScroll: true })}>Desfazer encerramento</button>
                            : <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => setJanela('fim')}>Encerrar</button>}
                    </>
                )}
            </div>

            <Janela aberta={janela === 'nova'} titulo={`Mensalidade de ${veiculo.rotulo}`} onFechar={fechar} ocupado={nova.processing}
                onEnviar={() => nova.post(`/veiculos/${veiculo.id}/mensalidade`, opcoes)}>
                <Campo as="select" rotulo="Período" value={nova.data.periodo} erro={nova.errors.periodo} onChange={(e) => nova.setData('periodo', e.target.value)}>
                    {periodos.map((p) => <option key={p}>{p}</option>)}
                </Campo>
                <CampoValor rotulo="Valor da mensalidade" value={nova.data.valor} erro={nova.errors.valor} onChange={(v) => nova.setData('valor', v)} />
                <CampoData rotulo="Começa em" value={nova.data.inicio} erro={nova.errors.inicio} onChange={(v) => nova.setData('inicio', v)} />
                {unidades.length > 1 && (
                    <Campo as="select" rotulo="Unidade" value={nova.data.unidade_id} erro={nova.errors.unidade_id} onChange={(e) => nova.setData('unidade_id', e.target.value)}>
                        {unidades.map((u) => <option key={u.id} value={u.id}>{u.nome}</option>)}
                    </Campo>
                )}
                <p className="text-muted small mb-0">A primeira cobrança será gerada pelo sistema novo a partir da etapa 3. Até lá, gere pelo sistema atual.</p>
            </Janela>

            {contrato && (
                <>
                    <Janela aberta={janela === 'reajuste'} titulo="Reajustar mensalidade" onFechar={fechar} ocupado={reajuste.processing}
                        onEnviar={() => reajuste.post(`/mensalidades/${contrato.id}/reajustar`, opcoes)}>
                        <p className="text-muted">Hoje: {moeda(contrato.valor)} ({contrato.periodo}). O valor atual continua até a véspera da data escolhida.</p>
                        <CampoValor rotulo="Novo valor" value={reajuste.data.valor} erro={reajuste.errors.valor} onChange={(v) => reajuste.setData('valor', v)} />
                        <CampoData rotulo="Vale a partir de" value={reajuste.data.a_partir} erro={reajuste.errors.a_partir} onChange={(v) => reajuste.setData('a_partir', v)} />
                        <Campo as="select" rotulo="Período" value={reajuste.data.periodo} onChange={(e) => reajuste.setData('periodo', e.target.value)}>
                            {[...new Set([contrato.periodo, ...periodos])].map((p) => <option key={p}>{p}</option>)}
                        </Campo>
                    </Janela>

                    <Janela aberta={janela === 'fim'} titulo="Encerrar mensalidade" perigo rotuloOk="Encerrar" onFechar={fechar} ocupado={fim.processing}
                        onEnviar={() => fim.post(`/mensalidades/${contrato.id}/encerrar`, opcoes)}>
                        <p className="text-muted">Pode ser uma data futura (encerramento agendado): a última mensalidade é a do mês dessa data e o sistema para sozinho.</p>
                        <CampoData rotulo="Último dia" value={fim.data.data} erro={fim.errors.data} onChange={(v) => fim.setData('data', v)} />
                        <Campo rotulo="Motivo" value={fim.data.motivo} erro={fim.errors.motivo} maxLength={200} onChange={(e) => fim.setData('motivo', e.target.value)} />
                    </Janela>

                    <Janela aberta={janela === 'pausa'} titulo="Pausar mensalidade" onFechar={fechar} ocupado={pausa.processing}
                        onEnviar={() => pausa.post(`/mensalidades/${contrato.id}/pausar`, opcoes)}>
                        <p className="text-muted">Nos meses pausados a mensalidade cheia não é gerada; se houver taxa de reserva, ela é cobrada no lugar.</p>
                        <div className="row g-2">
                            <div className="col-6"><CampoData mes rotulo="De" value={pausa.data.de} erro={pausa.errors.de} onChange={(v) => pausa.setData('de', v)} /></div>
                            <div className="col-6"><CampoData mes rotulo="Até" value={pausa.data.ate} erro={pausa.errors.ate} onChange={(v) => pausa.setData('ate', v)} /></div>
                        </div>
                        <div className="row g-2">
                            <div className="col-5">
                                <Campo as="select" rotulo="Taxa" value={pausa.data.taxa_tipo} onChange={(e) => pausa.setData('taxa_tipo', e.target.value)}>
                                    <option value="valor">Valor fixo</option>
                                    <option value="pct">% da mensalidade</option>
                                </Campo>
                            </div>
                            <div className="col-7">
                                <CampoValor rotulo={pausa.data.taxa_tipo === 'pct' ? 'Porcentagem' : 'Valor por mês'} prefixo={pausa.data.taxa_tipo === 'pct' ? '%' : 'R$'}
                                    value={pausa.data.taxa_valor} erro={pausa.errors.taxa_valor} onChange={(v) => pausa.setData('taxa_valor', v)} ajuda="0 = pausa grátis" />
                            </div>
                        </div>
                        <Campo rotulo="Motivo (opcional)" value={pausa.data.motivo} maxLength={200} onChange={(e) => pausa.setData('motivo', e.target.value)} />
                    </Janela>
                </>
            )}
        </>
    )
}

export default function ClienteShow({ cliente, veiculos, pagadores, periodos, pausa_padrao, abertas, total_aberto, cobrancas, pagamentos, mes_atual, regras_boleto_empresa }) {
    const interLigado = !!usePage().props.empresa?.inter
    const pode = usePode()
    const { unidades = [] } = usePage().props
    const [confirmar, setConfirmar] = useState(null)
    const endereco = [cliente.logradouro, cliente.numero, cliente.complemento].filter(Boolean).join(', ')
    const cidade = [cliente.bairro, cliente.cidade && `${cliente.cidade}${cliente.uf ? '/' + cliente.uf : ''}`, cliente.cep && mCep(cliente.cep)].filter(Boolean).join(' · ')

    const [remover, setRemover] = useState(false)
    const [removendo, setRemovendo] = useState(false)
    const aReceber = abertas.filter((o) => o.tipo !== 'credito')
    const totalReceber = aReceber.reduce((s, o) => s + o.valor, 0)

    function executar() {
        const { url } = confirmar
        router.post(url, {}, { preserveScroll: true, onFinish: () => setConfirmar(null) })
    }

    /** com conta em aberto: excluir (cancelar), manter, ou ir receber */
    function removerCom(contas) {
        if (contas === 'receber') {
            router.visit(`/clientes/${cliente.id}/receber`)
            return
        }
        setRemovendo(true)
        router.post(`/clientes/${cliente.id}/remover`, { contas }, { preserveScroll: true, onFinish: () => { setRemovendo(false); setRemover(false) } })
    }

    return (
        <div className="pagina">
            <TituloPagina titulo={cliente.nome}>
                <Link href={`/clientes/${cliente.id}/editar`} className="btn btn-outline-secondary d-inline-flex align-items-center gap-2"><Icone nome="lapis" tamanho={16} /> Editar</Link>
            </TituloPagina>
            <div className="d-flex flex-wrap gap-2 mb-3" style={{ marginTop: -12 }}>
                <span className="selo">Cód. {cliente.id}</span>
                {!cliente.ativo && <span className="selo selo-erro">Inativo</span>}
                {cliente.convenio && <span className="selo selo-marca">Convênio: {cliente.convenio}</span>}
                {cliente.nao_recebe_avisos && <span className="selo">Não recebe avisos</span>}
            </div>

            <div className="row g-4">
                <div className="col-lg-4">
                    <div className="card">
                        <div className="card-header">Dados</div>
                        <div className="card-body">
                            <dl className="ficha-dados mb-0">
                                <dt>CPF/CNPJ</dt><dd>{cliente.documento || '—'}</dd>
                                <dt>Celular</dt><dd>{cliente.celular ? telefone(cliente.celular) : '—'}{cliente.telefone && <span className="d-block text-muted small">Tel. {telefone(cliente.telefone)}</span>}</dd>
                                {cliente.email && (<><dt>E-mail</dt><dd>{cliente.email}</dd></>)}
                                <dt>Endereço</dt><dd>{endereco || '—'}{cidade && <span className="d-block text-muted small">{cidade}</span>}</dd>
                                <dt>Vencimento</dt><dd>Todo dia {cliente.dia_vencimento}</dd>
                                {cliente.cliente_desde && (<><dt>Cliente desde</dt><dd>{mesAno(cliente.cliente_desde)}</dd></>)}
                                {cliente.observacoes && (<><dt>Observações</dt><dd className="text-break">{cliente.observacoes}</dd></>)}
                            </dl>
                        </div>
                    </div>

                    {pagadores.length > 0 && (
                        <div className="card mt-4">
                            <div className="card-header">Quem também paga por ele</div>
                            <ul className="list-group list-group-flush">
                                {pagadores.map((p) => (
                                    <li key={p.id} className="list-group-item d-flex align-items-center gap-2 bg-transparent">
                                        <div className="flex-grow-1"><div>{p.nome}</div><small className="text-muted">{[p.documento, p.conta].filter(Boolean).join(' · ')}</small></div>
                                        <button type="button" className="btn btn-sm btn-outline-secondary" aria-label={`Desvincular ${p.nome}`}
                                            onClick={() => router.delete(`/pagadores/${p.id}`, { preserveScroll: true })}><Icone nome="lixeira" tamanho={16} /></button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {(interLigado || cliente.regras_boleto) && regras_boleto_empresa && <RegrasBoletoCliente cliente={cliente} empresa={regras_boleto_empresa} />}

                    {pode('excluir_cadastro') && (
                        <div className="mt-3">
                            {cliente.ativo
                                ? <button type="button" className="btn btn-link text-danger px-0" onClick={() => (aReceber.length ? setRemover(true) : setConfirmar({ url: `/clientes/${cliente.id}/remover`, titulo: 'Remover cliente', texto: 'O cliente e os veículos saem das listas e as mensalidades são encerradas hoje. O histórico continua guardado e ele pode ser reativado.' }))}>Remover cliente</button>
                                : <button type="button" className="btn btn-link px-0" onClick={() => router.post(`/clientes/${cliente.id}/reativar`, {}, { preserveScroll: true })}>Reativar cliente</button>}
                        </div>
                    )}
                </div>

                <div className="col-lg-8">
                    <ContasCliente cliente={cliente} abertas={abertas} total={total_aberto} cobrancas={cobrancas} pagamentos={pagamentos} mesAtual={mes_atual} />
                    <div className="d-flex align-items-center mb-2">
                        <h2 className="fs-5 fw-semibold flex-grow-1 mb-0">Veículos</h2>
                        {cliente.ativo && <Link href={`/clientes/${cliente.id}/veiculos/novo`} className="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"><Icone nome="mais" tamanho={16} /> Adicionar veículo</Link>}
                    </div>
                    {veiculos.length === 0 && <div className="alert aviso-neutro">Nenhum veículo cadastrado.</div>}
                    {veiculos.map((v) => {
                        const atual = v.contratos.find((c) => c.situacao !== 'encerrado')
                        const antigos = v.contratos.filter((c) => c !== atual)
                        return (
                            <div key={v.id} className={`card veiculo-card ${v.ativo ? '' : 'inativo'}`}>
                                <div className="card-body">
                                    <div className="d-flex flex-wrap align-items-start gap-2">
                                        <div className="flex-grow-1">
                                            <div className="fs-5 fw-bold">{v.rotulo} {!v.tem_placa && <span className="selo ms-1">Sem placa</span>} {!v.ativo && <span className="selo ms-1">Removido</span>}</div>
                                            <div className="text-muted">{[v.descricao, v.tipo].filter(Boolean).join(' · ')}</div>
                                        </div>
                                        <div className="d-flex gap-2">
                                            <Link href={`/veiculos/${v.id}/editar`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${v.rotulo}`}><Icone nome="lapis" tamanho={16} /></Link>
                                            {pode('excluir_cadastro') && (v.ativo
                                                ? <button type="button" className="btn btn-sm btn-outline-secondary" aria-label={`Remover ${v.rotulo}`} onClick={() => setConfirmar({ url: `/veiculos/${v.id}/remover`, titulo: 'Remover veículo', texto: `Remover ${v.rotulo}? A mensalidade dele é encerrada hoje; o histórico fica guardado.` })}><Icone nome="lixeira" tamanho={16} /></button>
                                                : <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.post(`/veiculos/${v.id}/reativar`, {}, { preserveScroll: true })}>Reativar</button>)}
                                        </div>
                                    </div>

                                    <hr />
                                    {atual ? (
                                        <div className="mb-2">
                                            <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                <strong>Mensalidade {moeda(atual.valor)}</strong>
                                                <span className="text-muted">{atual.periodo} · desde {fData(atual.inicio)}</span>
                                                <SituacaoContrato c={atual} />
                                            </div>
                                            {atual.motivo_fim && atual.fim && <div className="small text-muted">Motivo: {atual.motivo_fim}</div>}
                                            {atual.pausas.map((p) => (
                                                <div key={p.id} className="small d-flex align-items-center gap-2">
                                                    <span className="selo selo-aviso">Pausa</span>
                                                    {mesAno(p.inicio)} a {mesAno(p.fim)}, {taxaTexto(p)}{p.motivo ? ` — ${p.motivo}` : ''}
                                                    {p.pode_remover && <button type="button" className="btn btn-link btn-sm p-0" onClick={() => router.delete(`/pausas/${p.id}`, { preserveScroll: true })}>remover</button>}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-muted mb-2">Sem mensalidade (avulso).</div>
                                    )}
                                    <AcoesMensalidade veiculo={v} contrato={atual} periodos={periodos} pausaPadrao={pausa_padrao} unidades={unidades} />

                                    {antigos.length > 0 && (
                                        <details className="mt-3 small">
                                            <summary className="text-muted">Histórico da mensalidade ({antigos.length})</summary>
                                            <ul className="mb-0 mt-2">
                                                {antigos.map((c) => (
                                                    <li key={c.id}>{moeda(c.valor)} · {c.periodo} · {fData(c.inicio)} a {c.fim ? fData(c.fim) : '—'}{c.motivo_fim ? ` (${c.motivo_fim})` : ''}</li>
                                                ))}
                                            </ul>
                                        </details>
                                    )}
                                </div>
                            </div>
                        )
                    })}

                </div>
            </div>

            <Modal show={remover} onHide={() => !removendo && setRemover(false)} centered>
                <Modal.Header closeButton><Modal.Title as="h2" className="fs-5">Remover cliente com conta em aberto</Modal.Title></Modal.Header>
                <Modal.Body>
                    <p>{cliente.nome} tem {aReceber.length} conta(s) em aberto, somando <strong>{moeda(totalReceber)}</strong>:</p>
                    <ul className="list-unstyled small border rounded p-2 mb-3" style={{ maxHeight: 180, overflowY: 'auto' }}>
                        {aReceber.map((o) => (
                            <li key={o.id} className="d-flex justify-content-between gap-2 py-1">
                                <span>#{o.numero} {o.descricao}{o.placa && <span className="text-muted"> · {o.placa}</span>}{o.vencida && <span className="selo selo-erro ms-1">Vencida</span>}</span>
                                <span className="num text-nowrap">{moeda(o.valor)}</span>
                            </li>
                        ))}
                    </ul>
                    <p className="mb-1 fw-semibold">O que fazer com essas contas?</p>
                    <ul className="small text-muted mb-0 ps-3">
                        {pode('contas_editar') && <li><strong>Excluir</strong>: as contas são canceladas (motivo "Cliente removido") e saem da inadimplência e do resultado. Ficam no histórico como canceladas.</li>}
                        <li><strong>Manter</strong>: o cliente é removido e as contas continuam em aberto, para receber depois.</li>
                        <li><strong>Receber</strong>: vai para a tela de receber. O cliente não é removido agora; remova depois de receber.</li>
                    </ul>
                </Modal.Body>
                <Modal.Footer className="flex-wrap">
                    <Button variant="outline-secondary" onClick={() => setRemover(false)} disabled={removendo} className="me-auto">Voltar</Button>
                    {pode('contas_editar') && <Button variant="danger" onClick={() => removerCom('cancelar')} disabled={removendo}>Excluir</Button>}
                    <Button variant="outline-danger" onClick={() => removerCom('manter')} disabled={removendo}>Manter</Button>
                    <Button variant="primary" onClick={() => removerCom('receber')} disabled={removendo}>Receber</Button>
                </Modal.Footer>
            </Modal>

            <Confirmar aberto={!!confirmar} titulo={confirmar?.titulo} perigo rotuloOk="Remover" onOk={executar} onCancelar={() => setConfirmar(null)}>
                {confirmar?.texto}
            </Confirmar>
        </div>
    )
}
