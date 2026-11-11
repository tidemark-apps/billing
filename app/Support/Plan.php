<?php

namespace App\Support;

use App\Enums\Role;
use App\Exceptions\PlanLimitReached;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The plan of a hosted install: a JSON file written by the host (BILLING_PLAN_FILE), for example
 * {"name": "starter", "limits": {"clients": 100, "staff": 2, "emails_per_day": 200}, "suspended": false}. A missing file, or a limit that is absent or null, means no limit,
 * so self-hosted installs are never affected. Limits stop new clients and staff users, and outgoing email once a day's allowance (`emails_per_day`, UTC) is used up; existing ones keep working
 * (for example after a downgrade), and the host can see the overage in `php artisan billing:usage`.
 */
class Plan
{
    public const LIMITS = ['clients', 'staff', 'emails_per_day'];

    /** @return array{name: ?string, limits: array<string, ?int>, whitelabel: bool, suspended: bool, suspended_message: ?string} */
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

        return [
            'name' => is_string($data['name'] ?? null) ? $data['name'] : null,
            'limits' => $limits,
            'whitelabel' => ($data['whitelabel'] ?? false) === true,
            'suspended' => ($data['suspended'] ?? false) === true,
            'suspended_message' => is_string($data['suspended_message'] ?? null) && $data['suspended_message'] !== '' ? $data['suspended_message'] : null,
        ];
    }

    /**
     * White label: the host has switched off Tidemark branding for this install ({"whitelabel": true} in the plan file), so the
     * "Powered by" footers, the Tidemark logo and the Tidemark favicon are not shown. Always false when there is no plan file.
     */
    public static function whitelabel(): bool
    {
        return self::current()['whitelabel'];
    }

    /** The host has suspended this install (for example an unpaid subscription): everything is shown with a banner. */
    public static function suspended(): bool
    {
        return self::current()['suspended'];
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
            'emails_per_day' => (int) Cache::get(self::emailKey(), 0),
        };
    }

    private static function emailKey(): string
    {
        return 'plan-emails:'.now()->utc()->format('Y-m-d');
    }

    /** Count one outgoing email against today's allowance (UTC day). False when the plan's daily limit is used up. */
    public static function allowEmail(): bool
    {
        $limit = self::limit('emails_per_day');
        if ($limit !== null && self::usage('emails_per_day') >= $limit) {
            return false;
        }
        Cache::add(self::emailKey(), 0, now()->addDays(2));
        Cache::increment(self::emailKey());

        return true;
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
        $usage = ['clients' => self::usage('clients'), 'staff' => self::usage('staff'), 'emails_per_day' => self::usage('emails_per_day')];

        return [
            'plan' => $plan['name'],
            'suspended' => $plan['suspended'],
            'whitelabel' => $plan['whitelabel'],
            'limits' => $plan['limits'],
            'usage' => $usage + ['invoices_last_30_days' => Invoice::query()->where('created_at', '>=', now()->subDays(30))->count()],
            'over_limit' => array_values(array_filter(self::LIMITS, fn ($k) => $plan['limits'][$k] !== null && $usage[$k] > $plan['limits'][$k])),
            'version' => config('billing.version'),
        ];
    }
}
