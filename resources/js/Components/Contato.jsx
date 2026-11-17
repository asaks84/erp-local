import Icone from './Icone'
import { linkWhatsApp } from '../lib/whatsapp'

/** só dígitos, sem o 55 na frente */
const digitos = (v) => {
    let d = String(v ?? '').replace(/\D/g, '')
    if (d.length > 11 && d.startsWith('55')) d = d.slice(2)
    return d
}
/** celular brasileiro: DDD + 9 + 8 dígitos */
export const ehCelular = (v) => /^[1-9][1-9]9\d{8}$/.test(digitos(v))

/**
 * Botões de contato: WhatsApp (só se o número for celular) e Ligar (abre o discador no celular
 * ou o aplicativo de chamadas no computador). `compacto` mostra só os ícones.
 */
export default function Contato({ celular, telefone, compacto = false, className = '' }) {
    const whats = [celular, telefone].find(ehCelular)
    const ligar = digitos(celular) || digitos(telefone)
    if (!whats && !ligar) return null
    const parar = (e) => e.stopPropagation()
    return (
        <span className={`d-inline-flex gap-1 ${className}`}>
            {whats && (
                <a href={linkWhatsApp(whats)} target="_blank" rel="noopener noreferrer" onClick={parar}
                    className={`btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1`} title="Abrir conversa no WhatsApp" aria-label="WhatsApp">
                    <Icone nome="whatsapp" tamanho={16} />{!compacto && 'WhatsApp'}
                </a>
            )}
            {ligar && (
                <a href={`tel:+55${ligar}`} onClick={parar}
                    className={`btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1`} title="Ligar" aria-label="Ligar">
                    <Icone nome="telefone" tamanho={16} />{!compacto && 'Ligar'}
                </a>
            )}
        </span>
    )
}
