import { ErroApi } from './servidor'
import { novoCodigo, novoUuid } from './estado'
import { enfileirar, fila, pacote } from './fila'
import { conferirPinLocal } from './pins'
import { agoraBrasilia, calcularEstadia, duracao, minutosDe } from './tarifador'

/*
 * As rotas da API que o celular responde sozinho quando está sem internet (8.7), com a cópia do servidor (pacote) e a
 * fila. Mesmas respostas da API, para as telas funcionarem sem mudar. Estadia (entrada e saída) funciona;
 * lavagem, caixa e cancelamento pedem internet nesta etapa.
 */

const SO_COM_INTERNET = 'Sem internet. Isso precisa de internet; entrada e saída de estadia funcionam sem.'

const DESTINOS = {
    pago: 'Pago na saída', conta: 'Na conta do cliente', convenio: 'Pago pelo convênio', mensalista: 'Mensalista',
    gratis: 'Sem cobrança (tolerância)', pago_entrada: 'Diária paga na entrada',
}

const erro = (msg, campo = null, status = 422) => new ErroApi(msg, status, campo ? { campos: { [campo]: [msg] } } : {})
const placaDe = (p) => (String(p || '').toUpperCase().replace(/[^A-Z0-9]/g, '') || null)
const br = (t) => (t ? `${t.slice(8, 10)}/${t.slice(5, 7)}/${t.slice(0, 4)} ${t.slice(11, 16)}` : null)
const moeda = (v) => `R$ ${Number(v).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`

/** rotas que o celular sabe responder sem internet */
const ROTAS = [
    ['GET', /^\/eu$/], ['GET', /^\/inicio$/], ['GET', /^\/estadias\/opcoes$/], ['GET', /^\/estadias\/patio$/],
    ['GET', /^\/estadias\/placa\/[^/]+$/], ['GET', /^\/estadias\/buscar/], ['GET', /^\/estadias\/[^/]+\/calculo/],
    ['GET', /^\/ticket/], ['GET', /^\/clientes/], ['POST', /^\/pin\/conferir$/], ['POST', /^\/estadias$/], ['POST', /^\/estadias\/[^/]+\/saida$/],
]

export const sabeResponder = (metodo, caminho) => ROTAS.some(([m, r]) => m === metodo && r.test(caminho.split('?')[0]))

/** o pátio deste celular: o do pacote, mais as entradas e menos as saídas que estão na fila */
export function patioLocal() {
    const p = pacote()
    if (!p) return []
    let lista = (p.patio || []).map((e) => ({ ...e }))
    for (const i of fila()) {
        if (i.tipo === 'estadia_entrada' && i.local?.estadia && !lista.some((e) => e.codigo === i.local.estadia.codigo)) lista.unshift({ ...i.local.estadia })
        if (i.tipo === 'estadia_saida') lista = lista.filter((e) => e.codigo !== i.dados.codigo)
    }
    const agora = minutosDe(agoraBrasilia())
    return lista.map((e) => ({ ...e, tempo: duracao(Math.max(0, agora - minutosDe(e.entrada))) }))
}

function acharNoPatio(q) {
    let t = String(q || '').trim()
    const m = /\/t\/([a-z0-9]{16})/i.exec(t)
    if (m) [, t] = m
    const lista = patioLocal()
    if (/^[a-z0-9]{16}$/i.test(t) && !/^\d+$/.test(t)) {
        const e = lista.find((x) => x.codigo === t.toLowerCase())
        if (e) return e
    }
    if (/^\d+$/.test(t)) {
        const n = Number(t)
        const e = lista.find((x) => x.numero === n) || lista.find((x) => x.numero_impresso === n)
        if (e) return e
    }
    const p = placaDe(t)
    return p ? lista.find((x) => x.placa === p) || null : null
}

const acharPorId = (id) => patioLocal().find((e) => String(e.id) === String(id)) || null

async function pessoa(pin) {
    try {
        return await conferirPinLocal(pin)
    } catch (e) {
        throw erro(e.message, 'pin')
    }
}

function proximoNumero() {
    const p = pacote()
    const daFila = fila().filter((i) => i.tipo === 'estadia_entrada').map((i) => Number(i.dados.numero) || 0)
    return Math.max(Number(p?.ultimo_numero) || 0, ...daFila, 0) + 1
}

