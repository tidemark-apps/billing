<?php

namespace App\Support;

/**
 * Exact decimal arithmetic for metered billing (BCMath, no floats anywhere in the money path).
 *
 * PRECISION: usage prices are stored as integers in MICRO-MINOR units, i.e. 1 = 1/1,000,000 of the currency's minor unit
 * (USD: 1 = 0.000001 cent = $0.00000001). A price of $0.002 per API call is 0.2 cent = 200,000 micro. Quantities carry
 * 6 decimal places. A line's cost is computed exactly in micro units and rounded ONCE, half up, to the minor unit
 * (the invoice's integer cents), so rounding error never accumulates across records.
 */
class UsageMath
{
    public const QTY_SCALE = 6;

    public const MICRO = '1000000';

    public static function assertAvailable(): void
    {
        if (! function_exists('bcadd')) {
            throw new \RuntimeException('Metered billing needs the PHP bcmath extension.');
        }
    }

    /** Normalise a number (int, float, numeric string) to a plain 6-decimal string, rejecting anything non-numeric or negative. */
    public static function norm(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.000000';
        }
        if (is_float($value)) {
            $value = sprintf('%.'.self::QTY_SCALE.'F', $value);
        }
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new \InvalidArgumentException('Quantity must be a plain decimal number.');
        }
        self::assertAvailable();

        return bcadd($value, '0', self::QTY_SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::QTY_SCALE);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::QTY_SCALE);
    }

    public static function isZero(string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b) <= 0 ? $a : $b;
    }

    /** Quantity after the free allowance (never negative). */
    public static function billable(string $quantity, string $included): string
    {
        $diff = bcsub($quantity, $included, self::QTY_SCALE);

        return self::cmp($diff, '0') > 0 ? $diff : '0.000000';
    }

    /** units × micro price, as an integer string of micro-minor units (truncated; the single rounding happens in toMinor). */
    public static function lineMicro(string $units, int|string $microPrice): string
    {
        return bcmul($units, (string) $microPrice, 0);
    }

    /** @param array<int, array<string, mixed>> $tiers graduated tiers, cumulative "up_to" (null = unbounded) @return string micro-minor units */
    public static function tieredMicro(string $billable, array $tiers): string
    {
        $total = '0';
        $prev = '0.000000';
        foreach (self::sortTiers($tiers) as $tier) {
            if (self::cmp($billable, $prev) <= 0) {
                break;
            }
            $upTo = ($tier['up_to'] ?? null) === null || $tier['up_to'] === '' ? null : self::norm($tier['up_to']);
            $top = $upTo === null ? $billable : self::min($billable, $upTo);
            $inTier = bcsub($top, $prev, self::QTY_SCALE);
            if (self::cmp($inTier, '0') > 0) {
                $total = bcadd($total, self::lineMicro($inTier, (int) ($tier['unit_price'] ?? 0)), 0);
                $total = bcadd($total, (string) (int) ($tier['flat_fee'] ?? 0), 0);
            }
            if ($upTo === null) {
                break;
            }
            $prev = $upTo;
        }

        return $total;
    }

    /** Package pricing: every started package of $size units costs $price. */
    public static function packageMicro(string $billable, string $size, int|string $price): string
    {
        if (self::isZero($billable) || self::cmp($size, '0') <= 0) {
            return '0';
        }
        $packages = bcdiv($billable, $size, 0);
        if (bccomp(bcmul($packages, $size, self::QTY_SCALE), $billable, self::QTY_SCALE) < 0) {
            $packages = bcadd($packages, '1', 0);
        }

        return bcmul($packages, (string) $price, 0);
    }

    /** Round micro-minor units to whole minor units, half up. */
    public static function toMinor(string $micro): int
    {
        return (int) bcdiv(bcadd($micro, '500000', 0), self::MICRO, 0);
    }

    /** @param array<int, array<string, mixed>> $tiers */
    public static function sortTiers(array $tiers): array
    {
        $tiers = array_values($tiers);
        usort($tiers, function ($a, $b) {
            $ua = ($a['up_to'] ?? null) === null || ($a['up_to'] ?? '') === '' ? null : self::norm($a['up_to']);
            $ub = ($b['up_to'] ?? null) === null || ($b['up_to'] ?? '') === '' ? null : self::norm($b['up_to']);

            return $ua === null ? ($ub === null ? 0 : 1) : ($ub === null ? -1 : self::cmp($ua, $ub));
        });

        return $tiers;
    }

    /** A major-unit decimal entered by staff ("0.002") to micro-minor units for a currency. */
    public static function majorToMicro(string|int|float|null $major, string $currency): int
    {
        if ($major === null || $major === '') {
            return 0;
        }
        self::assertAvailable();
        $clean = is_float($major) ? sprintf('%.12F', $major) : preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $major));
        if (! preg_match('/^-?\d+(\.\d+)?$/', (string) $clean)) {
            throw new \InvalidArgumentException('Price must be a plain decimal number.');
        }
        $scale = Money::digits($currency) + 6;
        $micro = bcmul((string) $clean, bcpow('10', (string) $scale, 0), 0); // truncates beyond micro-minor precision

        return (int) $micro;
    }

    /** Micro-minor units to a major-unit decimal without trailing zeros beyond $minDecimals ("0.002", "12.50"). */
    public static function microToMajor(int $micro, string $currency, ?int $minDecimals = null): string
    {
        self::assertAvailable();
        $scale = Money::digits($currency) + 6;
        $minDecimals ??= Money::digits($currency);
        $s = bcdiv((string) $micro, bcpow('10', (string) $scale, 0), $scale);
        if (str_contains($s, '.')) {
            [$int, $frac] = explode('.', $s);
            $frac = rtrim($frac, '0');
            $frac = str_pad($frac, $minDecimals, '0');
            $s = $frac === '' ? $int : $int.'.'.$frac;
        }

        return $s;
    }

    /** "1000.500000" -> "1000.5", "0.000000" -> "0" (for form fields). */
    public static function plain(string $quantity): string
    {
        return str_contains($quantity, '.') ? rtrim(rtrim($quantity, '0'), '.') ?: '0' : $quantity;
    }

    /** Display a quantity without needless trailing zeros ("1,234.5"). */
    public static function display(string $quantity): string
    {
        [$int, $frac] = array_pad(explode('.', $quantity), 2, '');
        $frac = rtrim($frac, '0');

        return number_format((float) $int, 0, '.', ',').($frac !== '' ? '.'.$frac : '');
    }
}
