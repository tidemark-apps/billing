<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class Activity
{
    /** @param  array<string, mixed>  $properties */
    public static function log(string $action, ?Model $subject = null, ?string $description = null, array $properties = []): void
    {
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'action' => $action,
                'description' => $description,
                'properties' => $properties ?: null,
                'ip' => request()?->ip(),
            ]);
        } catch (\Throwable) {
            // an audit-log failure must never break the action being logged
        }
    }
}
