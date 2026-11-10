<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'fee' => 'integer',
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        // a deleted client keeps its history, so records that point at it must still load it
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    /** Total already refunded against this payment. */
    public function refundedAmount(): int
    {
        return (int) static::where('refund_of_id', $this->id)->where('status', PaymentStatus::Completed->value)->sum('amount');
    }
}
