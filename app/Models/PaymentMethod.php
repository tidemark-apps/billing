<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A tokenised payment method kept at a gateway. Holds the provider's token and display details only; never a card number. */
class PaymentMethod extends Model
{
    use HasUuid;

    protected $guarded = ['id'];

    protected $hidden = ['reference', 'customer_reference', 'data'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'autopay' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'data' => 'encrypted:array',
            'removed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Methods that have not been removed. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isExpired(): bool
    {
        if (! $this->exp_year || ! $this->exp_month) {
            return false;
        }

        return now()->greaterThan(Carbon::create($this->exp_year, $this->exp_month, 1)->endOfMonth());
    }

    /** "Visa ending 4242", "PayPal (me@example.com)" */
    public function displayName(): string
    {
        $brand = $this->brand ? ucfirst($this->brand) : ucfirst($this->gateway);
        if ($this->last4) {
            return $brand.' ending '.$this->last4;
        }

        return $this->label ? $brand.' ('.$this->label.')' : $brand;
    }

    public function expiry(): ?string
    {
        return $this->exp_month && $this->exp_year ? sprintf('%02d/%d', $this->exp_month, $this->exp_year) : null;
    }
}
