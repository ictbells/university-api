<?php

namespace Tests\Unit;

use App\Support\NinCipher;
use Tests\TestCase;

class NinCipherTest extends TestCase
{
    public function test_it_encrypts_hashes_and_redacts_a_nin(): void
    {
        $nin = '12345678901';

        $encrypted = NinCipher::encrypt($nin);
        $this->assertNotSame($nin, $encrypted);
        $this->assertSame($nin, NinCipher::decrypt($encrypted));
        $this->assertTrue(NinCipher::isPlain($nin));
        $this->assertFalse(NinCipher::isPlain($encrypted));
        $this->assertSame(NinCipher::hash($nin), NinCipher::hash('123-456-789-01'));
        $this->assertSame('********901', NinCipher::redact($nin));

        $opened = NinCipher::openPayload(NinCipher::sealPayload(['nin' => $nin, 'first_name' => 'Ada']));
        $this->assertSame($nin, $opened['nin']);
        $this->assertSame('Ada', $opened['first_name']);
    }
}
