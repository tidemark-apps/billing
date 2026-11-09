<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginRecord extends Model
{
    protected $table = 'plugins';

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'installed_at' => 'datetime'];
    }
}
