// PIN sem internet (8.7): o celular faz a mesma conta do servidor (Pins::hashOffline, tests/Unit/PinOfflineTest.php).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { hashOffline } from '../../resources/js/pdv/offline/pins.js'

test('mesma versão do PIN que o servidor (PBKDF2-SHA256)', async () => {
    assert.equal(await hashOffline('4831', 'sal-teste', 100000), '71b0c6a72bb96785ba964c87dfe637fcd9db5843ed5e988effe48caa4a5f5e7e')
})
