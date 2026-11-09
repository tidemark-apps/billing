<?php

use App\Plugins\Extensions;
use App\Support\Money;
use Illuminate\Support\HtmlString;

if (! function_exists('extensions')) {
    /** Render everything plugins added to a UI slot. Use as {!! extensions('portal.service', ['service' => $service]) !!}. */
    function extensions(string $slot, array $data = []): HtmlString
    {
        return app(Extensions::class)->render($slot, $data);
    }
}

if (! function_exists('money')) {
    function money(int|float|null $minor, ?string $currency = null): string
    {
        return Money::format((int) ($minor ?? 0), $currency);
    }
}
