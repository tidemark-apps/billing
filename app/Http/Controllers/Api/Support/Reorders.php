<?php

namespace App\Http\Controllers\Api\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Display order for models with a `sort` column: the given ids come first, in that order; everything else keeps its relative order after them. */
trait Reorders
{
    /** @param  class-string<Model>  $model @param  array<int, int>  $ids */
    protected function applyOrder(string $model, array $ids): void
    {
        DB::transaction(function () use ($model, $ids) {
            $position = 0;
            foreach (array_values($ids) as $id) {
                $model::whereKey($id)->update(['sort' => $position++]);
            }
            foreach ($model::whereNotIn('id', $ids)->orderBy('sort')->orderBy('name')->pluck('id') as $id) {
                $model::whereKey($id)->update(['sort' => $position++]);
            }
        });
    }
}