function convenioDe(id) {
    return (pacote()?.convenios || []).find((c) => c.id === Number(id)) || null
}

function calcular(e, convenioId, saida) {
    const p = pacote()
    const conv = convenioId === undefined ? (e.convenio ? convenioDe(e.convenio.id) : null) : convenioId ? convenioDe(convenioId) : null
    if (convenioId && !conv) throw erro('Convênio não encontrado, inativo ou sem estadias.', 'convenio_id')
    return calcularEstadia(e, saida || agoraBrasilia(), p.tabelas?.[e.tipo_veiculo_id], conv)
}

export async function responder(metodo, caminho, dados = {}) {
    const p = pacote()
    if (!p) throw new ErroApi('Sem internet e o celular ainda não tem a cópia do sistema. Abra o PDV uma vez com internet.', 0, { semInternet: true })
    const [rota, busca = ''] = caminho.split('?')
    const q = new URLSearchParams(busca)
    let m

    if (metodo === 'GET') {
        if (rota === '/eu') return { ...p.eu, pix_qr: false, sem_internet: true }
        if (rota === '/inicio') return { patio: patioLocal().length, lavagens: null, caixa: p.caixa }
        if (rota === '/estadias/opcoes') return p.opcoes
        if (rota === '/estadias/patio') return patioLocal()
        if (rota.startsWith('/clientes')) return []
        if ((m = /^\/estadias\/placa\/(.+)$/.exec(rota))) {
            const placa = placaDe(decodeURIComponent(m[1]))
            const no = placa && patioLocal().find((e) => e.placa === placa)
            const v = placa && (p.mensalistas || []).find((x) => x.placa === placa)
            return {
                placa,
                no_patio: no ? { id: no.id, numero: no.numero, entrada: `${no.entrada.slice(8, 10)}/${no.entrada.slice(5, 7)} ${no.entrada.slice(11, 16)}` } : null,
                veiculo: v ? { modelo: v.modelo, tipo_id: v.tipo_id, cliente: v.cliente, mensalista: true } : null,
                ultima: null,
            }
        }
        if (rota === '/estadias/buscar' || rota === '/ticket') {
            const e = acharNoPatio(q.get('q'))
            if (!e) throw new ErroApi(rota === '/ticket' ? 'Nenhuma estadia no pátio com esse QR, número ou placa (sem internet, só estadias).' : 'Nenhum veículo no pátio com esse ticket ou placa.', 404)
            return rota === '/ticket'
                ? { achados: [{ tipo: 'estadia', id: e.id, numero: e.numero, placa: e.rotulo, detalhe: `Estadia · entrou ${e.entrada.slice(11, 16)}` }] }
                : { estadia: e }
        }
        if ((m = /^\/estadias\/([^/]+)\/calculo$/.exec(rota))) {
            const e = acharPorId(m[1])
            if (!e) throw new ErroApi('Esta estadia não está mais no pátio.', 404)
            const conv = q.has('convenio_id') ? (q.get('convenio_id') || null) : undefined
            try {
                return { estadia: e, calculo: calcular(e, conv) }
            } catch (ex) {
                throw ex instanceof ErroApi ? ex : erro(ex.message)
            }
        }
    }

    if (metodo === 'POST') {
        if (rota === '/pin/conferir') return { usuario: await pessoa(dados.pin) }
        if (rota === '/estadias') return entrar(p, dados)
        if ((m = /^\/estadias\/([^/]+)\/saida$/.exec(rota))) return sair(p, m[1], dados)
    }
    throw new ErroApi(SO_COM_INTERNET, 0, { semInternet: true })
}

