import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import { dataHora } from '../../lib/formato'

const SITUACAO = { APPROVED: ['Aprovado', 'selo-ok'], PENDING: ['Em análise', 'selo-aviso'], REJECTED: ['Recusado', 'selo-erro'], PAUSED: ['Pausado', 'selo-aviso'], DISABLED: ['Desativado', 'selo-erro'] }
const quando = (d) => (d < 0 ? `${-d} dia(s) antes do vencimento` : d === 0 ? 'No dia do vencimento' : `${d} dia(s) depois do vencimento`)

function Copiar({ texto }) {
    const [ok, setOk] = useState(false)
    return <button type="button" className="btn btn-sm btn-outline-secondary text-nowrap" onClick={() => navigator.clipboard?.writeText(texto).then(() => { setOk(true); setTimeout(() => setOk(false), 1500) })}>{ok ? 'Copiado' : 'Copiar'}</button>
}

function Credenciais({ i }) {
    const form = useForm({ phone_number_id: i.phone_number_id || '', waba_id: i.waba_id || '', token: '', app_secret: '' })
    const e = form.errors
    return (
        <form className="card h-100" onSubmit={(ev) => { ev.preventDefault(); form.post('/configuracoes/whatsapp/credenciais', { preserveScroll: true, onSuccess: () => form.reset('token', 'app_secret') }) }}>
            <div className="card-header">Credenciais da API oficial (Meta)</div>
            <div className="card-body">
                <p className="small text-muted">Os dados vêm do aplicativo criado em developers.facebook.com (WhatsApp › Configuração da API). O token e a chave secreta ficam guardados criptografados; ninguém consegue ver de novo pela tela.</p>
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="ID do número de telefone" value={form.data.phone_number_id} onChange={(ev) => form.setData('phone_number_id', ev.target.value.replace(/\D/g, ''))} erro={e.phone_number_id} inputMode="numeric" autoComplete="off" /></div>
                    <div className="col-sm-6"><Campo rotulo="ID da conta do WhatsApp Business" value={form.data.waba_id} onChange={(ev) => form.setData('waba_id', ev.target.value.replace(/\D/g, ''))} erro={e.waba_id} inputMode="numeric" autoComplete="off" /></div>
                    <div className="col-12"><Campo rotulo="Token de acesso" type="password" value={form.data.token} onChange={(ev) => form.setData('token', ev.target.value)} erro={e.token} autoComplete="new-password"
                        ajuda={i.tem_token ? 'Guardado. Vazio = mantém.' : 'Para uso contínuo, gere o token de um usuário do sistema, sem expiração. O token temporário da tela de testes vence em horas.'} /></div>
                    <div className="col-12"><Campo rotulo="Chave secreta do aplicativo (opcional)" type="password" value={form.data.app_secret} onChange={(ev) => form.setData('app_secret', ev.target.value)} erro={e.app_secret} autoComplete="new-password"
                        ajuda={i.tem_app_secret ? 'Guardada. Vazio = mantém.' : 'Configurações do app › Básico. Com ela, o sistema confere que os avisos de entrega vêm mesmo da Meta.'} /></div>
                </div>
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar credenciais</button>
            </div>
        </form>
    )
}

function Regua({ regua }) {
    const form = useForm({ passos: regua.passos, hora: regua.hora, automatico: regua.automatico })
    const mudar = (i, campos) => form.setData('passos', form.data.passos.map((p, j) => (j === i ? { ...p, ...campos } : p)))
    return (
        <form className="card h-100" onSubmit={(e) => { e.preventDefault(); form.put('/configuracoes/whatsapp/regua', { preserveScroll: true }) }}>
            <div className="card-header">Régua de cobrança</div>
            <div className="card-body">
                <p className="small text-muted">Cada cobrança em aberto recebe um aviso em cada passo marcado, uma vez só. Para de avisar quando é paga ou cancelada. Com boleto, conta o vencimento do boleto.</p>
                {form.data.passos.map((p, i) => (
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-2" key={i}>
                        <div className="form-check form-switch mb-0">
                            <input className="form-check-input" type="checkbox" role="switch" id={`passo-${i}`} checked={p.ativo} onChange={(e) => mudar(i, { ativo: e.target.checked })} />
                        </div>
                        <input type="number" className="form-control" style={{ maxWidth: 90 }} min="-30" max="90" value={p.dias} aria-label="Dias em relação ao vencimento" onChange={(e) => mudar(i, { dias: e.target.value })} />
                        <label className="small flex-grow-1" htmlFor={`passo-${i}`}>{quando(Number(p.dias))}</label>
                        <button type="button" className="btn btn-sm btn-link text-danger p-0" onClick={() => form.setData('passos', form.data.passos.filter((_, j) => j !== i))} disabled={form.data.passos.length <= 1}>Tirar</button>
                    </div>
                ))}
                {Object.keys(form.errors).filter((k) => k.startsWith('passos')).slice(0, 1).map((k) => <div className="text-danger small mb-2" key={k}>{form.errors[k]}</div>)}
                <button type="button" className="btn btn-sm btn-outline-secondary mb-3" disabled={form.data.passos.length >= 8}
                    onClick={() => form.setData('passos', [...form.data.passos, { dias: Math.max(...form.data.passos.map((p) => Number(p.dias))) + 7, ativo: true }])}>Adicionar passo</button>
                <p className="form-text mt-0">Número negativo = dias antes do vencimento; 0 = no dia; positivo = dias depois.</p>
                <div className="row g-2 align-items-center">
                    <div className="col-sm-5"><Campo rotulo="Enviar a partir de" type="time" min="08:00" max="19:00" value={form.data.hora} onChange={(e) => form.setData('hora', e.target.value)} erro={form.errors.hora} /></div>
                    <div className="col-sm-7">
                        <div className="form-check form-switch">
                            <input className="form-check-input" type="checkbox" role="switch" id="wa-auto" checked={form.data.automatico} onChange={(e) => form.setData('automatico', e.target.checked)} />
                            <label className="form-check-label" htmlFor="wa-auto">Enviar sozinho (precisa da API ligada)</label>
                        </div>
                        <div className="form-text">Desligado, os avisos ficam em Cobranças › Avisos para você enviar.</div>
                    </div>
                </div>
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar régua</button>
            </div>
        </form>
    )
}

