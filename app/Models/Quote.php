<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'tax_rate' => 'float',
            'issue_date' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        // a deleted client keeps its history, so records that point at it must still load it
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort')->orderBy('id');
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Draft #'.$this->id;
    }

    public function isOpenForResponse(): bool
    {
        return $this->status === QuoteStatus::Sent && ! $this->isPastValidity();
    }

    public function isPastValidity(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast() && ! $this->valid_until->isToday();
    }
}
