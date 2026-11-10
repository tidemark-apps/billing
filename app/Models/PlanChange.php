<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One upgrade, downgrade or other change of a service's plan, applied or scheduled for its next renewal. */
class PlanChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_cycle' => BillingCycle::class,
            'to_cycle' => BillingCycle::class,
            'from_amount' => 'integer',
            'to_amount' => 'integer',
            'credit' => 'integer',
            'charge' => 'integer',
            'effective_date' => 'date',
            'applied_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id')->withTrashed();
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id')->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
