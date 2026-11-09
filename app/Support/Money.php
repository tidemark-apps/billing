<?php

namespace App\Support;

/**
 * Amounts are stored as integers in the currency's minor unit (cents). This class converts and formats them.
 */
class Money
{
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function digits(string $currency): int
    {
        $currency = strtoupper($currency);

        return in_array($currency, self::ZERO_DECIMAL, true) ? 0 : (in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2);
    }

    /** Convert a user-entered decimal ("12.50", "1,200.5") into minor units. */
    public static function toMinor(string|int|float|null $value, string $currency): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $clean = is_string($value) ? preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $value)) : (string) $value;

        return (int) round(((float) $clean) * (10 ** self::digits($currency)));
    }

    /** Minor units to a plain decimal string without symbol ("12.50"). */
    public static function toDecimal(int $minor, string $currency): string
    {
        return number_format($minor / (10 ** self::digits($currency)), self::digits($currency), '.', '');
    }

    public static function format(int $minor, ?string $currency = null, ?string $locale = null): string
    {
        $currency = strtoupper($currency ?: config('billing.currency'));
        $value = $minor / (10 ** self::digits($currency));

        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter($locale ?: app()->getLocale(), \NumberFormatter::CURRENCY);
            $formatted = $f->formatCurrency($value, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return $currency.' '.number_format($value, self::digits($currency));
    }

    /** @return array<string, string> the currencies offered in settings: code => label */
    public static function currencies(): array
    {
        return [
            'USD' => 'US dollar', 'EUR' => 'Euro', 'GBP' => 'British pound', 'CAD' => 'Canadian dollar', 'AUD' => 'Australian dollar',
            'NZD' => 'New Zealand dollar', 'CHF' => 'Swiss franc', 'SEK' => 'Swedish krona', 'NOK' => 'Norwegian krone', 'DKK' => 'Danish krone',
            'PLN' => 'Polish złoty', 'CZK' => 'Czech koruna', 'JPY' => 'Japanese yen', 'CNY' => 'Chinese yuan', 'INR' => 'Indian rupee',
            'BRL' => 'Brazilian real', 'MXN' => 'Mexican peso', 'ZAR' => 'South African rand', 'SGD' => 'Singapore dollar', 'HKD' => 'Hong Kong dollar',
            'KRW' => 'South Korean won', 'TRY' => 'Turkish lira', 'AED' => 'UAE dirham', 'ILS' => 'Israeli shekel',
        ];
    }
}
