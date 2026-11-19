import { router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { Modal } from 'react-bootstrap'

/** botão que gera o código de cadastro do PIN (6 dígitos, vale 24 h) para a pessoa escolher o próprio PIN no PDV */
export function BotaoCodigoPin({ usuario, className = 'btn btn-sm btn-outline-primary', children }) {
    return (
        <button type="button" className={className} onClick={() => router.post(`/usuarios/${usuario.id}/codigo-pin`, {}, { preserveScroll: true })}>
            {children || (usuario.codigo_ate ? 'Gerar outro código' : 'Gerar código do PIN')}
        </button>
    )
}

/** mostra, uma vez, o código que acabou de ser gerado (só existe nesta tela: guardado apenas como hash) */
export default function JanelaCodigoPin() {
    const { flash } = usePage().props
    const [c, setC] = useState(null)
    useEffect(() => { if (flash?.codigo_pin) setC(flash.codigo_pin) }, [flash?.codigo_pin])
    if (!c) return null
    return (
        <Modal show onHide={() => setC(null)} centered>
            <Modal.Header closeButton><Modal.Title as="h2" className="fs-5">Código do PIN de {c.nome}</Modal.Title></Modal.Header>
            <Modal.Body className="text-center">
                <div className="display-5 fw-bold font-monospace my-2" style={{ letterSpacing: '.3em' }}>{c.codigo.slice(0, 3)} {c.codigo.slice(3)}</div>
                <p className="mb-2">Vale até <strong>{c.ate}</strong> e uma vez só.</p>
                <p className="small text-muted mb-0 text-start">
                    Passe o código para {c.nome}. No PDV: <strong>Este aparelho › Cadastrar ou trocar meu PIN › Tenho um código</strong>, digita o código e escolhe os 4 números.
                    Só ele fica sabendo o PIN. Este código não aparece de novo: se perder, gere outro.
                </p>
            </Modal.Body>
            <Modal.Footer><button type="button" className="btn btn-primary" onClick={() => setC(null)}>Pronto</button></Modal.Footer>
        </Modal>
    )
}
