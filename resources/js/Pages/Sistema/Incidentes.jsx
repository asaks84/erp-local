import { Head, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import Campo from '../../Components/Campo'
import CampoData from '../../Components/CampoData'
import CampoDataHora from '../../Components/CampoDataHora'
import Icone from '../../Components/Icone'
import TituloPagina from '../../Components/TituloPagina'

const ETAPA = {
    avaliar: ['Avaliar o risco', 'selo-aviso'],
    comunicar: ['Comunicar', 'selo-erro'],
    pronto: ['Pronto para encerrar', 'selo-ok'],
    encerrado: ['Encerrado', ''],
}
// texto do usuário dentro de uma frase: sem ponto final duplicado
const sem = (t) => (t ? String(t).trim().replace(/[.\s]+$/, '') : t)
const br = (v) => (v ? v.slice(0, 10).split('-').reverse().join('/') : '')
const agora = () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}
const VAZIO = { titulo: '', descoberto_em: '', ocorrido_em: '', descricao: '', dados_afetados: '', titulares: '', contencao: '', risco: '', justificativa: '', comunicado_anpd_em: '', protocolo_anpd: '', comunicado_titulares_em: '' }

/** passo a passo do que fazer; fica aberto até ter o primeiro incidente */
function Roteiro({ dias, aberto: inicial }) {
    const [aberto, setAberto] = useState(inicial)
    return (
        <div className="card mb-4">
            <button type="button" className="card-header bg-transparent border-0 text-start d-flex align-items-center gap-2 w-100" onClick={() => setAberto(!aberto)} aria-expanded={aberto}>
                <Icone nome="alerta" tamanho={18} /> <strong>Roteiro: o que fazer quando algo der errado com dados de clientes</strong>
                <Icone nome={aberto ? 'subir' : 'descer'} tamanho={16} className="ms-auto" />
            </button>
            {aberto && (
                <div className="card-body pt-0 small">
                    <p className="text-muted">Incidente é qualquer acesso, perda, vazamento ou alteração indevida de dados pessoais: celular do balcão perdido ou roubado, senha de funcionário vazada, acesso estranho no sistema, conta da Hostinger, do Inter ou do WhatsApp invadida, backup ou papel com dados de cliente extraviado, envio para a pessoa errada.</p>
                    <ol className="mb-3">
                        <li className="mb-2"><strong>Conter, na hora.</strong> Celular: Configurações › Aparelhos › Desativar (o token cai e o PIN para de valer no aparelho). Senha: troque a senha e, se for de funcionário, bloqueie o usuário. Conta de fornecedor: troque a senha e as chaves (Inter, WhatsApp) e avise o suporte. Papel: recolha o que puder.</li>
                        <li className="mb-2"><strong>Registrar aqui, no mesmo dia.</strong> O prazo conta de quando você soube. Anote o que aconteceu, quais dados e de quantas pessoas, e o que já fez.</li>
                        <li className="mb-2"><strong>Avaliar o risco.</strong> <em>Relevante</em> (precisa comunicar): pode causar dano às pessoas, por exemplo CPF, endereço, dados financeiros ou de pagamento, senhas, muita gente afetada, ou sinal de que alguém usou os dados. <em>Baixo</em> (só registrar): dado pouco sensível (nome e placa), poucas pessoas e contido rápido, sem sinal de uso.</li>
                        <li className="mb-2"><strong>Se relevante, comunicar em até {dias} dias úteis</strong> (prazo de pequeno porte): à ANPD, pelo formulário de comunicação de incidente no site da ANPD (gov.br/anpd), e aos clientes afetados, por WhatsApp ou e-mail. Os textos prontos aparecem no incidente.</li>
                        <li className="mb-2"><strong>Encerrar</strong> com a justificativa: por que comunicou ou não, o que foi feito e o que muda para não acontecer de novo.</li>
                    </ol>
                    <p className="mb-0 text-muted">Na dúvida entre baixo e relevante, trate como relevante ou fale com um advogado antes do fim do prazo. O registro fica guardado, comunicado ou não; não há exclusão.</p>
                </div>
            )}
        </div>
    )
}

