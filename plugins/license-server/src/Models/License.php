<?php

namespace Plugins\LicenseServer\Models;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $table = 'licenses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'suspended_at' => 'datetime', 'meta' => 'array', 'max_activations' => 'integer', 'grace_days' => 'integer'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function activations(): HasMany
    {
        return $this->hasMany(Activation::class)->orderBy('id');
    }

    /** The status customers' software sees: active, grace (suspended but within grace days), suspended, revoked or expired. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->status === 'suspended') {
            $until = $this->suspended_at?->copy()->addDays($this->grace_days);

            return $this->grace_days > 0 && $until && $until->isFuture() ? 'grace' : 'suspended';
        }

        return $this->status;
    }

    public function isUsable(): bool
    {
        return in_array($this->effectiveStatus(), ['active', 'grace'], true);
    }
}
