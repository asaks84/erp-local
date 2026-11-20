import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'

/**
 * Lê o .pfx no navegador (node-forge, carregado só nesta tela) e devolve certificado e chave em PEM. A senha do .pfx
 * não sai do computador; o servidor recebe só o certificado e a chave (e guarda criptografado). Ler aqui também evita
 * o problema dos .pfx em formato antigo, que o servidor (OpenSSL 3) não abre.
 */
async function lerPfx(arquivo, senha) {
    const forge = (await import('node-forge')).default
    const bin = await new Promise((ok, erro) => {
        const r = new FileReader()
        r.onload = () => ok(r.result)
        r.onerror = () => erro(new Error('Não deu para ler o arquivo.'))
        r.readAsBinaryString(arquivo)
    })
    let p12
    try {
        p12 = forge.pkcs12.pkcs12FromAsn1(forge.asn1.fromDer(bin), senha)
    } catch {
        throw new Error('Senha errada ou arquivo que não é um certificado .pfx.')
    }
    const chaves = [...(p12.getBags({ bagType: forge.pki.oids.pkcs8ShroudedKeyBag })[forge.pki.oids.pkcs8ShroudedKeyBag] || []),
        ...(p12.getBags({ bagType: forge.pki.oids.keyBag })[forge.pki.oids.keyBag] || [])]
    const certs = p12.getBags({ bagType: forge.pki.oids.certBag })[forge.pki.oids.certBag] || []
    const chave = chaves[0]?.key
    if (!chave || !certs.length) throw new Error('O arquivo não tem o certificado e a chave juntos.')
    // o certificado da empresa é o que casa com a chave (o .pfx pode trazer a cadeia da certificadora junto)
    const mod = chave.n.toString(16)
    const cert = certs.map((c) => c.cert).find((c) => c?.publicKey?.n?.toString(16) === mod) || certs[0].cert
    return { certificado: forge.pki.certificateToPem(cert), chave: forge.pki.privateKeyToPem(chave) }
}

function Certificado({ certificado }) {
    const [arquivo, setArquivo] = useState(null)
    const [senha, setSenha] = useState('')
    const [erro, setErro] = useState(null)
    const [enviando, setEnviando] = useState(false)
    const [trocar, setTrocar] = useState(!certificado)
    const enviar = async (e) => {
        e.preventDefault()
        setErro(null)
        setEnviando(true)
        try {
            const pem = await lerPfx(arquivo, senha)
            router.post('/configuracoes/nfse/certificado', pem, {
                preserveScroll: true,
                onSuccess: () => { setArquivo(null); setSenha(''); setTrocar(false) },
                onError: (er) => setErro(er.certificado || er.chave || 'Não deu certo.'),
                onFinish: () => setEnviando(false),
            })
        } catch (x) {
            setErro(x.message)
            setEnviando(false)
        }
    }
    return (
        <div className="card mb-3">
            <div className="card-header">Certificado digital A1 (e-CNPJ)</div>
            <div className="card-body">
                {certificado && (
                    <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span className={`selo ${certificado.vencido ? 'selo-erro' : 'selo-ok'}`}>{certificado.vencido ? 'Vencido' : 'Guardado'}</span>
                        <span className="small">{certificado.titular}{certificado.validade && <> · vale até <strong>{certificado.validade}</strong></>}</span>
                        <span className="ms-auto d-flex gap-2">
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.post('/configuracoes/nfse/testar', {}, { preserveScroll: true })}>Testar conexão</button>
                            {!trocar && <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setTrocar(true)}>Trocar</button>}
                            <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => router.delete('/configuracoes/nfse/certificado', { preserveScroll: true })}>Tirar</button>
                        </span>
                    </div>
                )}
                {trocar && (
                    <form onSubmit={enviar}>
                        <p className="small text-muted">Escolha o arquivo do certificado (.pfx ou .p12) e digite a senha dele. O arquivo é aberto aqui no seu navegador: a senha não é enviada. Vão para o sistema só o certificado e a chave, guardados criptografados.</p>
                        <div className="row g-2">
                            <div className="col-md-6 mb-3">
                                <label className="form-label small mb-1" htmlFor="nfse-pfx">Arquivo .pfx</label>
                                <input id="nfse-pfx" type="file" accept=".pfx,.p12" className="form-control" onChange={(e) => setArquivo(e.target.files[0] || null)} />
                            </div>
                            <div className="col-md-6"><Campo rotulo="Senha do certificado" type="password" autoComplete="off" value={senha} onChange={(e) => setSenha(e.target.value)} /></div>
                        </div>
                        {erro && <div className="alert alert-danger py-2 small">{erro}</div>}
                        <button type="submit" className="btn btn-primary" disabled={!arquivo || !senha || enviando}>{enviando ? 'Lendo…' : 'Guardar certificado'}</button>
                    </form>
                )}
            </div>
        </div>
    )
}

