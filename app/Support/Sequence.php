<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Gap-free, concurrency-safe counters for invoice and quote numbers. */
class Sequence
{
    public static function next(string $name): int
    {
        return DB::transaction(function () use ($name) {
            DB::table('sequences')->insertOrIgnore(['name' => $name, 'value' => 0]);
            DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
            DB::table('sequences')->where('name', $name)->increment('value');

            return (int) DB::table('sequences')->where('name', $name)->value('value');
        });
    }

    public static function format(string $prefix, int $number, int $pad = 5): string
    {
        return $prefix.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }
}
