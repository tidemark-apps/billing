<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-currency price of a meter. unit_price and tier prices are micro-minor units (1/1,000,000 of the currency's minor unit). */
class UsageMeterPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'integer', 'tiers' => 'array'];
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UsageMeter::class, 'usage_meter_id');
    }
}
