<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'string', 'recorded_at' => 'datetime', 'invoiced_at' => 'datetime', 'metadata' => 'array'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UsageMeter::class, 'meter_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function isInvoiced(): bool
    {
        return $this->invoiced_at !== null;
    }
}
