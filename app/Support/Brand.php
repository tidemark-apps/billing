<?php

namespace App\Support;

/**
 * The business's own brand colour (Settings → General), turned into the colour tokens the stylesheet uses.
 *
 * Whatever is picked, the result stays readable: for each theme the colour is nudged, by the smallest change in lightness
 * (hue and saturation are kept), until buttons are at least 3:1 against the page, text in the brand colour is at least 4.5:1
 * on the page and on its own soft background, and the text on a filled button is at least 4.5:1.
 */
class Brand
{
    public const DEFAULT = '#0e7c86';

    /** Page and card backgrounds of the two themes (see :root in app.css). */
    private const SURFACES = ['light' => ['#fafafa', '#ffffff'], 'dark' => ['#000000', '#0a0a0a']];

    /** @var array<string, array<string, mixed>> */
    private static array $memo = [];

    /** "#abc", "ABCDEF" or "#AABBCC" become "#aabbcc"; anything else is null. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $m)) {
            return null;
        }
        $h = strtolower($m[1]);
        if (strlen($h) === 3) {
            $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
        }

        return '#'.$h;
    }

    /** The colour the business chose, or null while they have not set (or have cleared) one. */
    public static function chosen(): ?string
    {
        return self::normalize(Settings::get('company.brand_color'));
    }

    /**
     * @return array{light: array<string, string>, dark: array<string, string>, adjusted: array{light: bool, dark: bool}}
     */
    public static function palette(string $hex): array
    {
        $hex = self::normalize($hex) ?? self::DEFAULT;

        return self::$memo[$hex] ??= (function () use ($hex) {
            $light = self::theme($hex, 'light');
            $dark = self::theme($hex, 'dark');

            return ['light' => $light['tokens'], 'dark' => $dark['tokens'], 'adjusted' => ['light' => $light['adjusted'], 'dark' => $dark['adjusted']]];
        })();
    }

    /** A <style> body that overrides the stylesheet's brand tokens, or '' to keep the default teal. */
    public static function css(): string
    {
        $hex = self::chosen();
        if ($hex === null || $hex === self::DEFAULT) {
            return '';
        }
        $p = self::palette($hex);
        $decl = fn (array $t) => '--brand:'.$t['brand'].';--brand-foreground:'.$t['foreground'].';--brand-soft:'.$t['soft'].';--brand-ink:'.$t['ink'].';--brand-hover:'.$t['hover'].';--ring:'.$t['ink'].';--chart-1:'.$t['ink'].';';

        return ':root{'.$decl($p['light']).'}'
            .':root[data-theme="dark"]{'.$decl($p['dark']).'}'
            .'@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){'.$decl($p['dark']).'}}';
    }

    /** The light-theme tokens for places that cannot use the stylesheet (emails, PDFs). */
    public static function light(): array
    {
        return self::palette(self::chosen() ?? self::DEFAULT)['light'];
    }

    // ---- the maths -----------------------------------------------------------------------------------------------

    /** @return array{tokens: array<string, string>, adjusted: bool} */
    private static function theme(string $hex, string $theme): array
    {
        [$page, $card] = self::SURFACES[$theme];
        $towards = $theme === 'light' ? -1 : 1; // the direction that adds contrast against the page

        // the fill: a button needs to stand out from the page (3:1)
        $fill = self::nearest($hex, fn ($c) => self::contrast($c, $page) >= 3.0 && self::contrast($c, $card) >= 3.0, $towards);
        $foreground = self::contrast($fill, '#ffffff') >= self::contrast($fill, '#0a0a0a') ? '#ffffff' : '#0a0a0a';
        // text in the brand colour: 4.5:1 on the page, the card and its own soft tint
        $ink = self::nearest($hex, fn ($c) => self::contrast($c, $page) >= 4.5 && self::contrast($c, $card) >= 4.5 && self::contrast($c, self::mix($c, $card, $theme === 'light' ? 0.10 : 0.18)) >= 4.5, $towards);
        $soft = self::mix($ink, $card, $theme === 'light' ? 0.10 : 0.18);
        // hover: one step further from the page, as long as the button text still reads
        $hover = self::shift($fill, 0.045 * $towards);
        if (self::contrast($hover, $foreground) < 4.5) {
            $hover = $fill;
        }

        return ['tokens' => ['brand' => $fill, 'foreground' => $foreground, 'soft' => $soft, 'ink' => $ink, 'hover' => $hover], 'adjusted' => $fill !== $hex || $ink !== $hex];
    }

    /** The colour closest in lightness to $hex that passes $ok, looking both ways but preferring $prefer (+1 lighter, -1 darker). */
    private static function nearest(string $hex, callable $ok, int $prefer): string
    {
        if ($ok($hex)) {
            return $hex;
        }
        [$h, $s, $l] = self::hsl($hex);
        for ($step = 1; $step <= 100; $step++) {
            foreach ([$prefer, -$prefer] as $dir) {
                $candidate = self::fromHsl($h, $s, $l + $dir * $step / 100);
                if ($candidate !== null && $ok($candidate)) {
                    return $candidate;
                }
            }
        }

        return $prefer > 0 ? '#ffffff' : '#000000';
    }

    private static function shift(string $hex, float $by): string
    {
        [$h, $s, $l] = self::hsl($hex);

        return self::fromHsl($h, $s, $l + $by) ?? $hex;
    }

    private static function mix(string $a, string $b, float $amount): string
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);
        $m = fn ($x, $y) => (int) round($x * $amount + $y * (1 - $amount));

        return sprintf('#%02x%02x%02x', $m($ar, $br), $m($ag, $bg), $m($ab, $bb));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public static function luminance(string $hex): float
    {
        $c = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /** WCAG contrast ratio, 1 to 21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @return array{0: float, 1: float, 2: float} hue 0-360, saturation and lightness 0-1 */
    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match (true) {
            $max === $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
            $max === $g => (($b - $r) / $d) + 2,
            default => (($r - $g) / $d) + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): ?string
    {
        if ($l < 0 || $l > 1) {
            return null;
        }
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0],
            $h < 120 => [$x, $c, 0],
            $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c],
            $h < 300 => [$x, 0, $c],
            default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