export default function WhatsApp({ integracao: i, regua, modelos, erro_modelos: erroModelos, privacidade_url: privacidade }) {
    const [ocupado, setOcupado] = useState('')
    const completo = i.phone_number_id && i.waba_id && i.tem_token
    const acao = (nome, url) => { setOcupado(nome); router.post(url, {}, { preserveScroll: true, onFinish: () => setOcupado('') }) }
    const faltam = modelos.filter((m) => !m.situacao).length
    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="WhatsApp" descricao="Avisos de cobrança pelo WhatsApp. Sem a API ligada, os avisos ficam em Cobranças › Avisos e você envia um a um; com a API oficial da Meta, podem sair sozinhos." />
            <div className="card mb-4">
                <div className="card-body d-flex flex-wrap align-items-center gap-3">
                    <div className="flex-grow-1">
                        {i.ativo ? <span className="selo selo-ok">API ligada</span> : <span className="selo">API desligada (envio manual)</span>}
                        <div className="small text-muted mt-1">
                            {i.ativo && i.numero ? `${i.nome || ''} ${i.numero} · ` : ''}{i.testado_em ? `Último teste: ${dataHora(i.testado_em)}` : 'Ainda não testado.'}
                        </div>
                    </div>
                    <button type="button" className="btn btn-outline-primary" disabled={!completo || ocupado === 'testar'} onClick={() => acao('testar', '/configuracoes/whatsapp/testar')}>{ocupado === 'testar' ? 'Testando…' : 'Testar conexão'}</button>
                    {i.ativo && <button type="button" className="btn btn-outline-secondary" onClick={() => acao('desligar', '/configuracoes/whatsapp/desligar')}>Desligar</button>}
                </div>
                {i.ultimo_erro && <div className="card-footer bg-transparent small text-danger-emphasis">{i.ultimo_erro}</div>}
            </div>
            <div className="row g-4">
                <div className="col-xl-6"><Credenciais i={i} /></div>
                <div className="col-xl-6"><Regua regua={regua} /></div>
                <div className="col-xl-6">
                    <div className="card h-100">
                        <div className="card-header d-flex align-items-center">
                            <span className="flex-grow-1">Modelos de mensagem</span>
                            {i.ativo && faltam > 0 && <button type="button" className="btn btn-sm btn-primary" disabled={ocupado === 'modelos'} onClick={() => acao('modelos', '/configuracoes/whatsapp/modelos')}>{ocupado === 'modelos' ? 'Enviando…' : 'Criar na Meta'}</button>}
                        </div>
                        <div className="card-body">
                            <p className="small text-muted">Pela API, a mensagem precisa ser um modelo aprovado pela Meta (leva de minutos a horas). O texto é o mesmo do envio manual. Cada aviso leva o botão "Ver cobrança", com o boleto e o Pix.</p>
                            {erroModelos && <div className="alert alert-danger small">{erroModelos}</div>}
                            {modelos.map((m) => (
                                <div className="mb-3" key={m.nome}>
                                    <div className="d-flex align-items-center gap-2">
                                        <strong className="small">{m.rotulo}</strong>
                                        {m.situacao ? <span className={`selo ${(SITUACAO[m.situacao] || [])[1] || ''}`}>{(SITUACAO[m.situacao] || [m.situacao])[0]}</span> : <span className="selo">{i.ativo ? 'Ainda não criado' : 'Ligue a API para ver'}</span>}
                                    </div>
                                    <div className="small text-muted">{m.texto.replace('{{1}}', 'Maria').replace('{{2}}', 'Local Estacionamento').replace('{{3}}', 'R$ 250,00').replace('{{4}}', '10/12/2026')}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
                <div className="col-xl-6">
                    <div className="card h-100">
                        <div className="card-header">Para colar no aplicativo da Meta</div>
                        <div className="card-body">
                            <p className="small text-muted">Em WhatsApp › Configuração, no item "Configurar webhooks", para o sistema saber se cada aviso foi entregue e lido. Assine o campo <strong>messages</strong>.</p>
                            <div className="mb-3">
                                <div className="small fw-semibold">URL de callback</div>
                                <div className="d-flex gap-2 align-items-center"><code className="flex-grow-1 text-break">{i.webhook_url}</code><Copiar texto={i.webhook_url} /></div>
                            </div>
                            <div className="mb-3">
                                <div className="small fw-semibold">Verificar token</div>
                                {i.webhook_token
                                    ? <div className="d-flex gap-2 align-items-center"><code className="flex-grow-1 text-break">{i.webhook_token}</code><Copiar texto={i.webhook_token} /></div>
                                    : <div className="small text-muted">Aparece depois de salvar as credenciais.</div>}
                            </div>
                            <div>
                                <div className="small fw-semibold">URL da Política de Privacidade (para publicar o app)</div>
                                <div className="d-flex gap-2 align-items-center"><code className="flex-grow-1 text-break">{privacidade}</code><Copiar texto={privacidade} /></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
