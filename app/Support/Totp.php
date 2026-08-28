<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public static function provisioningUri(string $secret, string $label, string $issuer): string
    {
        $encodedLabel = rawurlencode($issuer.':'.$label);
        $encodedIssuer = rawurlencode($issuer);

        return "otpauth://totp/{$encodedLabel}?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    public static function qrDataUri(string $otpauthUrl): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'outputBase64' => true,
            'addQuietzone' => true,
            'eccLevel' => EccLevel::M,
            'svgAddXmlHeader' => false,
        ]);

        return (new QRCode($options))->addByteSegment($otpauthUrl)->render();
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    private static function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $val = strpos(self::BASE32_ALPHABET, $secret[$i]);
            if ($val === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}