async function entrar(p, d) {
    const placa = placaDe(d.placa)
    const ident = String(d.identificacao || '').trim() || null
    if (!placa && !ident) throw erro('Informe a placa (ou, sem placa, uma identificação do veículo).', 'placa')
    const tipo = (p.opcoes.tipos || []).find((t) => t.id === Number(d.tipo_veiculo_id))
    if (!tipo || !p.tabelas?.[tipo.id]) throw erro('Escolha o tamanho do veículo.', 'tipo_veiculo_id')
    if (placa && patioLocal().some((e) => e.placa === placa)) throw erro(`${placa} já está no pátio.`, 'placa')
    const diaria = d.pacote_id ? (tipo.diarias || []).find((x) => x.id === Number(d.pacote_id)) : null
    if (d.pacote_id && !diaria) throw erro('Diária não encontrada na tabela deste tamanho de veículo.', 'pacote_id')
    const pagarAgora = !!(diaria && d.pagar_agora)
    const forma = pagarAgora ? (p.opcoes.formas || []).find((f) => f.id === Number(d.forma_pagamento_id)) : null
    if (pagarAgora && !forma) throw erro('Escolha a forma de pagamento da diária.', 'forma_pagamento_id')
    const recebido = d.valor_recebido !== null && d.valor_recebido !== undefined && d.valor_recebido !== '' ? Number(d.valor_recebido) : null
    if (forma?.tipo === 'dinheiro' && recebido !== null && recebido + 0.001 < diaria.valor) throw erro(`O valor recebido é menor que o total (${moeda(diaria.valor)}).`, 'valor_recebido')
    const mens = placa && (p.mensalistas || []).find((x) => x.placa === placa)
    if (mens && !d.confirmar_mensalista) {
        throw erro(`Mensalista: ${mens.cliente || placa}. Mensalista não registra entrada; confirme só se for preciso (ex.: veículo fora do contrato).`, 'mensalista')
    }
    const u = await pessoa(d.pin)

    const uuid = d.acao_uuid || novoUuid()
    const codigo = d.codigo || novoCodigo()
    const numero = proximoNumero()
    const entrada = agoraBrasilia()
    const modelo = String(d.modelo || '').trim().slice(0, 40) || mens?.modelo || null
    const estadia = {
        id: `l-${uuid}`, numero, codigo, rotulo: placa || ident, placa, identificacao: placa ? null : ident.slice(0, 40), modelo,
        diaria: diaria ? { nome: diaria.nome, valor: diaria.valor } : null, pago_entrada: pagarAgora ? diaria.valor : 0, retirada: null, pdv: true,
        tipo: tipo.nome, tipo_veiculo_id: tipo.id, cliente: mens ? { id: mens.cliente_id, nome: mens.cliente } : null, convenio: null, mensalista: !!mens,
        celular: null, entrada, saida: null, tempo: '0 min', valor_calculado: null, valor_convenio: 0, valor_cliente: null, situacao: 'patio', destino: null,
        pacote_id: diaria?.id ?? null, valor_pacote: diaria?.valor ?? null, numero_impresso: null, sem_internet: true,
    }
    const troco = forma?.tipo === 'dinheiro' && recebido !== null && recebido > diaria.valor ? `Recebido ${moeda(recebido)} · troco ${moeda(recebido - diaria.valor)}` : null
    enfileirar({
        uuid, tipo: 'estadia_entrada', feita_em: new Date().toISOString(), usuario_id: u.id,
        dados: { placa, identificacao: placa ? null : ident, modelo, tipo_veiculo_id: tipo.id, pacote_id: diaria?.id ?? null, pagar_agora: pagarAgora,
            forma_pagamento_id: forma?.id ?? null, valor_recebido: recebido, codigo, numero, rotulo: placa || ident },
        local: { estadia },
    })
    return {
        estadia,
        recibo: {
            numero, codigo, placa: placa || ident, modelo, tipo: tipo.nome, servico: diaria ? diaria.nome : 'Estadia', entrada: br(entrada), saida: null, tempo: null,
            valor: null, convenio: null, pago_entrada: pagarAgora ? diaria.valor : 0,
            pago: pagarAgora ? { valor: diaria.valor, forma: forma.nome, observacao: troco } : null, destino: null,
        },
        sem_internet: true,
    }
}

