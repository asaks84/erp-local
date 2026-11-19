import { Icone, TopoEtapa } from '../visual'

/** Entrada pelo Início: estadia ou lavagem */
export default function Entrada({ onVoltar, onEstadia, onLavagem }) {
    return (
        <div>
            <TopoEtapa titulo="Entrada" onVoltar={onVoltar} />
            <h2 className="h4 fw-bold text-center my-4">O que o carro vai fazer?</h2>
            <div className="d-flex flex-column gap-3">
                <button type="button" className="pdv-escolha" onClick={onEstadia}>
                    <span className="pdv-cartao-icone"><Icone nome="carro" tamanho={40} /></span>
                    <span><strong>Estadia</strong><span className="pdv-escolha-legenda">Por hora ou diária</span></span>
                </button>
                <button type="button" className="pdv-escolha" onClick={onLavagem}>
                    <span className="pdv-cartao-icone"><Icone nome="gota" tamanho={38} /></span>
                    <span><strong>Lavagem</strong><span className="pdv-escolha-legenda">Serviço, adicionais e celular</span></span>
                </button>
            </div>
            <p className="small text-muted text-center mt-4">Carro que já está no pátio e pede lavagem: escolha Lavagem; o PDV pergunta o que fazer com a estadia.</p>
        </div>
    )
}
