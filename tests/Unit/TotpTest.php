<?php

namespace Tests\Unit;

use App\Support\Totp;
use Tests\TestCase;

class TotpTest extends TestCase
{
    public function test_qr_data_uri_is_an_svg_barcode_for_the_otpauth_url(): void
    {
        $url = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'staff@example.com', 'Bells');
        $uri = Totp::qrDataUri($url);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);

        $svg = base64_decode((string) substr($uri, strlen('data:image/svg+xml;base64,')), true);
        $this->assertIsString($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertGreaterThan(500, strlen($svg));
    }

    public function test_generated_codes_verify_within_the_time_window(): void
    {
        $secret = Totp::generateSecret();
        $code = (new \ReflectionClass(Totp::class))->getMethod('codeAt');
        $code->setAccessible(true);
        $current = $code->invoke(null, $secret, (int) floor(time() / 30));

        $this->assertTrue(Totp::verify($secret, $current));
    }
}
