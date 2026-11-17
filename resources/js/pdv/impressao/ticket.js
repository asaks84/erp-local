import { Ticket } from './escpos'

/**
 * Ticket do Local (decisão 7 do plano): placa e modelo, tamanho, tipo de serviço, dia e hora de entrada,
 * número, QR Code e espaço para o carimbo do convênio.
 */
export function montarTicket(t, opcoes = {}) {
    const tk = new Ticket(opcoes)
    const e = t.empresa || {}
    tk.alinhar('centro').negrito().tamanho(1, 2).linha(e.nome || 'Estacionamento').tamanho().negrito(false)
    if (e.endereco) tk.linha(e.endereco)
    if (e.telefone || e.documento) tk.linha([e.telefone, e.documento ? `CNPJ ${e.documento}` : null].filter(Boolean).join(' · '))
    tk.alinhar('esquerda').traco()
    tk.alinhar('centro').negrito().tamanho(2, 2).linha(`Nº ${t.numero}`).tamanho().linha(t.servico.toUpperCase()).negrito(false)
    tk.alinhar('esquerda').linha()
    tk.negrito().par('Placa', t.placa + (t.modelo ? ` - ${t.modelo}` : '')).negrito(false)
    tk.par('Tamanho', t.tamanho)
    tk.par('Entrada', t.entrada)
    tk.linha().alinhar('centro').qr(t.link).linha().linha(t.link.replace(/^https?:\/\//, '')).alinhar('esquerda')
    if (t.convenio !== false) tk.traco().linha('Carimbo do convênio:').caixa(4)
    if (t.rodape) tk.alinhar('centro').linha(t.rodape).alinhar('esquerda')

    return tk.avancar(4).cortar().pronto()
}

export function ticketExemplo(eu) {
    const agora = new Date()
    return {
        empresa: eu.empresa,
        numero: '000123',
        servico: 'Estadia',
        placa: 'ABC1D23',
        modelo: 'Gol',
        tamanho: 'Médio',
        entrada: agora.toLocaleDateString('pt-BR') + ' ' + agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
        link: `${window.location.origin}/t/exemplo`,
        rodape: 'Teste de impressão · acentuação: ÇÃÕÉ çãõé',
    }
}
