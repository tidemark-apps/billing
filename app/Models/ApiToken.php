<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['abilities' => 'array', 'last_used_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a token. The plain-text value is only ever available from this return value.
     *
     * @param  array<int, string>|null  $abilities  null means every ability the owner has
     * @return array{0: ApiToken, 1: string}
     */
    public static function issue(User $user, string $name, ?array $abilities = null, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = 'tmb_'.Str::random(40);
        $token = static::create([
            'user_id' => $user->id,
            'name' => $name,
            'prefix' => substr($plain, 0, 8),
            'token_hash' => hash('sha256', $plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plain];
    }

    public static function findPlain(string $plain): ?static
    {
        return static::with('user')->where('token_hash', hash('sha256', $plain))->first();
    }

    public function can(string $ability): bool
    {
        return $this->abilities === null || in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
