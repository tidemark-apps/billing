<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A browser that skips the two-factor prompt at sign-in ("Remember this device"). Only a hash of its cookie token is stored. */
class TwoFactorDevice extends Model
{
    use Prunable;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'binding'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }
}
