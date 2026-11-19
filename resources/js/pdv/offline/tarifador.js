/*
 * Tarifador no celular, para o modo sem internet (8.7). Cópia fiel de app/Servicos/Tarifador.php e do cálculo de
 * Estadias::calcular (diária marcada + convênio). Os dois são comparados com os mesmos casos
 * (tests/js/fixtures/tarifador.json, gerado pelo PHP): mexeu num, mexe no outro e roda os testes.
 *
 * Datas no formato do servidor, "AAAA-MM-DD HH:MM", na hora de Brasília (sem horário de verão desde 2019).
 */

const moeda = (v) => `R$ ${Number(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`
const redondo = (v) => Math.round((v + Number.EPSILON) * 100) / 100

/** "AAAA-MM-DD HH:MM" → minutos desde 1970 (relógio de parede, sem fuso) */
export function minutosDe(texto) {
    const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(texto || ''))
    if (!m) throw new Error(`Data inválida: ${texto}`)
    return Math.floor(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) / 60000)
}

/** minutos → "AAAA-MM-DD HH:MM" */
export function textoDe(min) {
    return new Date(min * 60000).toISOString().slice(0, 16).replace('T', ' ')
}

/** agora em Brasília, "AAAA-MM-DD HH:MM" (o celular pode estar em outro fuso; a conta é sempre na hora daqui) */
export function agoraBrasilia(data = new Date()) {
    return textoDe(Math.floor(data.getTime() / 60000) - 180)
}

export function duracao(min) {
    if (min < 60) return `${min} min`
    return `${Math.floor(min / 60)}h${min % 60 ? String(min % 60).padStart(2, '0') : ''}`
}

const hora = (h) => {
    const m = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(String(h ?? '').trim())
    return m ? `${m[1].padStart(2, '0')}:${m[2]}` : null
}

const rotuloTempo = (min) => (min % 60 === 0 ? `${min / 60}h` : min >= 60 ? `${Math.floor(min / 60)}h${String(min % 60).padStart(2, '0')}` : `${min} min`)
const rotuloBloco = (b) => (b === 1 ? 'minuto' : b === 60 ? 'hora' : b % 60 === 0 ? `${b / 60}h` : `${b} min`)

export class Tarifador {
    constructor(tabela) {
        const t = tabela || {}
        this.t = {
            tolerancia: Math.max(0, parseInt(t.tolerancia ?? 0, 10) || 0),
            fracao: Math.max(0, parseInt(t.fracao ?? 0, 10) || 0),
            restante: t.restante === 'pacotes' ? 'pacotes' : 'faixas',
            faixas: [],
            pacotes: [],
            janelas: [],
        }
        for (const f of t.faixas || []) {
            const dur = f.duracao
            this.t.faixas.push({
                duracao: dur === null || dur === undefined || dur === '' || parseInt(dur, 10) <= 0 ? null : parseInt(dur, 10),
                modo: f.modo === 'fixo' ? 'fixo' : 'bloco',
                bloco: Math.max(1, parseInt(f.bloco ?? 60, 10) || 0),
                valor: redondo(Number(f.valor ?? 0)),
            })
        }
        for (const p of t.pacotes || []) {
            const ini = hora(p.hora_inicio)
            const fim = hora(p.hora_fim)
            const pac = { nome: String(p.nome ?? 'Diária').trim() || 'Diária', duracao: parseInt(p.duracao ?? 0, 10) || 0, valor: redondo(Number(p.valor ?? 0)) }
            if (ini !== null && fim !== null) this.t.janelas.push({ ...pac, inicio: ini, fim })
            else if (pac.duracao > 0) this.t.pacotes.push(pac)
        }
        this.memo = new Map()
    }

    /** { valor, minutos, detalhes } entre "AAAA-MM-DD HH:MM" de entrada e de saída */
    calcular(entrada, saida) {
        const e = minutosDe(entrada)
        const s = minutosDe(saida)
        if (s < e) throw new Error('A saída precisa ser depois da entrada.')
        const min = s - e
        let r = this.porMinutos(min)
        for (const j of this.t.janelas) {
            if (min > this.t.tolerancia && this.dentroJanela(e, s, j) && j.valor < r.valor) {
                r = { valor: j.valor, detalhes: [`${j.nome} (${j.inicio} às ${j.fim}): ${moeda(j.valor)}`] }
            }
        }
        return { valor: r.valor, minutos: min, detalhes: r.detalhes }
    }

    porMinutos(min) {
        min = Math.max(0, min)
        if (min <= this.t.tolerancia) return { valor: 0, detalhes: [`Dentro da tolerância (${this.t.tolerancia} min): sem cobrança`] }
        const [valor, detalhes] = this.agrupar(this.custo(min, true))
        return { valor: redondo(valor), detalhes }
    }

    custo(min, primeiro) {
        if (min <= 0) return [0, []]
        const k = `${min}|${primeiro ? 1 : 0}`
        if (this.memo.has(k)) return this.memo.get(k)
        let maior = 0
        for (const p of this.t.pacotes) maior = Math.max(maior, p.duracao)
        let melhor = null
        if (this.t.faixas.length && (this.t.restante === 'faixas' || (primeiro && (maior === 0 || min <= maior)))) melhor = this.faixas(min)
        for (const p of this.t.pacotes) {
            const linha = `${p.nome}: ${moeda(p.valor)}`
            let c
            if (min <= p.duracao) c = [p.valor, [linha]]
            else {
                const resto = this.custo(min - p.duracao, false)
                c = [p.valor + resto[0], [linha, ...resto[1]]]
            }
            if (melhor === null || c[0] < melhor[0] - 0.0001) melhor = c
        }
        const r = melhor ?? [0, ['Tabela sem regras de cobrança']]
        this.memo.set(k, r)
        return r
    }

