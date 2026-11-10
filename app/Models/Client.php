<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\Countries;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'is_internal' => 'boolean'];
    }

    public function scopeExternal($query)
    {
        return $query->where('is_internal', false);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function displayName(): string
    {
        return $this->company_name ?: $this->name;
    }

    /** "Portland, OR 97201": city, then region and postcode. */
    public function cityLine(): string
    {
        $region = trim(implode(' ', array_filter([$this->state, $this->postcode])));

        return implode(', ', array_filter([$this->city, $region]));
    }

    /** @return array<int, string> */
    public function addressLines(): array
    {
        return array_values(array_filter([
            $this->company_name ? $this->name : null,
            $this->address_1,
            $this->address_2,
            $this->cityLine(),
            $this->country ? (Countries::name($this->country)) : null,
        ]));
    }
}
