import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useMemo, useRef, useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import { dataHora } from '../../lib/formato'

export const SITUACAO = {
    rascunho: ['Rascunho', ''], verificar: ['Verificando na Meta', ''], PENDING: ['Em análise', 'selo-aviso'], APPROVED: ['Aprovado', 'selo-ok'],
    REJECTED: ['Recusado', 'selo-erro'], PAUSED: ['Pausado', 'selo-aviso'], DISABLED: ['Desativado', 'selo-erro'],
}
export const Selo = ({ s }) => <span className={`selo ${(SITUACAO[s] || [])[1] || ''}`}>{(SITUACAO[s] || [s])[0]}</span>
const quando = (d) => (d < 0 ? `${-d} dia(s) antes` : d === 0 ? 'no dia' : `${d} dia(s) depois`)
const MAX_TEXTO = 1024
const MAX_LINHA = 60

const TAGS = /\{([a-z_]+)\}/g
const variaveis = (t) => [...t.matchAll(TAGS)].map((m) => m[1])

/** o mesmo que Modelos::problemas() no servidor */
function problemas(texto, finalidade, permitidasPropaganda, conhecidas) {
    const t = texto.trim()
    if (!t) return ['Escreva o texto da mensagem.']
    const p = []
    if (t.length > MAX_TEXTO) p.push(`Texto longo demais: até ${MAX_TEXTO} caracteres.`)
    const permitidas = finalidade === 'propaganda' ? permitidasPropaganda : conhecidas
    ;[...new Set(variaveis(t))].forEach((v) => {
        if (!conhecidas.includes(v)) p.push(`Informação desconhecida: {${v}}. Use os botões acima do texto.`)
        else if (!permitidas.includes(v)) p.push(`{${v}} só vale em aviso de cobrança.`)
    })
    if (/[{}]/.test(t.replace(/\{[a-z_]+\}/g, ''))) p.push('Chave { ou } sobrando no texto.')
    if (/^\{[a-z_]+\}/.test(t)) p.push('A Meta não aceita começar com uma informação variável. Comece com uma palavra (ex.: "Olá, {nome}").')
    if (/\{[a-z_]+\}$/.test(t)) p.push('A Meta não aceita terminar com uma informação variável. Termine com uma palavra ou um ponto.')
    if (/\}\s*\{/.test(t)) p.push('Duas informações variáveis seguidas: coloque pelo menos uma palavra entre elas.')
    const n = variaveis(t).length
    const palavras = (t.replace(/\{[a-z_]+\}/g, ' ').normalize('NFD').replace(/[̀-ͯ]/g, '').match(/[A-Za-z'-]+/g) || []).length
    if (n > 0 && palavras < 2 * n) p.push('Pouco texto para tantas informações variáveis: a Meta costuma recusar. Escreva mais ou use menos informações.')
    if (t.includes('\t') || / {4,}/.test(t)) p.push('Tire tabulações e sequências de espaços.')
    if (/\n{3,}/.test(t)) p.push('No máximo uma linha em branco seguida.')
    return p
}

/** o mesmo que Modelos::problemasCabecalho() e problemasRodape() */
function problemasCabecalho(cab, finalidade, permitidasPropaganda, conhecidas) {
    const c = (cab || '').trim()
    if (!c) return []
    const p = []
    if (c.length > MAX_LINHA) p.push(`Cabeçalho longo demais: até ${MAX_LINHA} caracteres.`)
    const vs = variaveis(c)
    if (vs.length > 1) p.push('O cabeçalho aceita no máximo uma informação variável.')
    const permitidas = finalidade === 'propaganda' ? permitidasPropaganda : conhecidas
    ;[...new Set(vs)].forEach((v) => {
        if (!conhecidas.includes(v)) p.push(`Cabeçalho: informação desconhecida {${v}}.`)
        else if (!permitidas.includes(v)) p.push(`Cabeçalho: {${v}} só vale em aviso de cobrança.`)
    })
    if (/[{}]/.test(c.replace(/\{[a-z_]+\}/g, ''))) p.push('Cabeçalho: chave { ou } sobrando.')
    if (/[\n\r*_~`]/.test(c)) p.push('O cabeçalho não aceita quebra de linha nem negrito/itálico (ele já sai em negrito).')
    if (/\p{Extended_Pictographic}/u.test(c)) p.push('O cabeçalho não aceita emoji.')
    return p
}
function problemasRodape(rod) {
    const r = (rod || '').trim()
    if (!r) return []
    const p = []
    if (r.length > MAX_LINHA) p.push(`Rodapé longo demais: até ${MAX_LINHA} caracteres.`)
    if (/[{}]/.test(r)) p.push('O rodapé não aceita informação variável: escreva um texto fixo.')
    if (/[\n\r]/.test(r)) p.push('O rodapé é uma linha só.')
    return p
}

/** *negrito*, _itálico_, ~tachado~ e ```mono``` como no WhatsApp */
function formatado(texto) {
    return texto.split(/(```[^`]+```|\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g).map((pedaco, i) => {
        if (/^```[^`]+```$/.test(pedaco)) return <code key={i}>{pedaco.slice(3, -3)}</code>
        if (/^\*[^*\n]+\*$/.test(pedaco)) return <strong key={i}>{pedaco.slice(1, -1)}</strong>
        if (/^_[^_\n]+_$/.test(pedaco)) return <em key={i}>{pedaco.slice(1, -1)}</em>
        if (/^~[^~\n]+~$/.test(pedaco)) return <s key={i}>{pedaco.slice(1, -1)}</s>
        return pedaco
    })
}

/** como a mensagem aparece no WhatsApp do cliente (com valores de exemplo) */
export function Previa({ texto, cabecalho, rodape, botao, rotuloBotao = 'Ver cobrança', exemplos }) {
    const trocar = (t) => (t || '').replace(TAGS, (m, v) => (exemplos[v] !== undefined ? exemplos[v] : m))
    const pronto = trocar(texto)
    return (
        <div className="wa-previa">
            <div className="wa-balao">
                {cabecalho?.trim() && <div className="wa-cabecalho">{trocar(cabecalho)}</div>}
                <div className="wa-texto">{pronto ? formatado(pronto) : <span className="text-muted">A mensagem aparece aqui.</span>}</div>
                {rodape?.trim() && <div className="wa-rodape">{rodape}</div>}
                <div className="wa-hora">09:00</div>
                {botao && <div className="wa-botao">↗ {rotuloBotao}</div>}
            </div>
        </div>
    )
}

function Editor({ modelo, novo, props, aoCancelar }) {
    const { variaveis: lista, variaveis_propaganda: vProp, exemplos, finalidades, botao: rotuloBotao, api } = props
    const form = useForm({
        rotulo: modelo?.rotulo || '', finalidade: modelo?.finalidade || 'cobranca', texto: modelo?.texto || 'Olá, {nome}! Aqui é do {empresa}. ',
        botao: modelo ? modelo.botao : true, cabecalho: modelo?.cabecalho || '', rodape: modelo?.rodape || '', enviar: false,
    })
    const cab = useRef(null)
    const alvo = useRef('texto') // onde entra a informação clicada: no texto ou no cabeçalho (o último com o cursor)
    const [descartar, setDescartar] = useState(false)
    const [enviando, setEnviando] = useState(false)
    const area = useRef(null)
    const conhecidas = lista.map((v) => v.chave)
    const erros = useMemo(() => [
        ...problemasCabecalho(form.data.cabecalho, form.data.finalidade, vProp, conhecidas),
        ...problemas(form.data.texto, form.data.finalidade, vProp, conhecidas),
        ...problemasRodape(form.data.rodape),
    ], [form.data.texto, form.data.cabecalho, form.data.rodape, form.data.finalidade])
    const chips = form.data.finalidade === 'propaganda' ? lista.filter((v) => vProp.includes(v.chave)) : lista

    const inserir = (chave) => {
        const campo = alvo.current
        const el = campo === 'cabecalho' ? cab.current : area.current
        const tag = `{${chave}}`
        const t = form.data[campo]
        const ini = el ? el.selectionStart : t.length
        const fim = el ? el.selectionEnd : t.length
        form.setData(campo, t.slice(0, ini) + tag + t.slice(fim))
        requestAnimationFrame(() => { if (el) { el.focus(); el.setSelectionRange(ini + tag.length, ini + tag.length) } })
    }
    const salvar = (enviar) => {
        form.transform((d) => ({ ...d, enviar }))
        setEnviando(enviar)
        const op = { preserveScroll: true, onFinish: () => setEnviando(false) }
        if (novo) form.post('/configuracoes/whatsapp/mensagens', op)
        else form.put(`/configuracoes/whatsapp/mensagens/${modelo.id}`, op)
    }

    return (
        <form onSubmit={(e) => { e.preventDefault(); salvar(false) }}>
            <div className="row g-2">
                <div className="col-sm-8"><Campo rotulo="Nome da mensagem" ajuda="Só aparece no sistema; o cliente não vê." value={form.data.rotulo} maxLength={80} onChange={(e) => form.setData('rotulo', e.target.value)} erro={form.errors.rotulo} /></div>
                <div className="col-sm-4">
                    <Campo as="select" rotulo="Tipo" value={form.data.finalidade} onChange={(e) => form.setData('finalidade', e.target.value)} erro={form.errors.finalidade}>
                        {Object.entries(finalidades).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </Campo>
                </div>
            </div>
            <div className="row g-3">
                <div className="col-md-7">
                    <Campo rotulo="Cabeçalho (opcional)" value={form.data.cabecalho} maxLength={80} ref={cab} onFocus={() => { alvo.current = 'cabecalho' }}
                        onChange={(e) => form.setData('cabecalho', e.target.value)} erro={form.errors.cabecalho}
                        ajuda={`Linha em negrito no topo. Até ${MAX_LINHA} caracteres, uma informação no máximo, sem emoji. (${form.data.cabecalho.trim().length}/${MAX_LINHA})`} />
                    <div className="small fw-semibold mb-1">Inserir informação <span className="fw-normal text-muted">(entra onde está o cursor: no cabeçalho ou no texto)</span></div>
                    <div className="d-flex flex-wrap gap-1 mb-2">
                        {chips.map((v) => (
                            <button type="button" key={v.chave} className="btn btn-sm btn-outline-secondary" title={v.rotulo} onClick={() => inserir(v.chave)}>{v.rotulo}</button>
                        ))}
                    </div>
                    <textarea ref={area} className={`form-control${form.errors.texto ? ' is-invalid' : ''}`} rows={7} value={form.data.texto} aria-label="Texto da mensagem"
                        onFocus={() => { alvo.current = 'texto' }} onChange={(e) => form.setData('texto', e.target.value)} />
                    <div className="d-flex justify-content-between form-text">
                        <span>As informações entre chaves são trocadas pelos dados de cada cliente.</span>
                        <span className={form.data.texto.trim().length > MAX_TEXTO ? 'text-danger' : ''}>{form.data.texto.trim().length}/{MAX_TEXTO}</span>
                    </div>
                    <div className="form-text mt-0">Formatação como no WhatsApp: *negrito*, _itálico_, ~tachado~.</div>
                    {form.errors.texto && <div className="text-danger small">{form.errors.texto}</div>}
                    <Campo className="mt-3" rotulo="Rodapé (opcional)" value={form.data.rodape} maxLength={80} onChange={(e) => form.setData('rodape', e.target.value)} erro={form.errors.rodape}
                        ajuda={`Linha cinza no fim. Até ${MAX_LINHA} caracteres, texto fixo (sem informação variável). (${form.data.rodape.trim().length}/${MAX_LINHA})`} />
                    {erros.length > 0 && (
                        <ul className="small text-danger-emphasis mt-2 mb-0 ps-3">{erros.map((p) => <li key={p}>{p}</li>)}</ul>
                    )}
                    {form.data.finalidade === 'cobranca' ? (
                        <div className="form-check form-switch mt-3">
                            <input className="form-check-input" type="checkbox" role="switch" id="wa-botao" checked={form.data.botao} onChange={(e) => form.setData('botao', e.target.checked)} />
                            <label className="form-check-label" htmlFor="wa-botao">Botão "{rotuloBotao}" (abre a cobrança com o boleto e o Pix)</label>
                            <div className="form-text">No envio manual, o link vai no texto: no lugar de "toque no botão abaixo" ou no fim.</div>
                        </div>
                    ) : (
                        <div className="alert aviso-neutro small mt-3 mb-0">
                            Propaganda é cobrada mais caro pela Meta e só pode ir para quem aceitou receber. O envio de campanhas entra numa próxima etapa: por enquanto dá para escrever e aprovar.
                        </div>
                    )}
                </div>
                <div className="col-md-5">
                    <div className="small fw-semibold mb-1">Como o cliente vê (exemplo)</div>
                    <Previa texto={form.data.texto} cabecalho={form.data.cabecalho} rodape={form.data.rodape} botao={form.data.finalidade === 'cobranca' && form.data.botao} rotuloBotao={rotuloBotao} exemplos={exemplos} />
                    <div className="form-text">No envio manual (wa.me) não existe cabeçalho nem rodapé: o cabeçalho vai em negrito no começo do texto e o rodapé no fim.</div>
                    {form.data.texto.includes('{valor_com_desconto}') || form.data.texto.includes('{desconto_ate}')
                        ? <div className="form-text">Sem desconto de pontualidade (opção desligada, cobrança atrasada ou com desconto negociado), "valor pagando em dia" mostra o valor cheio e a data limite é o vencimento.</div>
                        : null}
                </div>
            </div>
            <div className="d-flex flex-wrap gap-2 justify-content-end mt-3 pt-3 border-top">
                {novo && <button type="button" className="btn btn-outline-secondary" onClick={aoCancelar}>Cancelar</button>}
                {!novo && (descartar
                    ? <button type="button" className="btn btn-danger me-auto" onClick={() => router.delete(`/configuracoes/whatsapp/mensagens/${modelo.id}`, { preserveScroll: true })}>Confirmar: descartar rascunho</button>
                    : <button type="button" className="btn btn-outline-danger me-auto" onClick={() => setDescartar(true)}>Descartar rascunho</button>)}
                <button type="submit" className="btn btn-outline-primary" disabled={form.processing}>{novo ? 'Criar rascunho' : 'Salvar rascunho'}</button>
                {!novo && (
                    <button type="button" className="btn btn-primary" disabled={form.processing || erros.length > 0 || !api} title={!api ? 'Ligue a API do WhatsApp primeiro' : undefined} onClick={() => salvar(true)}>
                        {enviando ? 'Enviando…' : 'Enviar para aprovação'}
                    </button>
                )}
            </div>
            {!novo && !api && <div className="form-text text-end">Para enviar à Meta, a API do WhatsApp precisa estar ligada.</div>}
        </form>
    )
}

function Mensagem({ g, props }) {
    const ult = g.versoes[0]
    const enviadas = g.versoes.filter((v) => v.situacao !== 'rascunho')
    return (
        <div className="card">
            <div className="card-header d-flex flex-wrap align-items-center gap-2">
                <span className="flex-grow-1">{g.rotulo}</span>
                <span className="small text-muted">{props.finalidades[g.finalidade]}</span>
            </div>
            <div className="card-body">
                <div className="small mb-3 d-flex flex-wrap gap-3">
                    <span><strong>Pela API:</strong> {g.api_versao ? `versão ${g.api_versao} (aprovada)` : 'nenhuma versão aprovada ainda'}</span>
                    {g.finalidade === 'cobranca' && <span><strong>Na régua:</strong> {g.regua.length ? g.regua.map(quando).join(', ') : 'nenhum passo'}</span>}
                </div>
                {ult.situacao === 'rascunho' ? (
                    <>
                        <div className="alert aviso-neutro small">
                            <strong>Rascunho (versão {ult.versao}).</strong> Já vale no envio manual (wa.me). Pela API, só depois que a Meta aprovar
                            {g.api_versao ? `; até lá continua saindo a versão ${g.api_versao}.` : '.'}
                        </div>
                        <Editor key={ult.id} modelo={ult} props={props} />
                    </>
                ) : (
                    <>
                        <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <strong className="small">Versão {ult.versao}</strong><Selo s={ult.situacao} />
                            {ult.enviado_em && <span className="small text-muted">enviada em {dataHora(ult.enviado_em)}</span>}
                        </div>
                        {ult.motivo && <div className="alert alert-danger small">{ult.motivo}</div>}
                        {ult.finalidade === 'cobranca' && ult.categoria === 'MARKETING' && (
                            <div className="alert alert-warning small">A Meta classificou essa mensagem como propaganda: cada envio custa mais caro. Para voltar a aviso, tire do texto o que parecer venda ou promoção e envie uma nova versão.</div>
                        )}
                        {ult.situacao === 'PENDING' && <p className="small text-muted">A Meta costuma analisar em minutos ou poucas horas. A situação atualiza sozinha ao abrir esta tela.</p>}
                        <div className="row g-3">
                            <div className="col-md-7">
                                {ult.cabecalho && <div className="small mb-2"><span className="text-muted">Cabeçalho:</span> {ult.cabecalho}</div>}
                                <div className="small text-pre-wrap border rounded p-2">{ult.texto}</div>
                                {ult.rodape && <div className="small mt-2"><span className="text-muted">Rodapé:</span> {ult.rodape}</div>}
                            </div>
                            <div className="col-md-5"><Previa texto={ult.texto} cabecalho={ult.cabecalho} rodape={ult.rodape} botao={ult.finalidade === 'cobranca' && ult.botao} rotuloBotao={props.botao} exemplos={props.exemplos} /></div>
                        </div>
                        <div className="d-flex justify-content-end mt-3 pt-3 border-top">
                            <button type="button" className="btn btn-primary" onClick={() => router.post(`/configuracoes/whatsapp/mensagens/grupo/${g.grupo}/versao`, {}, { preserveScroll: true })}>
                                Editar (cria a versão {ult.versao + 1})
                            </button>
                        </div>
                        {ult.situacao === 'APPROVED' && <div className="form-text text-end">A versão aprovada continua valendo até a nova ser aprovada.</div>}
                    </>
                )}
                {enviadas.length > (ult.situacao === 'rascunho' ? 0 : 1) && (
                    <details className="mt-3">
                        <summary className="small">Versões anteriores</summary>
                        {enviadas.filter((v) => v.id !== ult.id).map((v) => (
                            <div key={v.id} className="small border-top pt-2 mt-2">
                                <div className="d-flex align-items-center gap-2"><strong>Versão {v.versao}</strong><Selo s={v.situacao} /><span className="text-muted">{v.nome}</span></div>
                                {v.motivo && <div className="text-danger-emphasis">{v.motivo}</div>}
                                <div className="text-muted text-pre-wrap">{v.texto}</div>
                            </div>
                        ))}
                    </details>
                )}
            </div>
        </div>
    )
}

/** Configurações › WhatsApp › Mensagens: criar e editar as mensagens antes de mandar para a Meta. */
export default function WhatsAppMensagens(props) {
    const { grupos, erro_meta: erroMeta, selecionado, api } = props
    const [sel, setSel] = useState(selecionado || grupos[0]?.grupo || 'novo')
    useEffect(() => { if (selecionado) setSel(selecionado) }, [selecionado])
    useEffect(() => { if (sel !== 'novo' && !grupos.some((g) => g.grupo === sel)) setSel(grupos[0]?.grupo || 'novo') }, [grupos])
    const g = grupos.find((x) => x.grupo === sel)
    const porTipo = (f) => grupos.filter((x) => x.finalidade === f)

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Mensagens do WhatsApp" descricao="Escreva e ajuste as mensagens antes de valerem. O rascunho já vale no envio manual; pela API, a mensagem só sai depois de aprovada pela Meta.">
                <Link href="/configuracoes/whatsapp" className="btn btn-outline-secondary">Voltar ao WhatsApp</Link>
            </TituloPagina>
            {erroMeta && <div className="alert alert-danger small">Não deu para consultar a Meta agora: {erroMeta}</div>}
            {!api && <div className="alert aviso-neutro small">A API do WhatsApp está desligada: dá para escrever e salvar rascunhos; para enviar à Meta, ligue a API em Configurações › WhatsApp.</div>}
            <div className="row g-4">
                <div className="col-lg-4">
                    <div className="card">
                        <div className="card-header d-flex align-items-center">
                            <span className="flex-grow-1">Mensagens</span>
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => setSel('novo')}>Nova mensagem</button>
                        </div>
                        <div className="list-group list-group-flush">
                            {['cobranca', 'propaganda'].map((f) => porTipo(f).length > 0 && (
                                <div key={f}>
                                    <div className="list-group-item small text-muted text-uppercase fw-semibold py-1">{props.finalidades[f]}</div>
                                    {porTipo(f).map((x) => (
                                        <button type="button" key={x.grupo} className={`list-group-item list-group-item-action${x.grupo === sel ? ' active' : ''}`} onClick={() => setSel(x.grupo)}>
                                            <div className="d-flex align-items-center gap-2">
                                                <span className="flex-grow-1">{x.rotulo}</span>
                                                <Selo s={x.versoes[0].situacao} />
                                            </div>
                                            <div className="small opacity-75">versão {x.versoes[0].versao}{x.api_versao && x.api_versao !== x.versoes[0].versao ? ` · na API: versão ${x.api_versao}` : ''}</div>
                                        </button>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="col-lg-8">
                    {sel === 'novo' ? (
                        <div className="card">
                            <div className="card-header">Nova mensagem</div>
                            <div className="card-body"><Editor novo props={props} aoCancelar={() => setSel(grupos[0]?.grupo || 'novo')} /></div>
                        </div>
                    ) : g ? <Mensagem key={`${g.grupo}-${g.versoes[0].id}`} g={g} props={props} /> : null}
                </div>
            </div>
        </div>
    )
}
