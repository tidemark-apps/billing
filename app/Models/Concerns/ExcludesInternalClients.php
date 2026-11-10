<?php

namespace App\Models\Concerns;

use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;

/** Adds ->external(): records that belong to real customers, leaving out internal accounts. Use it for every revenue figure and count in reports. */
trait ExcludesInternalClients
{
    public function scopeExternal(Builder $query): Builder
    {
        return $query->whereNotIn(
            $this->qualifyColumn('client_id'),
            Client::withTrashed()->where('is_internal', true)->select('id'),
        );
    }
}
