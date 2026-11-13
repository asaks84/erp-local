import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import { Regra } from '../../Components/RegrasBoleto'
import { data as fData, dataHora } from '../../lib/formato'

function Arquivo({ rotulo, id, tem, onChange, erro, aceita }) {
    return (
        <div className="mb-3">
            <label className="form-label small mb-1" htmlFor={id}>{rotulo} {tem && <span className="selo selo-ok ms-1">Enviado</span>}</label>
            <input id={id} type="file" accept={aceita} className={`form-control${erro ? ' is-invalid' : ''}`} onChange={(e) => onChange(e.target.files[0] || null)} />
            {erro && <div className="invalid-feedback">{erro}</div>}
            {tem && !erro && <div className="form-text">Já está guardado. Envie de novo só para trocar.</div>}
        </div>
    )
}

function Credenciais({ i }) {
    const form = useForm({ ambiente: i.ambiente, conta_corrente: i.conta_corrente || '', client_id: '', client_secret: '', certificado: null, chave: null })
    const e = form.errors
    return (
        <form className="card mb-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/configuracoes/inter/credenciais', { preserveScroll: true, onSuccess: () => form.reset('client_id', 'client_secret', 'certificado', 'chave') }) }}>
            <div className="card-header">Credenciais da integração</div>
            <div className="card-body">
                <p className="small text-muted">No Internet Banking do Inter Empresas (computador), crie a integração com as permissões de <strong>emitir e cancelar boletos</strong> e <strong>consultar boletos</strong>. Baixe o arquivo que o Inter entrega, descompacte e envie aqui o certificado (.crt) e a chave (.key). Tudo fica guardado criptografado; ninguém consegue ver de novo pela tela.</p>
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="Client ID" value={form.data.client_id} onChange={(ev) => form.setData('client_id', ev.target.value)} erro={e.client_id} autoComplete="off" ajuda={i.client_id ? `Guardado: ${i.client_id}. Vazio = mantém.` : null} /></div>
                    <div className="col-sm-6"><Campo rotulo="Client Secret" type="password" value={form.data.client_secret} onChange={(ev) => form.setData('client_secret', ev.target.value)} erro={e.client_secret} autoComplete="new-password" ajuda={i.tem_secret ? 'Guardado. Vazio = mantém.' : null} /></div>
                    <div className="col-sm-6"><Arquivo rotulo="Certificado (.crt)" id="inter-crt" aceita=".crt,.pem,.cer" tem={i.tem_certificado} onChange={(f) => form.setData('certificado', f)} erro={e.certificado} /></div>
                    <div className="col-sm-6"><Arquivo rotulo="Chave (.key)" id="inter-key" aceita=".key,.pem" tem={i.tem_chave} onChange={(f) => form.setData('chave', f)} erro={e.chave} /></div>
                    <div className="col-sm-6">
                        <Campo as="select" rotulo="Ambiente" value={form.data.ambiente} onChange={(ev) => form.setData('ambiente', ev.target.value)} erro={e.ambiente}>
                            <option value="producao">Produção (boletos de verdade)</option>
                            <option value="sandbox">Testes (sandbox do Inter)</option>
                        </Campo>
                    </div>
                    <div className="col-sm-6"><Campo rotulo="Conta corrente (opcional)" value={form.data.conta_corrente} onChange={(ev) => form.setData('conta_corrente', ev.target.value)} erro={e.conta_corrente} inputMode="numeric" ajuda="Só se a empresa tiver mais de uma conta no Inter." /></div>
                </div>
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar credenciais</button>
            </div>
        </form>
    )
}

