import { Modal, Button } from 'react-bootstrap'

/** Janela de confirmação do sistema (em vez do confirm() do navegador). */
export default function Confirmar({ aberto, titulo, children, rotuloOk = 'Confirmar', perigo = false, onOk, onCancelar, ocupado }) {
    return (
        <Modal show={aberto} onHide={onCancelar} centered>
            <Modal.Header closeButton>
                <Modal.Title as="h2" className="fs-5">{titulo}</Modal.Title>
            </Modal.Header>
            <Modal.Body>{children}</Modal.Body>
            <Modal.Footer>
                <Button variant="outline-secondary" onClick={onCancelar}>Cancelar</Button>
                <Button variant={perigo ? 'danger' : 'primary'} onClick={onOk} disabled={ocupado}>{rotuloOk}</Button>
            </Modal.Footer>
        </Modal>
    )
}
