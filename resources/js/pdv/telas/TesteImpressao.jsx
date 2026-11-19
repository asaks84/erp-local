import { useState } from 'react'
import Papel from '../impressao/Papel'
import { blocos, exemplo, modeloDe, paraEscPos } from '../impressao/modelo'
import { imprimirBluetooth, imprimirRawbt, temBluetooth } from '../impressao/transporte'
import { NOMES_VIA, preferencia } from '../impressao/preferencia'
import { TopoEtapa, useAvisos } from '../visual'

/**
 * Teste de impressão (antes de comprar a impressora): imprime um ticket de exemplo por três caminhos.
 * Leve o celular à loja e teste o modelo; o que funcionar aqui funciona no PDV.
 */
export default function TesteImpressao({ eu, onVoltar }) {
    const salvo = preferencia.get()
    const [op, setOp] = useState({ colunas: salvo.colunas, acentos: salvo.acentos, qrImagem: salvo.qrImagem })
    const [via, setVia] = useState(salvo.via)
    // o caminho que deu certo vira o do PDV (entrada e saída imprimem por ele)
    const usar = (v) => { preferencia.set({ via: v, ...op }); setVia(v) }
    const avisos = useAvisos()
    const setMsg = (m) => m && (m.tipo === 'success' ? avisos.ok(m.texto) : avisos.erro(m.texto))
    const [ocupado, setOcupado] = useState(false)
    // o ticket de entrada como está no modelo do ERP (Configurações › Impressão), com dados de exemplo e o teste de acentos
    const cfg = eu.impressao
    const dados = { ...exemplo('ticket', eu.empresa), link: `${window.location.origin}/t/exemplo` }
    const lista = [...blocos(modeloDe(cfg, 'ticket'), dados, { logo: cfg?.logo }), { idx: -1, linha: { k: 'texto' }, saida: [{ tipo: 'texto', a: 'Acentos: ÇÃÕÉ çãõé', al: 'c' }] }]
    const bytes = () => paraEscPos(lista, op)
    const viaBluetooth = (escolherOutra = false) => {
        setOcupado(true)
        setMsg(null)
        imprimirBluetooth(bytes(), { escolherOutra })
            .then((nome) => { usar('bluetooth'); setMsg({ tipo: 'success', texto: `Enviado para ${nome}. Saiu certo? Confira acentos, QR e o corte.` }) })
            .catch((e) => setMsg({ tipo: 'danger', texto: e.name === 'NotFoundError' ? 'Nenhuma impressora escolhida.' : e.message }))
            .finally(() => setOcupado(false))
    }

    return (
        <div>
            <TopoEtapa titulo="Impressão" onVoltar={onVoltar} />
            <p className="small text-muted">Ligue a impressora e emparelhe com o celular (no Bluetooth do Android, se ela pedir). Depois tente os caminhos abaixo, na ordem.</p>

            <div className="d-flex flex-wrap gap-3 small mb-3">
                <label className="d-flex align-items-center gap-1">Bobina
                    <select className="form-select form-select-sm w-auto" value={op.colunas} onChange={(e) => setOp({ ...op, colunas: Number(e.target.value) })}>
                        <option value={48}>80 mm</option>
                        <option value={32}>58 mm</option>
                    </select>
                </label>
                <label className="form-check mb-0"><input className="form-check-input" type="checkbox" checked={op.acentos} onChange={(e) => setOp({ ...op, acentos: e.target.checked })} /> <span className="form-check-label">Acentos</span></label>
                <label className="form-check mb-0"><input className="form-check-input" type="checkbox" checked={op.qrImagem} onChange={(e) => setOp({ ...op, qrImagem: e.target.checked })} /> <span className="form-check-label">QR como imagem</span></label>
            </div>
            <p className="small text-muted">Acentos saindo errados: desmarque "Acentos". QR não saiu: marque "QR como imagem".</p>

            <div className="alert aviso-neutro py-2 small">{via ? <>O PDV imprime pelo <strong>{NOMES_VIA[via]}</strong> (o último caminho testado aqui), com as opções acima.</> : 'O PDV vai imprimir pelo último caminho que você testar aqui.'}</div>

            <div className="d-grid gap-2 mb-4">
                <button type="button" className="btn btn-primary btn-lg" disabled={ocupado || !temBluetooth()} onClick={() => viaBluetooth(false)}>
                    1. Bluetooth direto (impressora BLE)
                </button>
                {temBluetooth() ? <button type="button" className="btn btn-sm btn-link" disabled={ocupado} onClick={() => viaBluetooth(true)}>escolher outra impressora</button>
                    : <div className="small text-danger">Este navegador não acessa Bluetooth: use o Chrome no Android.</div>}
                <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => { usar('rawbt'); imprimirRawbt(bytes()) }}>2. Pelo app RawBT (Bluetooth comum, USB, rede)</button>
                <div className="small text-muted">Precisa do app "RawBT" instalado (Play Store) e a impressora escolhida nele. Se não abrir nada, o app não está instalado.</div>
                <button type="button" className="btn btn-outline-secondary btn-lg" onClick={() => { usar('android'); window.print() }}>3. Imprimir pelo Android</button>
                <div className="small text-muted">Usa o serviço de impressão do Android (precisa do plugin da marca da impressora). É o caminho mais lento; serve de reserva.</div>
            </div>

            <div className="small fw-semibold mb-1">Como fica o ticket (muda em Configurações › Impressão, no ERP)</div>
            <Papel lista={lista} colunas={op.colunas} logo={cfg?.logo} />
        </div>
    )
}
