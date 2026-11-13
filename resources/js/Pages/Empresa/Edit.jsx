import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import CampoValor from '../../Components/CampoValor'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Campo from '../../Components/Campo'
import Icone from '../../Components/Icone'
import { cnpj, telefone } from '../../lib/mascaras'

function PadroesMensalidade({ config }) {
    const { data, setData, put, processing, errors } = useForm({ ...config, periodos: config.periodos })
    return (
        <form className="card mt-4" noValidate onSubmit={(e) => { e.preventDefault(); put('/empresa/config', { preserveScroll: true }) }}>
            <div className="card-header">Padrões de mensalidade</div>
            <div className="card-body">
                <Campo rotulo="Dia de vencimento padrão (cliente novo)" type="number" min="1" max="31" value={data.dia_vencimento_padrao} erro={errors.dia_vencimento_padrao}
                    onChange={(e) => setData('dia_vencimento_padrao', e.target.value)} />
                <label className="form-label fw-semibold">Períodos de mensalidade</label>
                {data.periodos.map((p, i) => (
                    <div className="input-group mb-2" key={i}>
                        <input className={`form-control${errors[`periodos.${i}`] ? ' is-invalid' : ''}`} value={p} maxLength={30} aria-label={`Período ${i + 1}`}
                            onChange={(e) => setData('periodos', data.periodos.map((x, j) => (j === i ? e.target.value : x)))} />
                        {data.periodos.length > 1 && <button type="button" className="btn btn-outline-secondary" onClick={() => setData('periodos', data.periodos.filter((_, j) => j !== i))}>Tirar</button>}
                    </div>
                ))}
                <button type="button" className="btn btn-sm btn-outline-secondary mb-3" onClick={() => setData('periodos', [...data.periodos, ''])}>+ Período</button>
                <div className="row g-2">
                    <div className="col-5">
                        <Campo as="select" rotulo="Taxa de pausa" value={data.pausa_taxa_tipo} onChange={(e) => setData('pausa_taxa_tipo', e.target.value)}>
                            <option value="valor">Valor fixo</option>
                            <option value="pct">% da mensalidade</option>
                        </Campo>
                    </div>
                    <div className="col-7">
                        <CampoValor rotulo="Taxa padrão por mês" prefixo={data.pausa_taxa_tipo === 'pct' ? '%' : 'R$'} value={data.pausa_taxa_valor} erro={errors.pausa_taxa_valor}
                            onChange={(v) => setData('pausa_taxa_valor', v)} ajuda="Sugestão ao pausar; 0 = pausa grátis." />
                    </div>
                </div>
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end p-3">
                <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
            </div>
        </form>
    )
}

function InstrucoesCobranca({ texto }) {
    const { data, setData, put, processing, errors } = useForm({ cobranca_instrucoes: texto || '' })
    return (
        <form className="card mt-4" noValidate onSubmit={(e) => { e.preventDefault(); put('/empresa/cobranca', { preserveScroll: true }) }}>
            <div className="card-header">Cobrança impressa</div>
            <div className="card-body">
                <Campo as="textarea" rotulo="Como pagar (sai no pé da cobrança)" style={{ height: 110 }} maxLength={500} value={data.cobranca_instrucoes}
                    onChange={(e) => setData('cobranca_instrucoes', e.target.value)} erro={errors.cobranca_instrucoes}
                    ajuda="Ex.: chave Pix, banco e conta, recado para o cliente." className="mb-0" />
            </div>
            <div className="card-footer bg-transparent d-flex justify-content-end p-3">
                <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
            </div>
        </form>
    )
}

