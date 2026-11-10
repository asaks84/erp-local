import { Modal } from 'react-bootstrap'

/** Janela com formulário (ações da ficha: reajustar, pausar, encerrar...). */
export default function Janela({ aberta, titulo, onFechar, onEnviar, rotuloOk = 'Salvar', perigo = false, ocupado, children }) {
    return (
        <Modal show={aberta} onHide={onFechar} centered>
            <form onSubmit={(e) => { e.preventDefault(); onEnviar() }} noValidate>
                <Modal.Header closeButton>
                    <Modal.Title as="h2" className="fs-5">{titulo}</Modal.Title>
                </Modal.Header>
                <Modal.Body>{children}</Modal.Body>
                <Modal.Footer>
                    <button type="button" className="btn btn-outline-secondary" onClick={onFechar}>Cancelar</button>
                    <button type="submit" className={`btn ${perigo ? 'btn-danger' : 'btn-primary'}`} disabled={ocupado}>{rotuloOk}</button>
                </Modal.Footer>
            </form>
        </Modal>
    )
}
