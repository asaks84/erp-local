import { useEffect, useRef, useState } from 'react'
import { Folha } from './comum'

export const temLeitor = () => typeof window !== 'undefined' && 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia

/** lê o QR do ticket pela câmera traseira (BarcodeDetector do Chrome no Android) */
export default function LeitorQr({ onLido, onFechar }) {
    const video = useRef(null)
    const [erro, setErro] = useState(temLeitor() ? null : 'Este navegador não lê QR pela câmera. Digite o número do ticket ou a placa.')

    useEffect(() => {
        if (!temLeitor()) return undefined
        let parar = false
        let stream = null
        let timer = null
        const detector = new window.BarcodeDetector({ formats: ['qr_code'] })
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then((s) => {
            stream = s
            if (parar) { s.getTracks().forEach((t) => t.stop()); return }
            video.current.srcObject = s
            video.current.play().catch(() => {})
            const ler = async () => {
                if (parar) return
                try {
                    const r = await detector.detect(video.current)
                    if (r.length && r[0].rawValue) { parar = true; if (navigator.vibrate) navigator.vibrate(80); onLido(r[0].rawValue); return }
                } catch { /* quadro ainda não pronto */ }
                timer = setTimeout(ler, 250)
            }
            ler()
        }).catch(() => setErro('Sem acesso à câmera. Libere a câmera para este site nas permissões do Chrome.'))

        return () => { parar = true; clearTimeout(timer); stream?.getTracks().forEach((t) => t.stop()) }
    }, [onLido])

    return (
        <Folha titulo="Aponte para o QR do ticket" onFechar={onFechar}>
            {erro ? <div className="alert alert-warning small">{erro}</div> : <video ref={video} className="pdv-camera" playsInline muted />}
            <button type="button" className="btn btn-outline-secondary btn-lg w-100 mt-2" onClick={onFechar}>Fechar</button>
        </Folha>
    )
}
