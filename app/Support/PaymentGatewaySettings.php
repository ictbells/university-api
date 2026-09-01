<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

class PaymentGatewaySettings
{
    public const ACTIVE = 'payments.active_gateway';

    public const PAYSTACK = 'paystack';

    public const WEMA = 'wema';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::PAYSTACK, self::WEMA];
    }

    public static function defaults(): array
    {
        return [
            'payment_gateway' => self::PAYSTACK,
            'payment_gateways' => [
                self::PAYSTACK => [
                    'key' => self::PAYSTACK,
                    'label' => 'Paystack',
                    'configured' => self::paystackConfigured(),
                ],
                self::WEMA => [
                    'key' => self::WEMA,
                    'label' => 'Wema Bank',
                    'configured' => self::wemaConfigured(),
                ],
            ],
        ];
    }

    public static function all(): array
    {
        $defaults = self::defaults();
        $active = self::active();

        return [
            'payment_gateway' => $active,
            'payment_gateways' => $defaults['payment_gateways'],
        ];
    }

    public static function active(): string
    {
        $value = strtolower(trim((string) Setting::getValue(self::ACTIVE, self::PAYSTACK)));

        return in_array($value, self::keys(), true) ? $value : self::PAYSTACK;
    }

    public static function paystackConfigured(): bool
    {
        return (string) config('services.paystack.secret') !== ''
            && (string) config('services.paystack.public') !== '';
    }

    public static function wemaConfigured(): bool
    {
        return (string) config('services.wema.public') !== ''
            && (string) config('services.wema.secret') !== ''
            && (string) config('services.wema.business_id') !== '';
    }

    public static function configured(string $gateway): bool
    {
        return match ($gateway) {
            self::WEMA => self::wemaConfigured(),
            default => self::paystackConfigured(),
        };
    }

    public static function demoAllowed(): bool
    {
        return (bool) config('services.paystack.allow_demo_fulfill');
    }

    public static function update(array $data): array
    {
        if (! array_key_exists('payment_gateway', $data)) {
            return self::all();
        }

        $gateway = strtolower(trim((string) $data['payment_gateway']));
        if (! in_array($gateway, self::keys(), true)) {
            throw new InvalidArgumentException('Unknown payment gateway.');
        }

        if ($gateway === self::WEMA && ! self::wemaConfigured()) {
            throw new InvalidArgumentException('Wema Bank is not configured. Add ALATPay keys in the server environment first.');
        }

        Setting::setValue(self::ACTIVE, $gateway);

        return self::all();
    }
}
