import { Ticket } from './escpos'

/*
 * Modelos de impressão (8.8): cada papel é uma lista de linhas (blocos) que o editor do ERP
 * (Configurações › Impressão do PDV) liga, desliga, formata e reordena. O PDV monta o papel daqui:
 * a mesma lista vira a prévia na tela e os comandos ESC/POS da impressora.
 * Linha = { k, on, al: 'e'|'c'|'d', neg, gra, txt (texto livre), alt (espaço, em linhas), tam (QR: P|M|G) }.
 * A lista de chaves aceitas por papel também está no servidor (App\Servicos\Pdv\ImpressaoModelos).
 */

export const moeda = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
const semHttp = (u) => String(u || '').replace(/^https?:\/\//, '')
const quando = (s) => (s || '').split(' ').map((p, i) => (i ? p : p.includes('-') ? p.split('-').reverse().join('/') : p)).join(' ')

// três papéis: o ticket (estadia e lavagem), o recibo (todo pagamento) e o fechamento do caixa
export const PAPEIS = [
    { chave: 'ticket', nome: 'Ticket de entrada', ajuda: 'Estadia e lavagem' },
    { chave: 'recibo', nome: 'Recibo de pagamento', ajuda: 'Estadia, lavagem, contas de cliente: todo pagamento' },
    { chave: 'fechamento', nome: 'Fechamento do caixa', ajuda: 'Resumo impresso ao fechar o caixa' },
]

// peças de montagem (valem em qualquer papel)
export const LIVRES = {
    espaco: ['Espaço', 'Distância entre as áreas'],
    hr: ['Linha tracejada', 'Separador'],
    texto: ['Texto livre', 'Escreva o que quiser'],
}

/** dados que o papel mostra: nome, ajuda e as linhas que saem (cada linha: { a, b? } — b vai à direita) */
const CAMPOS = {
    logo: ['Logo', 'Imagem em preto e branco (Papel › Logo)', () => []],
    nome: ['Nome da empresa', 'Do cadastro da empresa', (d) => [{ a: d.empresa?.nome || 'Estacionamento' }]],
    cnpj: ['CNPJ', 'Do cadastro da empresa', (d) => (d.empresa?.documento ? [{ a: `CNPJ ${d.empresa.documento}` }] : [])],
    endereco: ['Endereço', 'Da unidade', (d) => (d.empresa?.endereco ? [{ a: d.empresa.endereco }] : [])],
    telefone: ['Telefone', 'Da unidade ou da empresa', (d) => (d.empresa?.telefone ? [{ a: d.empresa.telefone }] : [])],
    titulo: ['Título', 'Recibo, fechamento…', (d) => (d.titulo ? [{ a: d.titulo }] : [])],
    numero: ['Número do ticket', 'Dado do ticket', (d) => [{ a: `Nº ${d.numero}` }]],
    servico: ['Serviço', 'Estadia, diária ou lavagem', (d) => (d.servico ? [{ a: String(d.servico).toUpperCase() }] : [])],
    placa: ['Placa e modelo', 'Dado do veículo', (d) => [{ a: 'Placa', b: (d.placa || '') + (d.modelo ? ` - ${d.modelo}` : '') }]],
    tamanho: ['Tamanho', 'Dado do veículo', (d) => (d.tamanho ? [{ a: 'Tamanho', b: d.tamanho }] : [])],
    entrada: ['Entrada', 'Data e hora', (d) => (d.entrada ? [{ a: 'Entrada', b: d.entrada }] : [])],
    saida: ['Saída / entrega', 'Data e hora', (d) => (d.saida ? [{ a: d.rotuloSaida || 'Saída', b: d.saida }] : [])],
    tempo: ['Tempo', 'Quanto ficou', (d) => (d.tempo ? [{ a: 'Tempo', b: d.tempo }] : [])],
    pago_entrada: ['Pago na entrada', 'Só sai quando pagou', (d) => (d.pago ? [{ a: 'Pago', b: `${moeda(d.pago.valor)}${d.pago.forma ? ` (${d.pago.forma})` : ''}` }] : [])],
    itens: ['Itens', 'O que foi pago (serviços, contas), com convênio e o já pago', (d) => (d.itens || []).map((i) => ({ a: i.descricao, b: i.texto ?? moeda(i.valor) }))],
    pago: ['Pagamento', 'Forma e valor pago', (d) => (d.pago
        ? [{ a: `Pago (${d.pago.forma})`, b: moeda(d.pago.valor) }, ...(d.pago.observacao ? [{ a: d.pago.observacao }] : [])]
        : d.situacao ? [{ a: 'Situação', b: d.situacao }] : [])],
    na_conta: ['Na conta de', 'Quando foi para a conta de alguém', (d) => (d.na_conta || []).map((n) => ({ a: `Na conta de ${n}` }))],
    cliente: ['Cliente', 'Quem pagou', (d) => (d.cliente ? [{ a: 'Cliente', b: d.cliente }] : [])],
    data: ['Data', 'Quando pagou', (d) => (d.data ? [{ a: 'Data', b: d.data }] : [])],
    link: ['Endereço do ticket', 'O link do QR, escrito', (d) => (d.link ? [{ a: semHttp(d.link) }] : [])],
    qr: ['QR do ticket', 'Leva à página do ticket', () => []],
    carimbo: ['Espaço para carimbo', 'Quadro para o convênio', () => []],
    periodo: ['Período', 'Aberto, fechado e por quem', (d) => d.periodo || []],
    por_servico: ['Por serviço', 'Quanto entrou em cada um', (d) => d.por_servico || []],
    por_forma: ['Por forma', 'Dinheiro, Pix, cartão…', (d) => d.por_forma || []],
    dinheiro: ['Conferência do dinheiro', 'Deveria ter, contado, diferença', (d) => d.dinheiro || []],
    movimentos: ['Movimentos', 'Sangrias, pagamentos, suprimentos', (d) => d.movimentos || []],
    assinatura: ['Assinatura', 'Linha para quem conferiu', () => [{ a: 'Conferido: ____________________' }]],
}

const CABECALHO = ['logo', 'nome', 'cnpj', 'endereco', 'telefone']
/** chaves aceitas em cada papel (além de espaço, linha e texto) */
export const CAMPOS_DO_PAPEL = {
    ticket: [...CABECALHO, 'numero', 'servico', 'placa', 'tamanho', 'entrada', 'pago_entrada', 'qr', 'link', 'carimbo'],
    recibo: [...CABECALHO, 'titulo', 'cliente', 'data', 'placa', 'tamanho', 'entrada', 'saida', 'tempo', 'itens', 'pago', 'na_conta'],
    fechamento: [...CABECALHO, 'titulo', 'periodo', 'por_servico', 'por_forma', 'dinheiro', 'movimentos', 'assinatura'],
}

const L = (k, extra = {}) => ({ k, on: true, al: 'e', neg: false, gra: false, ...extra })
const C = (k, extra = {}) => L(k, { al: 'c', ...extra })
const T = (txt, extra = {}) => C('texto', { txt, ...extra })
const ESP = (alt = 1) => L('espaco', { alt })
const HR = () => L('hr')
const topo = () => [C('logo'), C('nome', { neg: true }), C('endereco'), C('telefone'), C('cnpj')]
const topoCurto = () => [C('logo', { on: false }), C('nome', { neg: true }), C('cnpj'), C('endereco', { on: false }), C('telefone', { on: false })]

/** como sai sem nada editado (igual ao que o PDV imprimia antes do editor) */
export const PADRAO = {
    ticket: [...topo(), HR(), C('numero', { neg: true, gra: true }), C('servico', { neg: true }), ESP(), L('placa', { neg: true }), L('tamanho'), L('entrada'), L('pago_entrada'),
        ESP(), C('qr', { tam: 'M' }), C('link'), HR(), L('texto', { al: 'e', txt: 'Carimbo do convênio:' }), L('carimbo'), T('Apresente este ticket na saída.')],
    recibo: [...topoCurto(), HR(), C('titulo', { neg: true }), L('cliente'), L('data'), L('placa'), L('tamanho'), L('entrada'), L('saida'), L('tempo'), HR(), L('itens'), HR(),
        L('pago', { neg: true }), L('na_conta'), HR(), T('Obrigado pela preferência!')],
    fechamento: [...topoCurto(), HR(), C('titulo', { neg: true }), L('periodo'), HR(), L('por_servico'), HR(), L('por_forma'), HR(), L('dinheiro'), L('movimentos'), HR(), L('assinatura')],
}

/** o modelo salvo de um papel (ou o padrão, se nunca foi editado) */
export const modeloDe = (config, papel) => {
    const salvo = config?.modelos?.[papel]
    return Array.isArray(salvo) && salvo.length ? salvo : PADRAO[papel]
}

export const nomeDaLinha = (l) => (LIVRES[l.k] || CAMPOS[l.k] || ['?', ''])[0]
export const ajudaDaLinha = (l) => (LIVRES[l.k] || CAMPOS[l.k] || ['', ''])[1]
export const ehDado = (k) => !LIVRES[k]

/**
 * o papel em blocos (um por linha do modelo que está ligada), cada um com o que sai:
 * { tipo: 'texto', a, b, al, neg, gra } | { tipo: 'hr' } | { tipo: 'espaco', alt } | { tipo: 'logo' } | { tipo: 'qr', conteudo, tam } | { tipo: 'caixa' }
 */
export function blocos(modelo, d, { logo = null } = {}) {
    return modelo.map((l, idx) => {
        if (!l.on) return null
        const estilo = { al: l.al || 'e', neg: !!l.neg, gra: !!l.gra }
        let saida = []
        if (l.k === 'hr') saida = [{ tipo: 'hr' }]
        else if (l.k === 'espaco') saida = [{ tipo: 'espaco', alt: Math.min(6, Math.max(1, l.alt || 1)) }]
        else if (l.k === 'texto') saida = (l.txt || '').trim() ? [{ tipo: 'texto', a: l.txt, ...estilo }] : []
        else if (l.k === 'logo') saida = logo ? [{ tipo: 'logo' }] : []
        else if (l.k === 'qr') saida = d.link ? [{ tipo: 'qr', conteudo: d.link, tam: l.tam || 'M' }] : []
        else if (l.k === 'carimbo') saida = [{ tipo: 'caixa' }]
        else if (CAMPOS[l.k]) saida = CAMPOS[l.k][2](d).map((r) => ({ tipo: 'texto', ...estilo, ...r, neg: r.neg ?? estilo.neg }))
        return { idx, linha: l, saida }
    }).filter(Boolean)
}

const MODULO_QR = { P: 4, M: 6, G: 8 }

// ---------- logo em bits para a impressora (convertido uma vez, guardado aqui) ----------
let logoBits = null
/** carrega o logo (data URL já em preto e branco, vindo do ERP) e prepara os bits da impressora */
export function carregarLogo(url) {
    logoBits = null
    if (!url || typeof document === 'undefined') return Promise.resolve(null)
    return new Promise((ok) => {
        const img = new Image()
        img.onload = () => {
            const largura = Math.min(384, img.width) - (Math.min(384, img.width) % 8)
            const altura = Math.round(img.height * (largura / img.width))
            const cv = document.createElement('canvas'); cv.width = largura; cv.height = altura
            const cx = cv.getContext('2d'); cx.fillStyle = '#fff'; cx.fillRect(0, 0, largura, altura); cx.drawImage(img, 0, 0, largura, altura)
            const px = cx.getImageData(0, 0, largura, altura).data
            const bytesLinha = largura / 8
            const dados = new Array(bytesLinha * altura).fill(0)
            for (let y = 0; y < altura; y++) {
                for (let x = 0; x < largura; x++) {
                    const i = (y * largura + x) * 4
                    if ((px[i] + px[i + 1] + px[i + 2]) / 3 < 128) dados[y * bytesLinha + (x >> 3)] |= 0x80 >> (x & 7)
                }
            }
            logoBits = { bytesLinha, altura, dados }
            ok(logoBits)
        }
        img.onerror = () => ok(null)
        img.src = url
    })
}

/** comandos ESC/POS do papel */
export function paraEscPos(lista, opcoes = {}) {
    const tk = new Ticket(opcoes)
    const AL = { e: 'esquerda', c: 'centro', d: 'direita' }
    for (const b of lista) {
        for (const s of b.saida) {
            if (s.tipo === 'hr') { tk.alinhar('esquerda').traco(); continue }
            if (s.tipo === 'espaco') { tk.cmd(0x1b, 0x64, s.alt); continue }
            if (s.tipo === 'caixa') { tk.alinhar('esquerda').caixa(4); continue }
            if (s.tipo === 'qr') { tk.alinhar('centro').qr(s.conteudo, MODULO_QR[s.tam] || 6).linha(); continue }
            if (s.tipo === 'logo') {
                if (!logoBits) continue
                const { bytesLinha, altura, dados } = logoBits
                tk.alinhar('centro').cmd(0x1d, 0x76, 0x30, 0x00, bytesLinha & 0xff, bytesLinha >> 8, altura & 0xff, altura >> 8, ...dados).linha()
                continue
            }
            // texto
            const colunas = s.gra ? Math.floor(tk.colunas / 2) : tk.colunas
            tk.negrito(s.neg).tamanho(s.gra ? 2 : 1, s.gra ? 2 : 1)
            if (s.b !== undefined && s.b !== null && s.b !== '') {
                const a = String(s.a); const v = String(s.b)
                if (a.length + v.length + 1 <= colunas) tk.alinhar('esquerda').linha(a + ' '.repeat(colunas - a.length - v.length) + v)
                else tk.alinhar('esquerda').linha(a).alinhar('direita').linha(v)
            } else {
                tk.alinhar(AL[s.al] || 'esquerda').linha(String(s.a))
            }
            tk.tamanho().negrito(false)
        }
    }
    return tk.alinhar('esquerda').avancar(4).cortar().pronto()
}

/** atalho: modelo + dados → bytes */
export const montarPapel = (config, papel, dados, opcoes) => paraEscPos(blocos(modeloDe(config, papel), dados, { logo: config?.logo }), opcoes)

// ---------- dados de cada papel, a partir do que a API devolve ----------
const n6 = (n) => String(n ?? '').padStart(6, '0')
export const linkTicket = (codigo) => `${window.location.origin}/t/${codigo}`

export const dadosTicket = (r, eu) => ({
    empresa: eu.empresa, numero: n6(r.numero), servico: r.servico, placa: r.placa, modelo: r.modelo, tamanho: r.tipo, entrada: r.entrada,
    link: r.codigo ? linkTicket(r.codigo) : null,
    pago: r.pago ? { valor: r.pago.valor, forma: r.pago.forma } : (r.pago_entrada > 0 ? { valor: r.pago_entrada } : null),
})

// o ticket serve para estadia e lavagem; o serviço vem no próprio ticket
export const dadosTicketLavagem = (r, eu) => dadosTicket({ ...r, servico: r.servico || 'Lavagem' }, eu)

/** recibo de pagamento: um só papel para todo pagamento; cada origem preenche o que tem */
export const dadosReciboEstadia = (r, eu) => ({
    empresa: eu.empresa, titulo: `RECIBO · Nº ${n6(r.numero)}`, placa: r.placa, modelo: r.modelo, tamanho: r.tipo, entrada: r.entrada, saida: r.saida, tempo: r.tempo,
    itens: [
        ...(r.valor !== null && r.valor !== undefined ? [{ descricao: r.servico, valor: r.valor }] : []),
        ...(r.convenio ? [{ descricao: `Convênio ${r.convenio.nome || ''}`.trim(), texto: `-${moeda(r.convenio.valor)}` }] : []),
        ...(r.pago_entrada > 0 && r.saida ? [{ descricao: 'Pago na entrada', texto: `-${moeda(r.pago_entrada)}` }] : []),
    ],
    pago: r.pago, situacao: r.destino,
})

export const dadosReciboLavagem = (r, eu) => ({
    empresa: eu.empresa, titulo: `RECIBO · Nº ${n6(r.numero)}`, placa: r.placa, modelo: r.modelo, tamanho: r.tipo, entrada: r.entrada, saida: r.saida, rotuloSaida: 'Entrega',
    itens: r.itens, pago: r.pago, na_conta: (r.itens || []).filter((i) => i.conta).map((i) => i.conta),
})

export const dadosReciboConta = (r, eu) => ({
    empresa: eu.empresa, titulo: 'RECIBO', cliente: r.cliente, data: r.data, itens: r.itens,
    pago: { forma: r.forma, valor: r.total, observacao: r.observacao },
})

export const dadosFechamento = (f, eu) => ({
    empresa: eu.empresa, titulo: 'FECHAMENTO DE CAIXA',
    periodo: [{ a: 'Aberto', b: quando(f.aberto_em) }, { a: 'Fechado', b: quando(f.fechado_em) }, ...(f.fechado_por ? [{ a: 'Por', b: f.fechado_por }] : [])],
    por_servico: [{ a: 'POR SERVIÇO', neg: true }, ...f.por_servico.map((l) => ({ a: `${l.servico} (${l.qtd})`, b: moeda(l.total) })), { a: 'Total recebido', b: moeda(f.total_recebido), neg: true }],
    por_forma: [{ a: 'POR FORMA', neg: true }, ...f.por_forma.map((l) => ({ a: l.forma, b: moeda(l.total) }))],
    dinheiro: [{ a: 'DINHEIRO', neg: true }, { a: 'Troco inicial', b: moeda(f.troco_inicial) }, { a: '+ Recebido', b: moeda(f.dinheiro_recebido) },
        ...(f.suprimentos > 0 ? [{ a: '+ Suprimentos', b: moeda(f.suprimentos) }] : []), ...(f.sangrias > 0 ? [{ a: '- Sangrias', b: moeda(f.sangrias) }] : []),
        ...(f.pagamentos > 0 ? [{ a: '- Pagamentos', b: moeda(f.pagamentos) }] : []), ...(f.retiradas > 0 ? [{ a: '- Retiradas', b: moeda(f.retiradas) }] : []),
        { a: 'Deveria ter', b: moeda(f.dinheiro_esperado), neg: true }, { a: 'Contado', b: moeda(f.dinheiro_contado) }, { a: 'Diferença', b: moeda(f.diferenca) }, { a: 'Fica de troco', b: moeda(f.troco_deixado) }],
    movimentos: f.movimentos?.length ? [{ a: 'MOVIMENTOS', neg: true }, ...f.movimentos.map((m) => ({ a: `${(m.hora || '').slice(6)} ${m.rotulo}`.trim(), b: moeda(m.sinal * m.valor) }))] : [],
})

/** dados de exemplo para o editor e o teste de impressão */
export function exemplo(papel, empresa) {
    const e = empresa || { nome: 'Local Estacionamento', documento: '00.000.000/0001-00', endereco: 'Av. Exemplo, 100 - Santos', telefone: '(13) 0000-0000' }
    const base = { empresa: e, numero: '000215', placa: 'ASD1A23', modelo: 'Gol branco', tamanho: 'Médio', entrada: '19/11/2026 08:12', link: 'https://erp.localestacionamento.com.br/t/exemplo' }
    switch (papel) {
        case 'ticket': return { ...base, servico: 'Diária 12h', pago: { valor: 60, forma: 'Pix' } }
        // exemplo com quase tudo: cada pagamento real só mostra o que tem (conta de cliente não tem placa, estadia não tem cliente…)
        case 'recibo': return { ...base, titulo: 'RECIBO · Nº 000215', cliente: 'João da Silva', saida: '19/11/2026 10:22', tempo: '2h10',
            itens: [{ descricao: 'Estadia', valor: 20 }, { descricao: 'Lavagem simples', valor: 40 }, { descricao: 'Convênio Padaria', texto: `-${moeda(5)}` }],
            pago: { forma: 'Pix', valor: 55, observacao: 'Pix QR de MARIA SILVA' } }
        case 'fechamento': return dadosFechamento({
            aberto_em: '2026-11-19 07:02', fechado_em: '2026-11-19 22:05', fechado_por: 'Gerente', total_recebido: 1530,
            por_servico: [{ servico: 'Estadias', qtd: 42, total: 980 }, { servico: 'Lavagens e serviços', qtd: 7, total: 550 }],
            por_forma: [{ forma: 'Dinheiro', total: 610 }, { forma: 'Pix', total: 720 }, { forma: 'Débito', total: 200 }],
            troco_inicial: 100, dinheiro_recebido: 610, sangrias: 400, suprimentos: 0, pagamentos: 35, retiradas: 0,
            dinheiro_esperado: 275, dinheiro_contado: 275, diferenca: 0, troco_deixado: 100,
            movimentos: [{ hora: '19/11 12:10', rotulo: 'Sangria (cofre)', sinal: -1, valor: 400 }, { hora: '19/11 13:00', rotulo: 'Almoço', sinal: -1, valor: 35 }],
        }, { empresa: e })
        default: return base
    }
}
