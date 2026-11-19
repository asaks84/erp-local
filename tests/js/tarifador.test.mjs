// Tarifador do celular (modo sem internet, 8.7) contra os casos gerados pelo PHP (tests/Unit/TarifadorParidadeTest.php).
// Rodar: npm run test:js
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { calcularEstadia, agoraBrasilia, duracao } from '../../resources/js/pdv/offline/tarifador.js'

const casos = JSON.parse(readFileSync(new URL('./fixtures/tarifador.json', import.meta.url), 'utf8'))

test('mesmo valor, tempo, detalhes e divisão do convênio que o servidor', () => {
    let erros = 0
    casos.forEach((c, i) => {
        const r = calcularEstadia(c.estadia, c.saida, { id: 1, nome: 'T', regras: c.tabela }, c.convenio)
        const obtido = { minutos: r.minutos, valor: r.valor, detalhes: r.detalhes, convenio: r.convenio, cliente: r.cliente, regra: r.regra, a_pagar: r.a_pagar }
        try {
            assert.deepEqual(obtido, c.esperado)
        } catch (e) {
            erros++
            if (erros <= 5) console.error(`caso ${i}`, JSON.stringify({ tabela: c.tabela, estadia: c.estadia, saida: c.saida, convenio: c.convenio }), '\n', e.message)
        }
    })
    assert.equal(erros, 0, `${erros} de ${casos.length} casos diferentes do servidor`)
})

test('hora de Brasília e duração', () => {
    assert.equal(agoraBrasilia(new Date('2026-11-19T15:00:00Z')), '2026-11-19 12:00')
    assert.equal(duracao(45), '45 min')
    assert.equal(duracao(90), '1h30')
    assert.equal(duracao(120), '2h')
})
