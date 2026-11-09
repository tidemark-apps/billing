<?php

namespace Plugins\LicenseServer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activation extends Model
{
    protected $table = 'license_activations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'meta' => 'array'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
