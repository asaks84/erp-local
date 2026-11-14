import { Head, Link, router } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import QRCode from 'qrcode'
import { data as fData } from '../../lib/formato'

const POR_FOLHA = 8 // A4 em pé: 2 colunas × 4 linhas

function Cartao({ c, empresa }) {
    const [qr, setQr] = useState(null)
    useEffect(() => { QRCode.toDataURL(c.link, { width: 300, margin: 0 }).then(setQr) }, [c.link])
    return (
        <div className="cartao-atualizacao">
            <div className="flex-grow-1">
                <div className="fw-bold">{empresa}</div>
                <div className="fs-5 fw-bold mt-1">Olá, {c.primeiro_nome}!</div>
                <p className="small mt-2 mb-1">Estamos atualizando o cadastro dos clientes. <strong>Aponte a câmera do celular para o código</strong>, confirme a placa do seu veículo (sem o código do link, ninguém abre) e confira seus dados (CPF, celular e endereço).</p>
                <div className="text-muted" style={{ fontSize: 10 }}>Leva 1 minuto · válido até {fData(c.expira_em)}</div>
            </div>
            {qr && <img src={qr} alt="" width="110" height="110" />}
        </div>
    )
}

/** Folhas A4 com cartões de atualização cadastral: escolhe quais imprimir (todos ou alguns), 8 por folha. */
export default function CartoesAtualizacao({ empresa_nome: empresa, cartoes }) {
    const [escolhidos, setEscolhidos] = useState(() => new Set(cartoes.map((c) => c.id)))
    const [busca, setBusca] = useState('')
    const [marcados, setMarcados] = useState(false)

    const selecionados = cartoes.filter((c) => escolhidos.has(c.id))
    const folhas = useMemo(() => {
        const f = []
        for (let i = 0; i < selecionados.length; i += POR_FOLHA) f.push(selecionados.slice(i, i + POR_FOLHA))
        return f
    }, [selecionados])
    const termo = busca.trim().toLowerCase()
    const visiveis = termo ? cartoes.filter((c) => c.nome.toLowerCase().includes(termo)) : cartoes

    const alternar = (id) => setEscolhidos((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
    const todos = (sim) => setEscolhidos((s) => { const n = new Set(s); visiveis.forEach((c) => (sim ? n.add(c.id) : n.delete(c.id))); return n })
    const marcar = () => router.post('/atualizacao-cadastral/enviado', { ids: selecionados.map((c) => c.id), canal: 'cartao' },
        { preserveScroll: true, onSuccess: () => setMarcados(true) })

    return (
        <div className="cartoes-atualizacao-pagina">
            <Head title="Cartões de atualização" />
            <div className="cartoes-barra nao-imprimir">
                <div className="d-flex flex-wrap gap-2 align-items-center">
                    <Link href="/atualizacao-cadastral" className="btn btn-outline-secondary btn-sm">Voltar</Link>
                    <span className="flex-grow-1 small">
                        <strong>{selecionados.length}</strong> de {cartoes.length} cartão(ões) · {folhas.length} folha(s) A4
                    </span>
                    <button type="button" className="btn btn-outline-primary btn-sm" disabled={marcados || !selecionados.length} onClick={marcar}>
                        {marcados ? 'Marcados como entregues' : 'Marcar como entregues'}
                    </button>
                    <button type="button" className="btn btn-primary btn-sm" disabled={!selecionados.length} onClick={() => window.print()}>Imprimir</button>
                </div>
                {cartoes.length > 1 && (
                    <div className="mt-2 pt-2 border-top">
                        <div className="d-flex flex-wrap gap-2 align-items-center mb-2">
                            <input type="search" className="form-control form-control-sm" style={{ maxWidth: 220 }} placeholder="Buscar cliente"
                                value={busca} onChange={(e) => setBusca(e.target.value)} />
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={() => todos(true)}>Marcar {termo ? 'os encontrados' : 'todos'}</button>
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={() => todos(false)}>Desmarcar {termo ? 'os encontrados' : 'todos'}</button>
                        </div>
                        <div className="cartoes-escolha row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-1">
                            {visiveis.map((c) => (
                                <div className="col" key={c.id}>
                                    <label className="form-check small mb-0 text-truncate">
                                        <input type="checkbox" className="form-check-input" checked={escolhidos.has(c.id)} onChange={() => alternar(c.id)} />
                                        <span className="form-check-label">{c.nome}</span>
                                    </label>
                                </div>
                            ))}
                            {visiveis.length === 0 && <div className="col small text-muted">Nenhum cliente com esse nome.</div>}
                        </div>
                    </div>
                )}
            </div>
            {folhas.map((f, i) => (
                <div className="folha-cartoes" key={i}>
                    {f.map((c) => <Cartao key={c.id} c={c} empresa={empresa} />)}
                </div>
            ))}
            {cartoes.length === 0 && <p className="text-center text-muted p-5">Nenhum cartão: gere os links primeiro.</p>}
            {cartoes.length > 0 && selecionados.length === 0 && <p className="text-center text-muted p-5 nao-imprimir">Marque pelo menos um cliente para imprimir.</p>}
        </div>
    )
}
CartoesAtualizacao.layout = null