/** textos prontos para a ANPD (o que preencher no formulário) e para os clientes */
function Avisos({ inc, empresa }) {
    const [copiado, setCopiado] = useState('')
    const copiar = (k, t) => navigator.clipboard?.writeText(t).then(() => { setCopiado(k); setTimeout(() => setCopiado(''), 2000) })
    const anpd = [
        `Controlador: ${empresa.nome} (agente de tratamento de pequeno porte).`,
        `Contato: ${empresa.contato || '(preencha)'}.`,
        `Data em que soube: ${br(inc.descoberto_em)} ${inc.descoberto_em.slice(11, 16)}.${inc.ocorrido_em ? ` Data do ocorrido: ${br(inc.ocorrido_em)}.` : ''}`,
        `O que aconteceu: ${sem(inc.descricao)}.`,
        `Dados afetados: ${sem(inc.dados_afetados) || '(preencha)'}.`,
        `Pessoas afetadas (estimativa): ${inc.titulares ?? '(preencha)'}.`,
        `Medidas tomadas: ${sem(inc.contencao) || '(preencha)'}.`,
        `Riscos para as pessoas: ${sem(inc.justificativa) || '(preencha)'}.`,
    ].join('\n')
    const cliente = `Olá. Aqui é do ${empresa.nome}. Queremos avisar que, em ${br(inc.descoberto_em)}, identificamos um problema de segurança que pode ter envolvido seus dados: ${sem(inc.dados_afetados) || '(quais dados)'}. ${sem(inc.descricao)}. Já tomamos estas medidas: ${sem(inc.contencao) || '(medidas)'}. Recomendamos ficar atento a mensagens ou cobranças estranhas em nosso nome. Dúvidas ou pedidos sobre seus dados: ${empresa.contato || '(contato)'}.`
    return (
        <div className="row g-3 mt-1">
            {[['anpd', 'Para a ANPD (o que preencher no formulário)', anpd], ['cliente', 'Para os clientes afetados (WhatsApp ou e-mail)', cliente]].map(([k, r, t]) => (
                <div key={k} className="col-lg-6">
                    <div className="d-flex align-items-center mb-1"><span className="small fw-semibold">{r}</span>
                        <button type="button" className="btn btn-sm btn-outline-secondary ms-auto" onClick={() => copiar(k, t)}>{copiado === k ? 'Copiado' : 'Copiar'}</button></div>
                    <textarea className="form-control small" readOnly rows={9} value={t} />
                </div>
            ))}
            <p className="small text-muted mb-0">Os textos usam o que está salvo; revise antes de enviar. "(preencha)" marca o que falta no registro.</p>
        </div>
    )
}

function Formulario({ inc, empresa, dias, onFechar }) {
    const novo = !inc
    const fechado = !!inc?.encerrado_em
    const form = useForm(novo ? { ...VAZIO, descoberto_em: agora() } : Object.fromEntries(Object.keys(VAZIO).map((k) => [k, inc[k] ?? ''])))
    const { errors } = usePage().props
    const set = (k) => (e) => form.setData(k, e?.target ? e.target.value : e)
    const salvar = (e) => {
        e.preventDefault()
        const op = { preserveScroll: true, onSuccess: () => (novo ? onFechar() : form.setDefaults()) }
        novo ? form.post('/incidentes', op) : form.put(`/incidentes/${inc.id}`, op)
    }
    const area = (k, rotulo, ajuda, altura = 90) => <Campo as="textarea" rotulo={rotulo} style={{ height: altura }} value={form.data[k]} onChange={set(k)} erro={form.errors[k]} ajuda={ajuda} disabled={fechado} />
    return (
        <form className="card mb-4" onSubmit={salvar} noValidate>
            <div className="card-header d-flex align-items-center gap-2">
                <strong>{novo ? 'Registrar incidente' : `Incidente ${inc.numero}`}</strong>
                {!novo && <span className={`selo ${ETAPA[inc.etapa][1]}`}>{ETAPA[inc.etapa][0]}</span>}
                {!novo && !fechado && <span className={`small ${inc.atrasado ? 'text-danger fw-semibold' : 'text-muted'}`}>prazo {br(inc.prazo)}{inc.atrasado && ' (vencido)'}</span>}
                <button type="button" className="btn-close ms-auto" aria-label="Fechar" onClick={onFechar} />
            </div>
            <div className="card-body">
                {errors.incidente && <div className="alert alert-warning small">{errors.incidente}</div>}
                <fieldset disabled={fechado}>
                    <h3 className="fs-6 fw-semibold">1. O que aconteceu</h3>
                    <div className="row g-2">
                        <div className="col-md-6"><Campo rotulo="Título" maxLength={120} value={form.data.titulo} onChange={set('titulo')} erro={form.errors.titulo} placeholder="Ex.: Celular do balcão roubado" /></div>
                        <div className="col-6 col-md-3"><CampoDataHora rotulo="Quando soube" value={form.data.descoberto_em} onChange={set('descoberto_em')} erro={form.errors.descoberto_em} /></div>
                        <div className="col-6 col-md-3"><CampoDataHora rotulo="Quando ocorreu" value={form.data.ocorrido_em} onChange={set('ocorrido_em')} erro={form.errors.ocorrido_em} /></div>
                    </div>
                    {area('descricao', 'Descrição', 'O que houve, como foi descoberto.')}
                    <div className="row g-2">
                        <div className="col-md-9">{area('dados_afetados', 'Dados afetados', 'Ex.: nome, placa e modelo dos mensalistas (sem CPF).', 70)}</div>
                        <div className="col-md-3"><Campo rotulo="Pessoas afetadas (estimativa)" type="number" min="0" value={form.data.titulares} onChange={set('titulares')} erro={form.errors.titulares} /></div>
                    </div>
                    <h3 className="fs-6 fw-semibold mt-2">2. Contenção</h3>
                    {area('contencao', 'O que foi feito', 'Ex.: aparelho desativado às 10:15; senha trocada.')}
                    <h3 className="fs-6 fw-semibold mt-2">3. Risco e decisão</h3>
                    <div className="d-flex flex-wrap gap-3 mb-2">
                        {[['baixo', 'Baixo: só registrar'], ['relevante', `Relevante: comunicar em até ${dias} dias úteis`]].map(([v, r]) => (
                            <div key={v} className="form-check"><input className="form-check-input" type="radio" id={`risco-${v}`} name="risco" checked={form.data.risco === v} onChange={() => form.setData('risco', v)} />
                                <label className="form-check-label" htmlFor={`risco-${v}`}>{r}</label></div>
                        ))}
                    </div>
                    {area('justificativa', 'Justificativa', 'Por que o risco é baixo ou relevante (que dano pode causar às pessoas).', 70)}
                    {form.data.risco === 'relevante' && (
                        <>
                            <h3 className="fs-6 fw-semibold mt-2">4. Comunicação</h3>
                            <div className="row g-2">
                                <div className="col-6 col-md-3"><CampoData rotulo="ANPD comunicada em" value={form.data.comunicado_anpd_em} onChange={set('comunicado_anpd_em')} erro={form.errors.comunicado_anpd_em} /></div>
                                <div className="col-6 col-md-3"><Campo rotulo="Protocolo da ANPD" maxLength={60} value={form.data.protocolo_anpd} onChange={set('protocolo_anpd')} erro={form.errors.protocolo_anpd} /></div>
                                <div className="col-6 col-md-3"><CampoData rotulo="Clientes avisados em" value={form.data.comunicado_titulares_em} onChange={set('comunicado_titulares_em')} erro={form.errors.comunicado_titulares_em} /></div>
                            </div>
                            {!novo && <Avisos inc={inc} empresa={empresa} />}
                        </>
                    )}
                </fieldset>
                {!novo && <p className="small text-muted mt-3 mb-0">Registrado por {inc.registrado_por || '—'}{fechado && ` · encerrado em ${inc.encerrado_em}`}.</p>}
            </div>
            {!fechado && (
                <div className="card-footer bg-transparent d-flex flex-wrap gap-2 justify-content-end p-3">
                    {!novo && <button type="button" className="btn btn-outline-secondary me-auto" disabled={form.processing || form.isDirty}
                        title={form.isDirty ? 'Salve antes de encerrar' : undefined}
                        onClick={() => router.post(`/incidentes/${inc.id}/encerrar`, {}, { preserveScroll: true })}>Encerrar</button>}
                    <button type="submit" className="btn btn-primary" disabled={form.processing}>{novo ? 'Registrar' : 'Salvar'}</button>
                </div>
            )}
        </form>
    )
}