function Regras({ regras }) {
    const form = useForm(regras)
    return (
        <form className="card mb-4" onSubmit={(e) => { e.preventDefault(); form.put('/configuracoes/inter/regras', { preserveScroll: true }) }}>
            <div className="card-header">Regras do boleto</div>
            <div className="card-body">
                <p className="small text-muted">Valem para os boletos gerados daqui em diante. O Inter calcula e cobra; quando o boleto é pago, a multa e os juros entram como receita e o desconto como desconto.</p>
                <Regra nome="multa" rotulo="Multa por atraso" form={form} ajuda="Para pessoa física, o Código de Defesa do Consumidor limita a 2%." />
                <Regra nome="juros" rotulo="Juros por atraso" form={form} />
                <Regra nome="desconto" rotulo="Desconto por pagar antes" form={form} />
                {form.data.boleto_desconto_tipo !== 'nenhum' && (
                    <div className="row g-2">
                        <div className="col-sm-6"><Campo rotulo="Vale até quantos dias antes do vencimento" type="number" min="0" max="60" value={form.data.boleto_desconto_dias} onChange={(e) => form.setData('boleto_desconto_dias', e.target.value)} erro={form.errors.boleto_desconto_dias} ajuda="0 = até o dia do vencimento." /></div>
                    </div>
                )}
                <div className="row g-2">
                    <div className="col-sm-6"><Campo rotulo="Baixa automática (dias depois do vencimento)" type="number" min="0" max="60" value={form.data.boleto_baixa_dias} onChange={(e) => form.setData('boleto_baixa_dias', e.target.value)} erro={form.errors.boleto_baixa_dias} ajuda="Depois disso o boleto não pode mais ser pago; a dívida continua no sistema e entra na próxima cobrança. Máximo 60." /></div>
                </div>
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end">
                <button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar regras</button>
            </div>
        </form>
    )
}

export default function Inter({ integracao: i, regras, boletos }) {
    const [testando, setTestando] = useState(false)
    const completo = i.client_id && i.tem_secret && i.tem_certificado && i.tem_chave
    const vence = i.certificado_validade ? Math.ceil((new Date(i.certificado_validade + 'T12:00:00') - new Date()) / 86400000) : null
    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <div style={{ maxWidth: 820 }}>
                <TituloPagina titulo="Banco Inter" descricao="Boleto com Pix gerado a partir da cobrança. O sistema consulta o Inter a cada 10 minutos: boleto pago dá baixa sozinho." />
                <div className="card mb-4">
                    <div className="card-body d-flex flex-wrap align-items-center gap-3">
                        <div className="flex-grow-1">
                            {i.ativo ? <span className="selo selo-ok">Ligado</span> : <span className="selo">Desligado</span>}
                            {i.ambiente === 'sandbox' && <span className="selo selo-aviso ms-1">Ambiente de testes</span>}
                            <div className="small text-muted mt-1">
                                {i.testado_em ? `Último teste: ${dataHora(i.testado_em)}` : 'Ainda não testado.'}
                                {i.certificado_validade && ` · certificado vale até ${fData(i.certificado_validade)}`}
                                {i.ativo && ` · ${boletos.ativos} boleto(s) em aberto`}
                            </div>
                        </div>
                        <button type="button" className="btn btn-outline-primary" disabled={!completo || testando}
                            onClick={() => { setTestando(true); router.post('/configuracoes/inter/testar', {}, { preserveScroll: true, onFinish: () => setTestando(false) }) }}>
                            {testando ? 'Testando…' : 'Testar conexão'}
                        </button>
                        {i.ativo && <button type="button" className="btn btn-outline-secondary" onClick={() => router.post('/configuracoes/inter/desligar', {}, { preserveScroll: true })}>Desligar</button>}
                    </div>
                    {i.ultimo_erro && <div className="card-footer bg-transparent small text-danger-emphasis">{i.ultimo_erro}</div>}
                    {vence !== null && vence <= 30 && <div className="card-footer bg-transparent small text-danger-emphasis">{vence < 0 ? 'O certificado venceu.' : `O certificado vence em ${vence} dia(s).`} Gere outro no Internet Banking do Inter e envie aqui.</div>}
                </div>
                <Credenciais i={i} />
                <Regras regras={regras} />
            </div>
        </div>
    )
}