    faixas(min) {
        let resto = min
        let total = 0
        const det = []
        const n = this.t.faixas.length
        for (let i = 0; i < n; i++) {
            const f = this.t.faixas[i]
            if (resto <= 0) break
            const ultima = i === n - 1
            const usa = ultima || f.duracao === null ? resto : Math.min(resto, f.duracao)
            if (f.modo === 'fixo') {
                total += f.valor
                det.push(`${rotuloTempo(usa)}: ${moeda(f.valor)}`)
            } else {
                let blocos = Math.floor(usa / f.bloco)
                const sobra = usa - blocos * f.bloco
                if (sobra > this.t.fracao || (blocos === 0 && sobra > 0 && total === 0)) blocos++
                if (blocos > 0) {
                    total += blocos * f.valor
                    det.push(`${blocos} × ${rotuloBloco(f.bloco)} × ${moeda(f.valor)} = ${moeda(blocos * f.valor)}`)
                }
            }
            resto -= usa
            if (f.duracao === null) break
        }
        return [total, det]
    }

    agrupar([valor, linhas]) {
        const cont = new Map()
        for (const l of linhas) cont.set(l, (cont.get(l) || 0) + 1)
        const out = []
        for (const [l, n] of cont) {
            const m = n > 1 ? /^(.*): R\$ ([\d.,]+)$/.exec(l) : null
            if (m) out.push(`${n} × ${m[1]}: ${moeda(Number(m[2].replace(/\./g, '').replace(',', '.')) * n)}`)
            else for (let i = 0; i < n; i++) out.push(l)
        }
        return [valor, out]
    }

    dentroJanela(e, s, j) {
        const [hi, mi] = j.inicio.split(':').map(Number)
        const [hf, mf] = j.fim.split(':').map(Number)
        const ini = hi * 60 + mi
        const fim = hf * 60 + mf
        const dur = ((fim - ini + 1440) % 1440) || 1440
        const minE = ((e % 1440) + 1440) % 1440
        let comeco = e - minE + ini
        if (ini > fim && minE < fim) comeco -= 1440 // janela que vira a meia-noite
        return e >= comeco && s <= comeco + dur
    }
}

/** Convenios::dividir, só o uso "estadia" */
export function dividirConvenio(conv, valor, minutos, valorAte) {
    valor = redondo(valor)
    const nada = { convenio: 0, desconto: 0, cliente: valor, regra: '' }
    if (!conv || valor <= 0) return nada
    const r = conv.regra
    const nome = conv.nome || 'Convênio'
    if (!r) return { ...nada, regra: `${nome}: o convênio não cobre estadia` }
    let pago = 0
    if (r.modo === 'integral') pago = valor
    else if (r.modo === 'parcial') pago = Math.min(valor, redondo(Number(r.limite_valor || 0)))
    else if (r.modo === 'tempo') pago = minutos <= (r.limite_min || 0) || !valorAte ? valor : Math.min(valor, redondo(valorAte(r.limite_min || 0)))
    pago = redondo(Math.max(0, pago))
    const regra = r.modo === 'integral' ? `${nome} paga tudo` : r.modo === 'parcial' ? `${nome} paga até ${moeda(r.limite_valor)}` : `${nome} paga até ${duracao(r.limite_min || 0)}`
    return { convenio: pago, desconto: 0, cliente: redondo(valor - pago), regra: regra + (pago < valor ? '; o excedente fica com quem usou' : '') }
}

/**
 * Estadias::calcular no celular. e: { entrada, valor_pacote, pacote_id, pago_entrada }; tabela: { id, nome, regras };
 * conv: convênio do pacote ({ nome, regra }) ou null. Mesmo formato da resposta /estadias/{id}/calculo.
 */
export function calcularEstadia(e, saida, tabela, conv) {
    if (!tabela) throw new Error('Não há tabela de estadia para este tamanho de veículo.')
    if (minutosDe(saida) < minutosDe(e.entrada)) throw new Error(`A saída precisa ser depois da entrada.`)
    const t = new Tarifador(tabela.regras)
    const r = t.calcular(e.entrada, saida)
    if (e.pacote_id && Number(e.valor_pacote || 0) > r.valor) {
        r.valor = redondo(Number(e.valor_pacote))
        r.detalhes = [...r.detalhes, `Diária marcada na entrada: ${moeda(r.valor)}`]
    }
    const div = dividirConvenio(conv, r.valor, r.minutos, (min) => t.calcular(e.entrada, textoDe(minutosDe(e.entrada) + min)).valor)
    const pagoEntrada = Number(e.pago_entrada || 0)
    return {
        saida,
        minutos: r.minutos,
        tempo: duracao(r.minutos),
        valor: r.valor,
        detalhes: r.detalhes,
        tarifa_id: tabela.id,
        tarifa: tabela.nome,
        pago_entrada: pagoEntrada,
        a_pagar: redondo(Math.max(0, div.cliente - pagoEntrada)),
        ...div,
    }
}