/** Sistema › Incidentes (LGPD): roteiro, registro, prazo de comunicação e avisos prontos. */
export default function Incidentes({ incidentes, dias_uteis: dias, empresa_aviso: empresa }) {
    const [aberto, setAberto] = useState(null) // 'novo' | id
    const atual = typeof aberto === 'number' ? incidentes.find((i) => i.id === aberto) : null
    return (
        <>
            <Head title="Incidentes (LGPD)" />
            <TituloPagina titulo="Incidentes (LGPD)" descricao="Vazamento, perda ou acesso indevido a dados de clientes: o que fazer, o registro de cada caso e o prazo para comunicar.">
                {aberto !== 'novo' && <button type="button" className="btn btn-primary d-inline-flex align-items-center gap-2" onClick={() => setAberto('novo')}><Icone nome="mais" tamanho={16} /> Registrar incidente</button>}
            </TituloPagina>
            <Roteiro dias={dias} aberto={incidentes.length === 0} />
            {aberto && <Formulario key={aberto} inc={atual} empresa={empresa} dias={dias} onFechar={() => setAberto(null)} />}
            <div className="card">
                <div className="table-responsive">
                    <table className="table align-middle mb-0">
                        <thead><tr><th>Nº</th><th>Incidente</th><th>Soube em</th><th>Situação</th><th>Prazo</th></tr></thead>
                        <tbody>
                            {incidentes.length === 0 && <tr><td colSpan="5" className="text-center text-muted py-4">Nenhum incidente registrado.</td></tr>}
                            {incidentes.map((i) => (
                                <tr key={i.id} onClick={() => setAberto(i.id)} style={{ cursor: 'pointer' }} className={aberto === i.id ? 'table-active' : undefined}>
                                    <td>{i.numero}</td>
                                    <td>{i.titulo}<div className="small text-muted">{i.risco === 'relevante' ? 'Risco relevante' : i.risco === 'baixo' ? 'Risco baixo' : 'Risco não avaliado'}</div></td>
                                    <td className="text-nowrap">{br(i.descoberto_em)}</td>
                                    <td><span className={`selo ${ETAPA[i.etapa][1]}`}>{ETAPA[i.etapa][0]}</span></td>
                                    <td className={`text-nowrap ${i.atrasado ? 'text-danger fw-semibold' : ''}`}>{i.etapa === 'encerrado' || i.etapa === 'pronto' ? '—' : `${br(i.prazo)}${i.atrasado ? ' (vencido)' : ''}`}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    )
}
