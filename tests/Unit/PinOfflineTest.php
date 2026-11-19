<?php

namespace Tests\Unit;

use App\Servicos\Pdv\Pins;
use PHPUnit\Framework\TestCase;

/** a versão do PIN para o celular (Pins::hashOffline) é a mesma conta do JavaScript (tests/js/pin.test.mjs) */
class PinOfflineTest extends TestCase
{
    public function test_mesmo_vetor_do_javascript(): void
    {
        $this->assertSame('71b0c6a72bb96785ba964c87dfe637fcd9db5843ed5e988effe48caa4a5f5e7e', hash_pbkdf2('sha256', '4831', 'sal-teste', Pins::OFFLINE_RODADAS, 64));
    }
}
