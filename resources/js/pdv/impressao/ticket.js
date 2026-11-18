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

const linkTicket = (codigo) => `${window.location.origin}/t/${codigo}`

/** ticket de entrada a partir do que a API devolve (recibo da entrada) */
export function ticketDaEstadia(r, eu) {
    return {
        empresa: eu.empresa, numero: String(r.numero).padStart(6, '0'), servico: r.servico, placa: r.placa, modelo: r.modelo,
        tamanho: r.tipo, entrada: r.entrada, link: linkTicket(r.codigo),
        rodape: r.pago_entrada > 0 ? `${r.servico} paga: ${moeda(r.pago_entrada)}` : 'Apresente este ticket na saída.',
        pago: r.pago,
    }
}

export const moeda = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

/** recibo da saída (ou do pagamento da diária na entrada) */
export function montarRecibo(r, eu, opcoes = {}) {
    const tk = new Ticket(opcoes)
    const e = eu.empresa || {}
    tk.alinhar('centro').negrito().linha(e.nome || 'Estacionamento').negrito(false)
    if (e.documento) tk.linha(`CNPJ ${e.documento}`)
    tk.alinhar('esquerda').traco()
    tk.alinhar('centro').negrito().linha(`RECIBO · Nº ${String(r.numero).padStart(6, '0')}`).negrito(false).alinhar('esquerda')
    tk.par('Placa', r.placa + (r.modelo ? ` - ${r.modelo}` : ''))
    if (r.tipo) tk.par('Tamanho', r.tipo)
    tk.par('Entrada', r.entrada)
    if (r.saida) tk.par('Saída', r.saida)
    if (r.tempo) tk.par('Tempo', r.tempo)
    tk.traco()
    if (r.valor !== null && r.valor !== undefined) tk.par(r.servico, moeda(r.valor))
    if (r.convenio) tk.par(`Convênio ${r.convenio.nome || ''}`.trim().slice(0, 30), `-${moeda(r.convenio.valor)}`)
    if (r.pago_entrada > 0 && r.saida) tk.par('Pago na entrada', `-${moeda(r.pago_entrada)}`)
    if (r.pago) {
        tk.negrito().par(`Pago (${r.pago.forma})`, moeda(r.pago.valor)).negrito(false)
        if (r.pago.observacao) tk.linha(r.pago.observacao)
    } else if (r.destino) {
        tk.par('Situação', r.destino)
    }
    tk.traco().alinhar('centro').linha('Obrigado pela preferência!').alinhar('esquerda')

    return tk.avancar(4).cortar().pronto()
}

function cabecalho(tk, eu, titulo) {
    const e = eu.empresa || {}
    tk.alinhar('centro').negrito().linha(e.nome || 'Estacionamento').negrito(false)
    if (e.documento) tk.linha(`CNPJ ${e.documento}`)
    tk.alinhar('esquerda').traco()
    tk.alinhar('centro').negrito().linha(titulo).negrito(false).alinhar('esquerda')
}

/** recibo de conta paga no balcão (mensalidade e outras contas do cliente) */
export function montarReciboConta(r, eu, opcoes = {}) {
    const tk = new Ticket(opcoes)
    cabecalho(tk, eu, 'RECIBO')
    tk.par('Cliente', r.cliente).par('Data', r.data).traco()
    r.itens.forEach((i) => tk.par(i.descricao.slice(0, 32), moeda(i.valor)))
    tk.traco().negrito().par(`Pago (${r.forma})`, moeda(r.total)).negrito(false)
    if (r.observacao) tk.linha(r.observacao)
    tk.traco().alinhar('centro').linha('Obrigado!').alinhar('esquerda')

    return tk.avancar(4).cortar().pronto()
}

/** resumo do fechamento do caixa */
export function montarFechamento(r, eu, opcoes = {}) {
    const tk = new Ticket(opcoes)
    cabecalho(tk, eu, 'FECHAMENTO DE CAIXA')
    tk.par('Aberto', r.aberto_em.split(' ').map((p, i) => (i ? p : p.split('-').reverse().join('/'))).join(' '))
    tk.par('Fechado', (r.fechado_em || '').split(' ').map((p, i) => (i ? p : p.split('-').reverse().join('/'))).join(' '))
    if (r.fechado_por) tk.par('Por', r.fechado_por)
    tk.traco().negrito().linha('POR SERVIÇO').negrito(false)
    r.por_servico.forEach((l) => tk.par(`${l.servico} (${l.qtd})`.slice(0, 32), moeda(l.total)))
    tk.negrito().par('Total recebido', moeda(r.total_recebido)).negrito(false)
    tk.traco().negrito().linha('POR FORMA').negrito(false)
    r.por_forma.forEach((l) => tk.par(l.forma, moeda(l.total)))
    tk.traco().negrito().linha('DINHEIRO').negrito(false)
    tk.par('Troco inicial', moeda(r.troco_inicial)).par('+ Recebido', moeda(r.dinheiro_recebido))
    if (r.suprimentos > 0) tk.par('+ Suprimentos', moeda(r.suprimentos))
    if (r.sangrias > 0) tk.par('- Sangrias', moeda(r.sangrias))
    if (r.pagamentos > 0) tk.par('- Pagamentos', moeda(r.pagamentos))
    if (r.retiradas > 0) tk.par('- Retiradas', moeda(r.retiradas))
    tk.negrito().par('Deveria ter', moeda(r.dinheiro_esperado)).negrito(false)
    tk.par('Contado', moeda(r.dinheiro_contado)).par('Diferença', moeda(r.diferenca)).par('Fica de troco', moeda(r.troco_deixado))
    if (r.movimentos.length) {
        tk.traco().negrito().linha('MOVIMENTOS').negrito(false)
        r.movimentos.forEach((m) => tk.par(`${m.hora.slice(6)} ${m.rotulo}`.slice(0, 32), moeda(m.sinal * m.valor)))
    }
    tk.traco().linha('Conferido: ____________________')

    return tk.avancar(4).cortar().pronto()
}
