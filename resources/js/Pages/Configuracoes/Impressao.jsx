import { router } from '@inertiajs/react'
import { useMemo, useRef, useState } from 'react'
import AbasConfiguracoes from '../../Components/AbasConfiguracoes'
import TituloPagina from '../../Components/TituloPagina'
import Papel from '../../pdv/impressao/Papel'
import { CAMPOS_DO_PAPEL, PADRAO, PAPEIS, ajudaDaLinha, blocos, ehDado, exemplo, modeloDe, nomeDaLinha } from '../../pdv/impressao/modelo'

const copia = (x) => JSON.parse(JSON.stringify(x))

/**
 * Configurações › Impressão (PDV), parte 8.8: editor visual de cada papel. Clica na linha do papel,
 * formata, sobe/desce, esconde; insere espaço, linha tracejada ou texto. O PDV imprime pelo que for salvo.
 */
export default function Impressao({ config, empresa }) {
    const [modelos, setModelos] = useState(() => Object.fromEntries(PAPEIS.map((p) => [p.chave, copia(modeloDe(config, p.chave))])))
    const [mexidos, setMexidos] = useState({}) // papéis alterados desde o último salvar (null = voltar ao padrão)
    const [aba, setAba] = useState('ticket')
    const [sel, setSel] = useState(null)
    const [bobina, setBobina] = useState(48)
    const [salvando, setSalvando] = useState(false)
    const arquivo = useRef(null)

    const modelo = modelos[aba]
    const dados = useMemo(() => exemplo(aba, empresa), [aba, empresa])
    const lista = useMemo(() => blocos(modelo, dados, { logo: config.logo }), [modelo, dados, config.logo])
    const linhaSel = sel !== null ? modelo[sel] : null

    const trocar = (novo, novaSel = sel) => {
        setModelos({ ...modelos, [aba]: novo })
        setMexidos({ ...mexidos, [aba]: novo })
        setSel(novaSel)
    }
    const mudar = (idx, campos) => trocar(modelo.map((l, i) => (i === idx ? { ...l, ...campos } : l)))
    // sobe/desce pulando as escondidas (elas não aparecem no papel)
    const mover = (idx, dir) => {
        const visiveis = modelo.map((l, i) => (l.on ? i : null)).filter((i) => i !== null)
        const p = visiveis.indexOf(idx); const alvo = visiveis[p + dir]
        if (alvo === undefined) return
        const novo = [...modelo];[novo[idx], novo[alvo]] = [novo[alvo], novo[idx]]
        trocar(novo, alvo)
    }
    const inserir = (idx, k) => {
        const nova = { k, on: true, al: k === 'texto' ? 'c' : 'e', neg: false, gra: false, ...(k === 'texto' ? { txt: 'Novo texto' } : {}), ...(k === 'espaco' ? { alt: 1 } : {}) }
        const novo = [...modelo]; novo.splice(idx + 1, 0, nova)
        trocar(novo, idx + 1)
    }
    const remover = (idx) => trocar(modelo.filter((_, i) => i !== idx), null)
    const mostrar = (idx) => mudar(idx, { on: true })
    const padrao = () => { setModelos({ ...modelos, [aba]: copia(PADRAO[aba]) }); setMexidos({ ...mexidos, [aba]: null }); setSel(null) }
    const salvar = () => {
        setSalvando(true)
        router.put('/configuracoes/impressao', { modelos: mexidos }, { preserveScroll: true, onSuccess: () => setMexidos({}), onFinish: () => setSalvando(false) })
    }
    const enviarLogo = (e) => {
        const f = e.target.files?.[0]
        if (f) router.post('/configuracoes/impressao/logo', { logo: f }, { forceFormData: true, preserveScroll: true })
        e.target.value = ''
    }
    // dados que ainda não estão no papel (o padrão pode não ter todos)
    const faltando = CAMPOS_DO_PAPEL[aba].filter((k) => !modelo.some((l) => l.k === k))
    const escondidas = modelo.map((l, i) => ({ l, i })).filter(({ l }) => !l.on)

    const barra = linhaSel && (() => {
        const l = linhaSel
        const b = (rotulo, conteudo, fazer, ativo = false, desligado = false) => (
            <button type="button" title={rotulo} aria-label={rotulo} className={ativo ? 'ativo' : ''} disabled={desligado} onClick={(e) => { e.stopPropagation(); fazer() }}>{conteudo}</button>
        )
        const temTexto = !['logo', 'hr', 'espaco', 'qr', 'carimbo'].includes(l.k)
        return (
            <div className="papel-barra" role="toolbar" aria-label={`Formatar ${nomeDaLinha(l)}`}>
                {temTexto && <>
                    {b('Alinhar à esquerda', '⇤', () => mudar(sel, { al: 'e' }), l.al === 'e')}
                    {b('Centralizar', '≡', () => mudar(sel, { al: 'c' }), l.al === 'c')}
                    {b('Alinhar à direita', '⇥', () => mudar(sel, { al: 'd' }), l.al === 'd')}
                    {b('Negrito', 'B', () => mudar(sel, { neg: !l.neg }), l.neg)}
                    {b('Letra grande', 'A+', () => mudar(sel, { gra: !l.gra }), l.gra)}
                </>}
                {l.k === 'espaco' && <>
                    {b('Menos espaço', '−', () => mudar(sel, { alt: Math.max(1, (l.alt || 1) - 1) }), false, (l.alt || 1) <= 1)}
                    <span className="text-white small px-1 align-self-center">{l.alt || 1}</span>
                    {b('Mais espaço', '+', () => mudar(sel, { alt: Math.min(6, (l.alt || 1) + 1) }), false, (l.alt || 1) >= 6)}
                </>}
                {l.k === 'qr' && ['P', 'M', 'G'].map((t) => <span key={t}>{b(`QR ${t}`, t, () => mudar(sel, { tam: t }), (l.tam || 'M') === t)}</span>)}
                {b('Subir', '↑', () => mover(sel, -1))}
                {b('Descer', '↓', () => mover(sel, 1))}
                {ehDado(l.k) ? b('Esconder', '✕', () => { mudar(sel, { on: false }); setSel(null) }) : b('Remover', '🗑', () => remover(sel))}
            </div>
        )
    })()
    const abaixo = sel !== null && (
        <div className="papel-inserir">
            <button type="button" onClick={() => inserir(sel, 'espaco')}>+ Espaço</button>
            <button type="button" onClick={() => inserir(sel, 'hr')}>+ Linha</button>
            <button type="button" onClick={() => inserir(sel, 'texto')}>+ Texto</button>
        </div>
    )
    const algoMexido = Object.keys(mexidos).length > 0

    return (
        <div className="pagina">
            <AbasConfiguracoes />
            <TituloPagina titulo="Impressão (PDV)" descricao="Como saem o ticket, os recibos e o fechamento do caixa. Clique numa linha do papel para formatar, mover ou esconder." />

            <ul className="nav nav-tabs mb-3" role="tablist">
                {PAPEIS.map((p) => (
                    <li key={p.chave} className="nav-item" role="presentation">
                        <button type="button" role="tab" aria-selected={aba === p.chave} className={`nav-link${aba === p.chave ? ' active' : ''}`} onClick={() => { setAba(p.chave); setSel(null) }}>
                            {p.nome}{mexidos[p.chave] !== undefined && ' •'}
                        </button>
                    </li>
                ))}
            </ul>
            <p className="small text-muted mt-n2 mb-3">{PAPEIS.find((p) => p.chave === aba)?.ajuda}.{aba === 'recibo' && ' Cada pagamento mostra só as linhas que tem: conta de cliente não tem placa, estadia não tem cliente.'}</p>

            <div className="row g-4">
                <div className="col-lg-4 order-2 order-lg-1">
                    <div className="card mb-3">
                        <div className="card-header">Linha selecionada</div>
                        <div className="card-body">
                            {linhaSel ? (
                                <>
                                    <div className="fw-semibold">{nomeDaLinha(linhaSel)}</div>
                                    <div className="small text-muted mb-2">{ajudaDaLinha(linhaSel)}{ehDado(linhaSel.k) && ' · no PDV entram os dados do veículo'}</div>
                                    {linhaSel.k === 'texto' && (
                                        <label className="form-label w-100 mb-0">Texto
                                            <textarea className="form-control mt-1" rows={2} maxLength={200} value={linhaSel.txt || ''} onChange={(e) => mudar(sel, { txt: e.target.value })} />
                                        </label>
                                    )}
                                </>
                            ) : <div className="small text-muted">Clique numa linha do papel.</div>}
                        </div>
                    </div>

                    <div className="card mb-3">
                        <div className="card-header">Fora do papel</div>
                        <div className="card-body">
                            <div className="small text-muted mb-2">Clique para pôr de volta.</div>
                            <div className="d-flex flex-wrap gap-2">
                                {escondidas.map(({ l, i }) => <button key={i} type="button" className="btn btn-sm btn-outline-secondary" style={{ borderStyle: 'dashed' }} onClick={() => mostrar(i)}>+ {nomeDaLinha(l)}</button>)}
                                {faltando.map((k) => <button key={k} type="button" className="btn btn-sm btn-outline-secondary" style={{ borderStyle: 'dashed' }} onClick={() => trocar([...modelo, { k, on: true, al: 'e', neg: false, gra: false }], modelo.length)}>+ {nomeDaLinha({ k })}</button>)}
                                {!escondidas.length && !faltando.length && <span className="small text-muted">nada</span>}
                            </div>
                        </div>
                    </div>

                    <div className="card">
                        <div className="card-header">Papel</div>
                        <div className="card-body d-flex flex-column gap-3">
                            <div className="d-flex justify-content-between align-items-center gap-2">
                                <span className="fw-semibold small">Prévia na bobina de</span>
                                <div className="btn-group btn-group-sm" role="group" aria-label="Bobina">
                                    <button type="button" className={`btn ${bobina === 48 ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setBobina(48)}>80 mm</button>
                                    <button type="button" className={`btn ${bobina === 32 ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setBobina(32)}>58 mm</button>
                                </div>
                            </div>
                            <div>
                                <div className="fw-semibold small mb-1">Logo</div>
                                <div className="d-flex align-items-center gap-2 flex-wrap">
                                    {config.logo ? <img src={config.logo} alt="Logo da impressão" style={{ height: 36, background: '#fff', padding: 2, borderRadius: 4 }} /> : <span className="small text-muted">sem logo</span>}
                                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => arquivo.current?.click()}>{config.logo ? 'Trocar' : 'Enviar'}</button>
                                    {config.logo && <button type="button" className="btn btn-sm btn-link text-danger" onClick={() => router.delete('/configuracoes/impressao/logo', { preserveScroll: true })}>Tirar</button>}
                                    <input ref={arquivo} type="file" accept="image/png,image/jpeg" className="d-none" onChange={enviarLogo} />
                                </div>
                                <div className="form-text">Vira preto e branco para a impressora térmica. Prefira o logo sobre fundo branco.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="col-lg-8 order-1 order-lg-2">
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <span className="small text-muted">Os dados no papel são de exemplo.</span>
                        <div className="d-flex gap-2">
                            <button type="button" className="btn btn-outline-secondary" onClick={padrao}>Voltar ao padrão</button>
                            <button type="button" className="btn btn-primary" disabled={!algoMexido || salvando} onClick={salvar}>{salvando ? 'Salvando…' : 'Salvar'}</button>
                        </div>
                    </div>
                    <div className="papel-mesa" onClick={(e) => { if (e.target === e.currentTarget) setSel(null) }}>
                        <Papel lista={lista} colunas={bobina} logo={config.logo} selecionado={sel} onSelecionar={setSel} barra={barra} abaixo={abaixo} />
                    </div>
                </div>
            </div>
        </div>
    )
}