async function sair(p, id, d) {
    const e = acharPorId(id)
    if (!e) throw erro('Esta estadia não está mais no pátio deste celular.', 'saida')
    let convenioId
    if ('convenio_id' in d) {
        convenioId = d.convenio_id || null
        if (convenioId && e.pago_entrada > 0) throw erro('A diária já foi paga na entrada: não leva convênio.', 'convenio_id')
    }
    const perdido = !!d.perdido
    const nome = String(d.retirada_nome || '').trim()
    const doc = String(d.retirada_documento || '').trim()
    if (perdido && (nome.length < 3 || doc.length < 4)) throw erro('Ticket perdido: informe o nome e o número de um documento de quem está retirando.', 'retirada_nome')
    const saida = d.saida_calculo && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(d.saida_calculo) && minutosDe(agoraBrasilia()) - minutosDe(d.saida_calculo) <= 20
        ? d.saida_calculo.slice(0, 16) : agoraBrasilia()
    let c
    try {
        c = calcular(e, convenioId, saida)
    } catch (ex) {
        throw ex instanceof ErroApi ? ex : erro(ex.message, 'saida')
    }
    const destino = d.destino || 'pago'
    if (destino === 'mensalista' && !e.mensalista) throw erro('Este veículo não tem mensalidade vigente.', 'destino')
    let contaId = null
    if (destino === 'conta') {
        contaId = Number(d.conta_cliente_id) || e.cliente?.id || null
        if (!contaId) throw erro('Sem internet, escolha "Pagar agora" ou lance depois na gestão (a busca de clientes precisa de internet).', 'conta_cliente_id')
    }
    const cliente = destino === 'mensalista' ? 0 : c.a_pagar
    const forma = (p.opcoes.formas || []).find((f) => f.id === Number(d.forma_pagamento_id)) || null
    if (cliente > 0 && destino === 'pago' && !forma) throw erro('Escolha a forma de pagamento.', 'forma_pagamento_id')
    const recebido = d.valor_recebido !== null && d.valor_recebido !== undefined && d.valor_recebido !== '' ? Number(d.valor_recebido) : null
    if (destino === 'pago' && forma?.tipo === 'dinheiro' && recebido !== null && recebido + 0.001 < cliente) throw erro(`O valor recebido é menor que o total (${moeda(cliente)}).`, 'valor_recebido')
    const u = await pessoa(d.pin)

    const final = destino === 'mensalista' ? 'mensalista' : cliente > 0 ? destino : c.convenio > 0 ? 'convenio' : c.pago_entrada > 0 ? 'pago_entrada' : 'gratis'
    const conv = convenioId === undefined ? e.convenio : convenioId ? convenioDe(convenioId) : null
    enfileirar({
        uuid: d.acao_uuid || novoUuid(), tipo: 'estadia_saida', feita_em: new Date().toISOString(), usuario_id: u.id,
        dados: { codigo: e.codigo, destino, convenio_id: convenioId === undefined ? (e.convenio?.id ?? null) : convenioId, conta_cliente_id: contaId,
            forma_pagamento_id: forma?.id ?? null, forma_nome: forma?.nome ?? null, valor_recebido: recebido, cobrado: cliente,
            perdido, retirada_nome: perdido ? nome : null, retirada_documento: perdido ? doc : null, rotulo: e.rotulo },
    })
    const troco = forma?.tipo === 'dinheiro' && recebido !== null && recebido > cliente ? `Recebido ${moeda(recebido)} · troco ${moeda(recebido - cliente)}` : null
    return {
        estadia: { ...e, situacao: 'finalizada', saida: c.saida, destino: final, destino_rotulo: DESTINOS[final], valor_calculado: c.valor, valor_cliente: cliente },
        recibo: {
            numero: e.numero, codigo: e.codigo, placa: e.rotulo, modelo: e.modelo, tipo: e.tipo, servico: e.diaria ? e.diaria.nome : 'Estadia',
            entrada: br(e.entrada), saida: br(c.saida), tempo: c.tempo, valor: c.valor,
            convenio: c.convenio > 0 ? { nome: conv?.nome ?? null, valor: c.convenio } : null, pago_entrada: c.pago_entrada,
            pago: destino === 'pago' && cliente > 0 ? { valor: cliente, forma: forma?.nome, observacao: troco } : null, destino: DESTINOS[final],
        },
        mensagem: `Saída de ${e.rotulo}: ${c.tempo} · ${DESTINOS[final]} (sem internet, sobe depois).`,
        sem_internet: true,
    }
}
