<?php

namespace App\Support;

use App\Models\Setting;
use InvalidArgumentException;

class PaymentGatewaySettings
{
    public const ACTIVE = 'payments.active_gateway';

    public const PAYSTACK = 'paystack';

    public const WEMA = 'wema';

    public const PAYGATE = 'paygate';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::PAYSTACK, self::WEMA, self::PAYGATE];
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
                    'missing' => self::paystackMissing(),
                ],
                self::WEMA => [
                    'key' => self::WEMA,
                    'label' => 'Wema Bank',
                    'configured' => self::wemaConfigured(),
                    'missing' => self::wemaMissing(),
                ],
                self::PAYGATE => [
                    'key' => self::PAYGATE,
                    'label' => 'PayGate (Upperlink)',
                    'configured' => self::paygateConfigured(),
                    'missing' => self::paygateMissing(),
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
        return self::paystackMissing() === [];
    }

    /**
     * @return list<string>
     */
    public static function paystackMissing(): array
    {
        $missing = [];
        if ((string) config('services.paystack.public') === '') {
            $missing[] = 'PAYSTACK_PUBLIC_KEY';
        }
        if ((string) config('services.paystack.secret') === '') {
            $missing[] = 'PAYSTACK_SECRET_KEY';
        }

        return $missing;
    }

    public static function wemaConfigured(): bool
    {
        return self::wemaMissing() === [];
    }

    /**
     * @return list<string>
     */
    public static function wemaMissing(): array
    {
        $missing = [];
        if ((string) config('services.wema.public') === '') {
            $missing[] = 'WEMA_ALATPAY_PUBLIC_KEY';
        }
        if ((string) config('services.wema.secret') === '') {
            $missing[] = 'WEMA_ALATPAY_SECRET_KEY';
        }
        if ((string) config('services.wema.business_id') === '') {
            $missing[] = 'WEMA_ALATPAY_BUSINESS_ID';
        }

        return $missing;
    }

    public static function paygateConfigured(): bool
    {
        return self::paygateMissing() === [];
    }

    /**
     * @return list<string>
     */
    public static function paygateMissing(): array
    {
        $missing = [];
        if ((string) config('services.paygate.merchant_id') === '') {
            $missing[] = 'PAYGATE_MERCHANT_ID';
        }
        if ((string) config('services.paygate.username') === '') {
            $missing[] = 'PAYGATE_USERNAME';
        }
        if ((string) config('services.paygate.password') === '') {
            $missing[] = 'PAYGATE_PASSWORD';
        }
        if ((string) config('services.paygate.secret') === '') {
            $missing[] = 'PAYGATE_SECRET_KEY';
        }

        return $missing;
    }

    public static function configured(string $gateway): bool
    {
        return match ($gateway) {
            self::WEMA => self::wemaConfigured(),
            self::PAYGATE => self::paygateConfigured(),
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
            $missing = implode(', ', self::wemaMissing());
            throw new InvalidArgumentException(
                'Wema Bank is not configured. Add '.$missing.' in the server environment first.',
            );
        }

        if ($gateway === self::PAYGATE && ! self::paygateConfigured()) {
            $missing = implode(', ', self::paygateMissing());
            throw new InvalidArgumentException(
                'PayGate is not configured. Add '.$missing.' in the server environment first.',
            );
        }

        Setting::setValue(self::ACTIVE, $gateway);

        return self::all();
    }
}