function LinkCadastro({ link }) {
    const [qr, setQr] = useState(null)
    useEffect(() => { if (link) QRCode.toDataURL(link, { width: 480, margin: 1 }).then(setQr) }, [link])
    return (
        <div className="card mt-4">
            <div className="card-header">Cadastro público (QR Code do balcão)</div>
            <div className="card-body">
                {link ? (
                    <>
                        <p className="mb-2">O cliente escaneia, preenche e o cadastro cai na <Link href="/pendentes">Fila de espera</Link>.</p>
                        <div className="d-flex flex-wrap gap-3 align-items-center">
                            {qr && <img src={qr} alt="QR Code do cadastro" width="160" height="160" style={{ background: '#fff', padding: 8, borderRadius: 8 }} />}
                            <div className="text-break small">
                                <a href={link} target="_blank" rel="noreferrer">{link}</a>
                                <div className="mt-2 d-flex gap-2 flex-wrap">
                                    {qr && <a className="btn btn-sm btn-outline-secondary" href={qr} download="qrcode-cadastro.png">Baixar QR Code</a>}
                                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => router.post('/empresa/link-cadastro', {}, { preserveScroll: true })}>Trocar link</button>
                                </div>
                            </div>
                        </div>
                    </>
                ) : (
                    <>
                        <p>O link de cadastro ainda não foi criado.</p>
                        <button type="button" className="btn btn-primary" onClick={() => router.post('/empresa/link-cadastro', {}, { preserveScroll: true })}>Criar link e QR Code</button>
                    </>
                )}
            </div>
        </div>
    )
}

export default function EmpresaEdit({ empresa, unidades, config, link_cadastro, cobranca_instrucoes }) {
    const { data, setData, put, processing, errors } = useForm({
        nome: empresa.nome || '',
        razao_social: empresa.razao_social || '',
        documento: empresa.documento || '',
        telefone: empresa.telefone || '',
        email: empresa.email || '',
    })

    function salvar(e) {
        e.preventDefault()
        put('/empresa', { preserveScroll: true })
    }

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Empresa e unidades" />
            <div className="row g-4">
                <div className="col-lg-6">
                    <form onSubmit={salvar} className="card" noValidate>
                        <div className="card-header">Dados da empresa</div>
                        <div className="card-body">
                            <Campo rotulo="Nome (aparece no sistema)" value={data.nome} erro={errors.nome} maxLength={80}
                                onChange={(e) => setData('nome', e.target.value)} />
                            <Campo rotulo="Razão social" value={data.razao_social} erro={errors.razao_social} maxLength={120}
                                onChange={(e) => setData('razao_social', e.target.value)} />
                            <Campo rotulo="CNPJ" value={cnpj(data.documento)} erro={errors.documento} inputMode="numeric"
                                onChange={(e) => setData('documento', e.target.value)} />
                            <div className="row g-2">
                                <div className="col-sm-6">
                                    <Campo rotulo="Telefone" value={telefone(data.telefone)} erro={errors.telefone} inputMode="tel"
                                        onChange={(e) => setData('telefone', e.target.value)} />
                                </div>
                                <div className="col-sm-6">
                                    <Campo rotulo="E-mail" type="email" value={data.email} erro={errors.email} maxLength={120}
                                        onChange={(e) => setData('email', e.target.value)} />
                                </div>
                            </div>
                        </div>
                        <div className="card-footer bg-transparent d-flex justify-content-end p-3">
                            <button type="submit" className="btn btn-primary" disabled={processing}>Salvar</button>
                        </div>
                    </form>
                    <PadroesMensalidade config={config} />
                </div>

                <div className="col-lg-6">
                    <div className="card">
                        <div className="card-header d-flex align-items-center">
                            <span className="flex-grow-1">Unidades (pátios)</span>
                            <Link href="/unidades/nova" className="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1">
                                <Icone nome="mais" tamanho={16} /> Nova unidade
                            </Link>
                        </div>
                        <div className="table-responsive">
                            <table className="table table-hover align-middle">
                                <thead><tr><th>Unidade</th><th>Endereço</th><th>Situação</th><th /></tr></thead>
                                <tbody>
                                    {unidades.map((u) => (
                                        <tr key={u.id}>
                                            <td className="fw-semibold">{u.nome}</td>
                                            <td className="text-muted">{u.endereco}</td>
                                            <td>{u.ativa ? <span className="selo selo-ok">Ativa</span> : <span className="selo">Inativa</span>}</td>
                                            <td className="text-end">
                                                <Link href={`/unidades/${u.id}`} className="btn btn-sm btn-outline-secondary" aria-label={`Editar ${u.nome}`}>
                                                    <Icone nome="lapis" tamanho={16} />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <LinkCadastro link={link_cadastro} />
                    <InstrucoesCobranca texto={cobranca_instrucoes} />
                </div>
            </div>
        </div>
    )
}