/** código como o Emissor Nacional mostra: 110101 -> 11.01.01; 004 -> 11.01.01.004 */
const pontos = (n) => (n || '').replace(/\D/g, '').replace(/(\d{2})(?=\d)/g, '$1.')
const codigoMun = (nac, mun) => (mun ? `${pontos(nac)}.${String(mun).replace(/\D/g, '').slice(-3)}` : '')

/** passo do Emissor Nacional a que o cartão corresponde */
function Passo({ n, nome, onde, children }) {
    return (
        <div className="card mb-3">
            <div className="card-header d-flex align-items-center gap-2">
                <span className="badge rounded-pill text-bg-secondary">{n}</span>
                <span>{nome}</span>
                <span className="small text-muted ms-auto d-none d-sm-inline">no Emissor: {onde}</span>
            </div>
            <div className="card-body">{children}</div>
        </div>
    )
}

/** Configurações › Nota fiscal (NFS-e): emissão pelo Sistema Nacional (o mesmo do Emissor Nacional na web). */
export default function Nfse({ config, empresa, certificado, ambiente, faltando, opcoes, nomes, federal }) {
    const form = useForm({ ...config, c_trib_nac: pontos(config.c_trib_nac), c_trib_mun: codigoMun(config.c_trib_nac, config.c_trib_mun) })
    const e = form.errors
    const c = form.data
    const campo = (k, rotulo, extra = {}) => <Campo rotulo={rotulo} value={c[k] ?? ''} erro={e[k]} onChange={(ev) => form.setData(k, ev.target.value)} {...extra} />
    const nome = (k, v) => nomes[k]?.[String(v || '').replace(/\D/g, '').slice(k === 'c_trib_mun' ? -3 : 0)]
    const lista = (k, rotulo, mapa, extra = {}) => (
        <Campo as="select" rotulo={rotulo} value={c[k]} erro={e[k]} onChange={(ev) => form.setData(k, ev.target.value)} {...extra}>
            {Object.entries(mapa).map(([v, r]) => <option key={v} value={v}>{r}</option>)}
        </Campo>
    )
    const pctCampo = (k, rotulo, extra = {}) => campo(k, rotulo, { inputMode: 'decimal', ...extra, onChange: (ev) => form.setData(k, ev.target.value.replace(',', '.')) })
    const comBase = federal.com_base.includes(c.pis_cofins_cst)
    const salvar = (ev) => { ev.preventDefault(); form.put('/configuracoes/nfse', { preserveScroll: true }) }
    const trocarAmbiente = (a) => {
        if (a === 'producao' && !window.confirm('Passar para produção? A partir daí as notas valem de verdade (vão para o cliente e para a prefeitura).')) return
        router.put('/configuracoes/nfse/ambiente', { ambiente: a }, { preserveScroll: true })
    }

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Nota fiscal (NFS-e)" descricao="Emissão pelo Sistema Nacional da NFS-e, o mesmo do Emissor Nacional (nfse.gov.br). Os campos seguem a ordem e os nomes do Emissor. Uma nota por recebimento, em Gestão › Notas fiscais." />

            <div className={`alert ${faltando.length ? 'alert-warning' : ambiente === 'producao' ? 'alert-success' : 'alert-info'} d-flex flex-wrap align-items-center gap-2`}>
                <div className="me-auto">
                    {faltando.length ? <>Falta: {faltando.join(', ')}.</>
                        : ambiente === 'producao' ? <><strong>Em produção:</strong> as notas valem de verdade.</>
                            : <><strong>Em testes</strong> (produção restrita do Sistema Nacional): as notas não têm valor fiscal. Confira umas notas de teste antes de passar para produção.</>}
                </div>
                <div className="btn-group btn-group-sm" role="group" aria-label="Ambiente">
                    <button type="button" className={`btn ${ambiente !== 'producao' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => trocarAmbiente('restrita')}>Testes</button>
                    <button type="button" className={`btn ${ambiente === 'producao' ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => trocarAmbiente('producao')}>Produção</button>
                </div>
            </div>

            <Certificado certificado={certificado} />

            <form onSubmit={salvar} noValidate>
                <Passo n="1" nome="Informações gerais e emitente" onde="Pessoas">
                    <fieldset className="mb-3">
                        <legend className="form-label small mb-1">Preencher as informações IBS/CBS?</legend>
                        {[[false, 'Não'], [true, 'Sim']].map(([v, r]) => (
                            <div className="form-check form-check-inline" key={r}>
                                <input className="form-check-input" type="radio" name="ibs_cbs" id={`ibs-${r}`} checked={!!c.ibs_cbs === v} onChange={() => form.setData('ibs_cbs', v)} />
                                <label className="form-check-label" htmlFor={`ibs-${r}`}>{r}</label>
                            </div>
                        ))}
                        <div className="form-text">Hoje: Não. Quando passar a ser Sim (por opção ou obrigação), os códigos ficam no passo 3.</div>
                    </fieldset>
                    <div className="row g-2">
                        <div className="col-md-4"><Campo rotulo="CNPJ" value={empresa.cnpj || ''} disabled ajuda={empresa.cnpj ? undefined : 'Informe em Empresa e unidades.'} erro={empresa.cnpj ? undefined : 'Sem CNPJ'} /></div>
                        <div className="col-md-8"><Campo rotulo="Razão Social" value={empresa.nome || ''} disabled ajuda="Nome e endereço vão pelo cadastro do Sistema Nacional." /></div>
                        <div className="col-md-4">{campo('municipio_ibge', 'Município (código IBGE)', { inputMode: 'numeric', ajuda: 'Santos/SP = 3548500' })}</div>
                        <div className="col-md-4">{campo('im', 'Inscrição municipal', { inputMode: 'numeric', ajuda: 'Opcional (o Emissor não pede).' })}</div>
                        <div className="col-12">{lista('op_simples', 'Opção no Simples Nacional', opcoes.op_simples)}</div>
                        {c.op_simples === '3' && (
                            <div className="col-12">{lista('reg_ap_trib_sn', 'Regime de Apuração dos Tributos no Simples Nacional', opcoes.reg_ap, {
                                ajuda: c.reg_ap_trib_sn === '1' ? 'É o que você usa hoje: ISS e tributos federais no DAS.' : 'Opção para quem tira o ISS (ou tudo) do DAS. Só mude com orientação da contabilidade.',
                            })}</div>
                        )}
                    </div>
                    <p className="small text-muted mt-2 mb-0">Compras governamentais, destinatário e intermediário vão como no Emissor: “Não” e “não informado”. O tomador é o cliente do recebimento; declarar ou não os dados dele e as retenções (ISSQN e federais) você escolhe cliente a cliente, na hora de emitir.</p>
                </Passo>

                <Passo n="2" nome="Serviço/fornecimento prestado" onde="Serviço">
                    <div className="row g-2">
                        <div className="col-md-4">{campo('c_trib_nac', 'Código de Tributação Nacional', { inputMode: 'numeric', maxLength: 8, placeholder: '11.01.01', ajuda: nome('c_trib_nac', c.c_trib_nac) })}</div>
                        <div className="col-md-4">{campo('c_trib_mun', 'Código Complementar Municipal', { inputMode: 'numeric', maxLength: 12, placeholder: '11.01.01.004', ajuda: nome('c_trib_mun', c.c_trib_mun) })}</div>
                        <div className="col-md-4">{campo('c_nbs', 'Item da NBS', { inputMode: 'numeric', maxLength: 11, placeholder: '106043000', ajuda: nome('c_nbs', c.c_nbs) })}</div>
                        <div className="col-12">{campo('descricao', 'Descrição do Serviço/Fornecimento', { maxLength: 300, ajuda: 'Primeira linha da descrição; abaixo vem uma linha por conta paga.' })}</div>
                        <div className="col-12">
                            <div className="form-check form-switch">
                                <input className="form-check-input" type="checkbox" role="switch" id="especificar" checked={!!c.especificar_servicos} onChange={(ev) => form.setData('especificar_servicos', ev.target.checked)} />
                                <label className="form-check-label" htmlFor="especificar">Especificar cada serviço na descrição</label>
                            </div>
                            <div className="border rounded bg-body-tertiary small p-2 mt-1 font-monospace">
                                {(c.descricao || 'Serviço de estacionamento')}<br />
                                ABC1D23 - Fiat Uno - Mensalidade 11/2026 - R$ 180,00<br />
                                ABC1D23 - Fiat Uno - {c.especificar_servicos ? 'Estadia - ' : ''}R$ 25,00<br />
                                ABC1D23 - Fiat Uno - {c.especificar_servicos ? 'Lavagem simples - ' : ''}R$ 40,00
                            </div>
                            <div className="form-text">{c.especificar_servicos ? 'Cada linha diz o serviço.' : 'Só a mensalidade é nomeada; estadia, lavagem e outros saem só com placa, veículo e valor (para não citar na nota serviço sem CNAE, por exemplo).'}</div>
                        </div>
                    </div>
                    <p className="small text-muted mt-2 mb-0">Imunidade, exportação ou não incidência: “Não”. Local da prestação e município de incidência: o município do emitente.</p>
                </Passo>

                <Passo n="3" nome="Tributação municipal e federal" onde="Valores">
                    <div className="row g-2">
                        <div className="col-md-6"><Campo rotulo="Tributação do ISSQN sobre o serviço prestado" value="Operação Tributável" disabled /></div>
                        <div className="col-md-6">{lista('reg_esp_trib', 'Regime Especial de Tributação', opcoes.reg_esp)}</div>
                    </div>
                    <div className="alert alert-light border small mt-2 mb-0">
                        As duas alíquotas que a contabilidade manda todo mês (<strong>alíquota de ISS</strong> e <strong>valor aproximado dos tributos</strong>) ficam no alto de <Link href="/notas-fiscais">Gestão › Notas fiscais</Link>, por competência.
                        A de ISS só entra na nota quando o ISS é retido pelo tomador (você marca na hora de emitir); a dos tributos vai em “Informar alíquota do Simples Nacional”.
                        Exigibilidade suspensa, benefício municipal e dedução: “Não”.
                    </div>

                    <h3 className="fs-6 mt-4 mb-2">Tributação federal</h3>
                    <div className="row g-2">
                        <div className="col-md-6">{lista('pis_cofins_cst', 'Situação Tributária do PIS/COFINS', federal.cst, { ajuda: c.pis_cofins_cst === '00' ? 'No Simples Nacional: 00 - Nenhum (PIS e COFINS vão no DAS).' : comBase ? 'Leva base de cálculo, alíquotas e valores de PIS e COFINS.' : undefined })}</div>
                        <div className="col-12" />
                        <div className="col-6 col-md-4">{pctCampo('p_aliq_pis', 'PIS (%)', { ajuda: comBase ? 'Presumido 0,65 · real 1,65' : 'Usada se o cliente retiver PIS (0,65).' })}</div>
                        <div className="col-6 col-md-4">{pctCampo('p_aliq_cofins', 'COFINS (%)', { ajuda: comBase ? 'Presumido 3,00 · real 7,60' : 'Usada se o cliente retiver COFINS (3,00).' })}</div>
                        <div className="col-6 col-md-4">{pctCampo('p_ret_csll', 'CSLL (%)', { ajuda: 'Usada se o cliente retiver CSLL (1,00).' })}</div>
                        <div className="col-6 col-md-4">{pctCampo('p_ret_irrf', 'IRRF (%)', { ajuda: 'Usada se o cliente retiver IRRF (1,50).' })}</div>
                        <div className="col-6 col-md-4">{pctCampo('p_ret_cp', 'Contribuição previdenciária (%)', { ajuda: 'Usada se o cliente retiver.' })}</div>
                    </div>
                    <p className="small text-muted mt-2 mb-0">
                        No Simples: <strong>00 - Nenhum</strong>. As outras situações ficam para se mudar de regime (confirme com a contabilidade).
                        <strong> Retenção</strong> (PIS/COFINS/CSLL, IRRF, previdenciária) é escolhida cliente a cliente, na hora de emitir; aqui ficam só as alíquotas usadas no cálculo.
                    </p>

                    {c.ibs_cbs && (
                        <>
                            <h3 className="fs-6 mt-4 mb-2">IBS/CBS</h3>
                            <div className="row g-2">
                                <div className="col-md-4">{campo('ibs_cbs_cst', 'Código de Situação Tributária', { inputMode: 'numeric', maxLength: 3, placeholder: '000' })}</div>
                                <div className="col-md-8">{campo('ibs_cbs_class', 'Código de Classificação Tributária', { inputMode: 'numeric', maxLength: 6, placeholder: '000001' })}</div>
                            </div>
                            <div className="alert alert-warning small mt-2 mb-0">Os códigos ficam guardados, mas a emissão com IBS/CBS por aqui ainda não está pronta: o envio só será liberado depois de conferido o leiaute oficial em vigor. Até lá, com “Sim”, emita pelo Emissor Nacional.</div>
                        </>
                    )}
                </Passo>

                <Passo n="4" nome="Série e número da DPS" onde="Pessoas › Informar série e número da DPS">
                    <div className="row g-2">
                        <div className="col-md-4">{campo('serie', 'Série da DPS', { inputMode: 'numeric', ajuda: 'Use uma série diferente da que o Emissor Nacional usa na web, para os números não baterem.' })}</div>
                        <div className="col-md-8 small text-muted align-self-center">O número da DPS é contado pelo sistema, por série.</div>
                    </div>
                </Passo>
                <div className="d-flex justify-content-end"><button type="submit" className="btn btn-primary" disabled={form.processing}>Salvar</button></div>
            </form>
        </div>
    )
}
