// PIN sem internet (8.7): o celular faz a mesma conta do servidor (Pins::hashOffline, tests/Unit/PinOfflineTest.php).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { hashOffline, trancarPin } from '../../resources/js/pdv/offline/pins.js'

test('mesma versão do PIN que o servidor (PBKDF2-SHA256)', async () => {
    assert.equal(await hashOffline('4831', 'sal-teste', 100000), '71b0c6a72bb96785ba964c87dfe637fcd9db5843ed5e988effe48caa4a5f5e7e')
})

// auditoria de 21/11/2026: o PIN de cada ação da fila vai trancado com a chave pública do servidor (RSA-OAEP SHA-1,
// o mesmo que o openssl_private_decrypt do PHP abre, Pins::abrirProva)
test('PIN trancado só abre com a chave privada, com o uuid da ação', async () => {
    const { generateKeyPairSync, privateDecrypt, constants } = await import('node:crypto')
    const { publicKey, privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 })
    const chave = publicKey.export({ type: 'spki', format: 'der' }).toString('base64')
    const prova = await trancarPin('4831', 'a1b2c3d4-0000-4000-8000-000000000001', chave)
    assert.ok(!prova.includes('4831'))
    const aberto = privateDecrypt({ key: privateKey, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, Buffer.from(prova, 'base64'))
    assert.deepEqual(JSON.parse(aberto.toString()), { p: '4831', u: 'a1b2c3d4-0000-4000-8000-000000000001' })
    assert.equal(await trancarPin('4831', 'x', null), null) // cópia antiga, sem chave: vai sem prova (vira pendência)
})
