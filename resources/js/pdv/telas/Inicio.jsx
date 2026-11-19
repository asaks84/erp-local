import { useCallback, useEffect, useState } from 'react'
import { api } from '../api'
import { Icone, TopoInicio } from '../visual'

/** Início (8.8): atalhos de Entrada e Saída, Estadia, Lavagem e Caixa com os números do momento, impressão e aparelho */
export default function Inicio({ eu, irPara }) {
    const [resumo, setResumo] = useState(null)
    const carregar = useCallback(() => api('GET', '/inicio').then(setResumo).catch(() => { /* sem números: os botões funcionam igual */ }), [])
    useEffect(() => {
        carregar()
        const t = setInterval(carregar, 60000)
        return () => clearInterval(t)
    }, [carregar])

    const numero = (n, um, varios) => (n > 0 ? <span className="pdv-numero">{n} {n === 1 ? um : varios}</span> : null)
    return (
        <div className="pdv-inicio">
            <TopoInicio eu={eu} caixa={resumo ? resumo.caixa : undefined} />
            <div className="pdv-inicio-corpo">
                <div className="pdv-atalhos">
                    <button type="button" className="pdv-atalho" onClick={() => irPara('entrada')}>
                        <Icone nome="mais" tamanho={30} espessura={2.2} />
                        <span><span className="pdv-atalho-titulo">Entrada</span><span className="pdv-atalho-legenda">estadia ou lavagem</span></span>
                    </button>
                    <button type="button" className="pdv-atalho pdv-atalho-contorno" onClick={() => irPara('saida')}>
                        <Icone nome="qr" tamanho={30} />
                        <span><span className="pdv-atalho-titulo">Saída</span><span className="pdv-atalho-legenda">ler qualquer ticket</span></span>
                    </button>
                </div>

                <div className="pdv-cartoes">
                    <button type="button" className="pdv-cartao" onClick={() => irPara('estadias')}>
                        <span className="pdv-cartao-icone"><Icone nome="carro" tamanho={30} /></span>
                        <span className="pdv-cartao-texto"><strong>Estadia</strong><span>Pátio e histórico</span></span>
                        {numero(resumo?.patio, 'no pátio', 'no pátio') || <Icone nome="seta" tamanho={20} espessura={2.4} className="pdv-seta" />}
                    </button>
                    <button type="button" className="pdv-cartao" onClick={() => irPara('lavagens')}>
                        <span className="pdv-cartao-icone"><Icone nome="gota" tamanho={28} /></span>
                        <span className="pdv-cartao-texto"><strong>Lavagem</strong><span>Etapas e entrega</span></span>
                        {numero(resumo?.lavagens, 'em andamento', 'em andamento') || <Icone nome="seta" tamanho={20} espessura={2.4} className="pdv-seta" />}
                    </button>
                    <button type="button" className="pdv-cartao" onClick={() => irPara('caixa')}>
                        <span className="pdv-cartao-icone"><Icone nome="carteira" tamanho={28} /></span>
                        <span className="pdv-cartao-texto"><strong>Caixa</strong><span>Receber conta, retirada, fechar</span></span>
                        <Icone nome="seta" tamanho={20} espessura={2.4} className="pdv-seta" />
                    </button>
                </div>

                <div className="pdv-lista">
                    <button type="button" onClick={() => irPara('impressao')}><Icone nome="impressora" tamanho={22} />Impressão</button>
                    <button type="button" onClick={() => irPara('aparelho')}><Icone nome="celular" tamanho={22} />Este aparelho<span className="pdv-lista-extra">tema, PIN</span></button>
                </div>
            </div>
        </div>
    )
}
