/** Link para abrir uma conversa no WhatsApp (wa.me). */
const digitos = (v) => {
    let d = String(v ?? '').replace(/\D/g, '')
    if (d.length > 11 && d.startsWith('55')) d = d.slice(2)
    return d
}

export function linkWhatsApp(numero, texto = '') {
    return `https://wa.me/55${digitos(numero)}${texto ? `?text=${encodeURIComponent(texto)}` : ''}`
}

/** abre a conversa numa aba nova (para botões que fazem mais alguma coisa ao clicar) */
export function abrirWhatsApp(numero, texto = '') {
    window.open(linkWhatsApp(numero, texto), '_blank', 'noopener')
}
