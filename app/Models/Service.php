<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'billing_cycle' => BillingCycle::class,
            'amount' => 'integer',
            'next_due_date' => 'date',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'terminated_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'provision' => 'array',
            'secrets' => 'encrypted:array',
            'config' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ServiceLog::class)->latest('id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function module(): ?string
    {
        return $this->product?->module;
    }

    public function title(): string
    {
        return trim(($this->product?->name ?? 'Service').($this->label ? ' — '.$this->label : ''));
    }

    /** Read a value the provisioning module stored (username, license key, ip...). */
    public function data(string $key, mixed $default = null): mixed
    {
        return data_get($this->provision, $key, $default);
    }

    /** Merge values into the module data. */
    public function remember(array $values): static
    {
        $this->provision = array_merge($this->provision ?? [], $values);

        return $this;
    }

    public function secret(string $key, mixed $default = null): mixed
    {
        return data_get($this->secrets, $key, $default);
    }

    public function rememberSecret(array $values): static
    {
        $this->secrets = array_merge($this->secrets ?? [], $values);

        return $this;
    }
}
