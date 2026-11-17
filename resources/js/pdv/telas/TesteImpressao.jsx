import { useEffect, useState } from 'react'
import QRCode from 'qrcode'
import { montarTicket, ticketExemplo } from '../impressao/ticket'
import { imprimirBluetooth, imprimirRawbt, temBluetooth } from '../impressao/transporte'

/**
 * Teste de impressão (antes de comprar a impressora): imprime um ticket de exemplo por três caminhos.
 * Leve o celular à loja e teste o modelo; o que funcionar aqui funciona no PDV.
 */
export default function TesteImpressao({ eu, onVoltar }) {
    const [op, setOp] = useState({ colunas: 48, acentos: true, qrImagem: false })
    const [msg, setMsg] = useState(null)
    const [ocupado, setOcupado] = useState(false)
    const [qr, setQr] = useState(null)
    const t = ticketExemplo(eu)
    useEffect(() => { QRCode.toDataURL(t.link, { margin: 1, width: 220 }).then(setQr).catch(() => {}) }, [t.link])

    const bytes = () => montarTicket(t, op)
    const viaBluetooth = (escolherOutra = false) => {
        setOcupado(true)
        setMsg(null)
        imprimirBluetooth(bytes(), { escolherOutra })
            .then((nome) => setMsg({ tipo: 'success', texto: `Enviado para ${nome}. Saiu certo? Confira acentos, QR e o corte.` }))
            .catch((e) => setMsg({ tipo: 'danger', texto: e.name === 'NotFoundError' ? 'Nenhuma impressora escolhida.' : e.message }))
            .finally(() => setOcupado(false))
    }

    return (
        <div>
            <button type="button" className="btn btn-link px-0 mb-2" onClick={onVoltar}>‹ Voltar</button>
            <h1 className="h5">Teste de impressão</h1>
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

            {msg && <div className={`alert alert-${msg.tipo} py-2 small`}>{msg.texto}</div>}

            <div className="d-grid gap-2 mb-4">
                <button type="button" className="btn btn-primary btn-lg" disabled={ocupado || !temBluetooth()} onClick={() => viaBluetooth(false)}>
                    1. Bluetooth direto (impressora BLE)
                </button>
                {temBluetooth() ? <button type="button" className="btn btn-sm btn-link" disabled={ocupado} onClick={() => viaBluetooth(true)}>escolher outra impressora</button>
                    : <div className="small text-danger">Este navegador não acessa Bluetooth: use o Chrome no Android.</div>}
                <button type="button" className="btn btn-outline-primary btn-lg" onClick={() => imprimirRawbt(bytes())}>2. Pelo app RawBT (Bluetooth comum, USB, rede)</button>
                <div className="small text-muted">Precisa do app "RawBT" instalado (Play Store) e a impressora escolhida nele. Se não abrir nada, o app não está instalado.</div>
                <button type="button" className="btn btn-outline-secondary btn-lg" onClick={() => window.print()}>3. Imprimir pelo Android</button>
                <div className="small text-muted">Usa o serviço de impressão do Android (precisa do plugin da marca da impressora). É o caminho mais lento; serve de reserva.</div>
            </div>

            <div className="small fw-semibold mb-1">Como fica o ticket</div>
            <div className="pdv-ticket" style={{ width: op.colunas === 48 ? '80mm' : '58mm' }}>
                <div className="text-center fw-bold fs-6">{t.empresa.nome}</div>
                {t.empresa.endereco && <div className="text-center">{t.empresa.endereco}</div>}
                <hr />
                <div className="text-center fw-bold fs-4">Nº {t.numero}</div>
                <div className="text-center fw-bold">{t.servico.toUpperCase()}</div>
                <div className="d-flex justify-content-between mt-2 fw-bold"><span>Placa</span><span>{t.placa} - {t.modelo}</span></div>
                <div className="d-flex justify-content-between"><span>Tamanho</span><span>{t.tamanho}</span></div>
                <div className="d-flex justify-content-between"><span>Entrada</span><span>{t.entrada}</span></div>
                <div className="text-center my-2">{qr && <img src={qr} alt="QR do ticket" width="130" height="130" />}</div>
                <div className="text-center small">{t.link.replace(/^https?:\/\//, '')}</div>
                <hr />
                <div>Carimbo do convênio:</div>
                <div className="pdv-carimbo" />
            </div>
        </div>
    )
}
