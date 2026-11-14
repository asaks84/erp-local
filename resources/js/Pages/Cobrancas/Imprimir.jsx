import { Head } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { data as fData, mesAno, moeda } from '../../lib/formato'

// "Via do cliente", no formato do sistema atual: folha A4 deitada com duas cobranças lado a lado.
// Seções Mensalidades, Lavagens e serviços, Estadias avulsas (somadas numa linha) e o fechamento
// com subtotal, créditos, débitos e total. Conta não paga da cobrança anterior leva um asterisco.

const LONGA = 16 // acima disso a cobrança ocupa a folha sozinha, para não cortar linhas

function mesDaMensalidade(i) {
    const proporcional = i.descricao.match(/proporcional\s+(\d{2}\/\d{2})\s+a\s+(\d{2}\/\d{2})/i)
    if (proporcional) return `Proporcional ${proporcional[1]} a ${proporcional[2]}`
    const mes = i.competencia ? mesAno(`${i.competencia}-01`) : ''
    return /pausa/i.test(i.descricao) ? `Reserva (pausa) ${mes}` : mes
}

function linhasDe(c) {
    const mens = c.itens.filter((i) => i.tipo === 'mensalidade')
    const lav = c.itens.filter((i) => i.tipo === 'servico')
    const est = c.itens.filter((i) => i.tipo === 'estadia')
    return { mens, lav, est, total: mens.length + lav.length + (est.length ? 1 : 0) }
}

function Via({ c, empresa }) {
    const { mens, lav, est } = linhasDe(c)
    const soma = (l) => l.reduce((s, i) => s + i.valor, 0)
    const creditos = -soma(c.itens.filter((i) => i.tipo === 'credito'))
    const debitos = soma(c.itens.filter((i) => i.tipo === 'debito'))
    const subtotal = soma(mens) + soma(lav) + soma(est)
    const temAnterior = c.itens.some((i) => i.grupo === 'saldo_anterior')
    const marca = (i) => (i.grupo === 'saldo_anterior' ? <span className="via-ast">*</span> : null)
    const veiculo = (i) => [i.placa, i.veiculo].filter(Boolean).join(' - ')

    return (
        <section className={`via${linhasDe(c).total > LONGA ? ' via-longa' : ''}`}>
            <header className="via-cabecalho">
                <div className="via-topo">
                    {empresa.logo_url ? <img src={empresa.logo_url} alt="" /> : <img src="/imagens/logo-local.png" alt="" />}
                    <span className="via-cod">#{c.cliente.id}</span>
                </div>
                <p className="via-cliente">{c.cliente.nome}</p>
                <div className="via-linha">
                    <span>Vencimento: <strong>{fData(c.vencimento)}</strong></span>
                    <span>Cobrança nº {c.numero} · {c.mes}</span>
                </div>
            </header>

            <div className="via-valores">
                <p className="via-titulo">Resumo de contas</p>

                {mens.length > 0 && (
                    <>
                        <p className="via-secao">Mensalidades</p>
                        <table className="via-tab"><tbody>
                            {mens.map((i) => (
                                <tr key={i.numero}><td>{veiculo(i)}</td><td className="via-meio">{mesDaMensalidade(i)}{marca(i)}</td><td className="via-num">{moeda(i.valor)}</td></tr>
                            ))}
                        </tbody></table>
                    </>
                )}

                {lav.length > 0 && (
                    <>
                        <p className="via-secao">Lavagens e serviços</p>
                        <table className="via-tab"><tbody>
                            {lav.map((i) => (
                                <tr key={i.numero}><td className="via-data">{fData(i.data)}</td><td>{veiculo(i)}</td><td className="via-meio">{i.descricao}{marca(i)}</td><td className="via-num">{moeda(i.valor)}</td></tr>
                            ))}
                        </tbody></table>
                    </>
                )}

                {est.length > 0 && (
                    <>
                        <p className="via-secao">Estadias avulsas</p>
                        <table className="via-tab"><tbody>
                            <tr><td>{est.length === 1 ? '1 estadia' : `${est.length} estadias`} ({est.map((i) => fData(i.data).slice(0, 5)).join(', ')}){est.some((i) => i.grupo === 'saldo_anterior') && <span className="via-ast">*</span>}</td><td className="via-num">{moeda(soma(est))}</td></tr>
                        </tbody></table>
                    </>
                )}
            </div>

            <table className="via-total"><tbody>
                {(creditos !== 0 || debitos !== 0) && <tr><td>Subtotal</td><td className="via-num">{moeda(subtotal)}</td></tr>}
                {creditos !== 0 && <tr><td>Créditos</td><td className="via-num">-{moeda(creditos)}</td></tr>}
                {debitos !== 0 && <tr><td>Débitos</td><td className="via-num">{moeda(debitos)}</td></tr>}
                {c.desconto > 0 && <tr><td>Total</td><td className="via-num">{moeda(c.total)}</td></tr>}
                {c.desconto > 0 && <tr><td>Desconto</td><td className="via-num">-{moeda(c.desconto)}</td></tr>}
                <tr className="via-final"><td>{c.desconto > 0 ? 'Total a pagar' : 'Total'}</td><td className="via-num">{moeda(c.total - (c.desconto || 0))}</td></tr>
            </tbody></table>

            {temAnterior && <p className="via-nota">* Não pago na cobrança anterior.</p>}
            {empresa.instrucoes && <p className="via-instrucoes">{empresa.instrucoes}</p>}
            {c.atualizacao_link && <QrAtualizacao link={c.atualizacao_link} />}
            <p className="via-rodape">{[empresa.nome, empresa.documento && `CNPJ ${empresa.documento}`, empresa.telefone].filter(Boolean).join(' · ')}</p>
        </section>
    )
}

/** atualização cadastral pendente: QR Code no pé da cobrança */
function QrAtualizacao({ link }) {
    const [qr, setQr] = useState(null)
    useEffect(() => { QRCode.toDataURL(link, { width: 240, margin: 0 }).then(setQr) }, [link])
    return (
        <div className="via-atualizacao">
            {qr && <img src={qr} alt="" width="64" height="64" />}
            <span><strong>Atualize seu cadastro.</strong> Aponte a câmera para o código, confirme a placa do seu veículo e confira seus dados (CPF, celular e endereço).</span>
        </div>
    )
}

/** duas por folha; cobrança longa vai sozinha na folha */
function folhas(cobrancas) {
    const lista = []
    let atual = []
    for (const c of cobrancas) {
        if (linhasDe(c).total > LONGA) {
            if (atual.length) lista.push(atual)
            lista.push([c])
            atual = []
            continue
        }
        atual.push(c)
        if (atual.length === 2) { lista.push(atual); atual = [] }
    }
    if (atual.length) lista.push(atual)
    return lista
}

export default function Imprimir({ empresa, cobrancas }) {
    const paginas = folhas(cobrancas)
    return (
        <div className="imp">
            <Head title="Imprimir cobranças" />
            <div className="imp-barra">
                <span>{cobrancas.length} cobrança(s) · {paginas.length} folha(s) A4 deitada, duas vias por folha</span>
                <button type="button" className="btn btn-primary btn-sm" onClick={() => window.print()}>Imprimir</button>
            </div>
            {cobrancas.length === 0 && <p className="p-4">Nenhuma cobrança para imprimir.</p>}
            {paginas.map((f, n) => (
                <div key={n} className="imp-folha">
                    {f.map((c) => <Via key={c.id} c={c} empresa={empresa} />)}
                </div>
            ))}
        </div>
    )
}

Imprimir.layout = null
