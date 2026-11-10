<?php

namespace App\Support;

use App\Enums\Role;
use App\Exceptions\PlanLimitReached;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;

/**
 * The plan of a hosted install: a JSON file written by the host (BILLING_PLAN_FILE), for example
 * {"name": "starter", "limits": {"clients": 100, "staff": 2}}. A missing file, or a limit that is absent or null, means no limit,
 * so self-hosted installs are never affected. Limits stop new clients and staff users; existing ones keep working
 * (for example after a downgrade), and the host can see the overage in `php artisan billing:usage`.
 */
class Plan
{
    public const LIMITS = ['clients', 'staff'];

    /** @return array{name: ?string, limits: array<string, ?int>} */
    public static function current(): array
    {
        $file = config('billing.plan_file');
        $data = $file && is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $data = is_array($data) ? $data : [];
        $limits = [];
        foreach (self::LIMITS as $key) {
            $v = $data['limits'][$key] ?? null;
            $limits[$key] = is_int($v) && $v >= 0 ? $v : null;
        }

        return ['name' => is_string($data['name'] ?? null) ? $data['name'] : null, 'limits' => $limits];
    }

    public static function limit(string $key): ?int
    {
        return self::current()['limits'][$key] ?? null;
    }

    public static function usage(string $key): int
    {
        return match ($key) {
            'clients' => Client::query()->count(),
            'staff' => User::query()->whereIn('role', [Role::Admin, Role::Staff])->where('is_active', true)->count(),
        };
    }

    /** @throws PlanLimitReached */
    public static function ensureCanAdd(string $key): void
    {
        $limit = self::limit($key);
        if ($limit !== null && self::usage($key) >= $limit) {
            $label = $key === 'clients' ? 'clients' : 'staff users';
            throw new PlanLimitReached("Your plan allows $limit $label and you have reached that limit. Upgrade your plan to add more.");
        }
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        $plan = self::current();
        $usage = ['clients' => self::usage('clients'), 'staff' => self::usage('staff')];

        return [
            'plan' => $plan['name'],
            'limits' => $plan['limits'],
            'usage' => $usage + ['invoices_last_30_days' => Invoice::query()->where('created_at', '>=', now()->subDays(30))->count()],
            'over_limit' => array_values(array_filter(self::LIMITS, fn ($k) => $plan['limits'][$k] !== null && $usage[$k] > $plan['limits'][$k])),
            'version' => config('billing.version'),
        ];
    }
}
