<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\ExcludesInternalClients;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use ExcludesInternalClients, HasUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'tax_rate' => 'float',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        // a deleted client keeps its history, so records that point at it must still load it
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    public function isPayable(): bool
    {
        return $this->status === InvoiceStatus::Open && $this->balance() > 0;
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Open && $this->due_date !== null && $this->due_date->isPast() && ! $this->due_date->isToday();
    }

    public function displayNumber(): string
    {
        return $this->number ?? 'Draft #'.$this->id;
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', InvoiceStatus::Open->value)->whereDate('due_date', '<', now()->toDateString());
    }
}
