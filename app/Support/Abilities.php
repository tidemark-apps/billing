<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * The single list of API token abilities. Route middleware (`ability:invoices.read`), token creation (the API and the
 * admin screen) and the docs all read from here, so adding an ability means adding one line.
 *
 * Convention: `<resource>.read` for GET, `<resource>.write` for changes. A token without an ability list (null) can do
 * everything its owner can; a token with a list can only do what is listed.
 */
final class Abilities
{
    /** Abilities of customer tokens (client API) => description. */
    public const CUSTOMER = [
        'profile.read' => 'Read your profile and client details',
        'profile.write' => 'Update your profile and client details',
        'orders.write' => 'Place orders',
        'services.read' => 'List and read your services',
        'services.write' => 'Cancel services and run service actions',
        'invoices.read' => 'List and read invoices, payments and gateways; download invoice PDFs',
        'invoices.pay' => 'Pay an invoice (gateway checkout or a saved payment method)',
        'quotes.read' => 'List and read quotes; download quote PDFs',
        'quotes.write' => 'Accept or decline quotes',
        'payment-methods.read' => 'List your saved payment methods (tokens are never returned)',
        'payment-methods.write' => 'Add, remove and change saved payment methods and automatic payment',
        'usage.read' => 'Read the metered usage of your services',
    ];

    /** Abilities of staff tokens (/admin API) => description. */
    public const STAFF = [
        'dashboard.read' => 'Read dashboard statistics',
        'clients.read' => 'Read clients, their portal logins and tax rates',
        'clients.write' => 'Create, update and delete clients; manage portal logins',
        'products.read' => 'Read products, product groups and provisioning modules',
        'products.write' => 'Create, update, order and delete products and product groups',
        'services.read' => 'Read services and their logs',
        'services.write' => 'Create services, run lifecycle actions, reveal stored secrets',
        'invoices.read' => 'Read invoices and download invoice PDFs',
        'invoices.write' => 'Create, edit, issue, void, resend invoices and record manual payments',
        'quotes.read' => 'Read quotes and download quote PDFs',
        'quotes.write' => 'Create, edit, send, convert and delete quotes',
        'payments.read' => 'Read payments and refunds',
        'payments.write' => 'Refund payments',
        'tax-rates.read' => 'Read tax rates',
        'tax-rates.write' => 'Create, update and delete tax rates',
        'servers.read' => 'Read provisioning servers (secrets are never returned)',
        'servers.write' => 'Create, update, test and delete provisioning servers',
        'settings.read' => 'Read settings (secrets are masked)',
        'settings.write' => 'Change settings, send a test email, run billing tasks',
        'plugins.read' => 'Read plugins and their settings (secrets are masked)',
        'plugins.write' => 'Enable, disable and configure plugins',
        'webhooks.read' => 'Read webhook endpoints, deliveries and the event catalogue',
        'webhooks.write' => 'Create, update, test and delete webhook endpoints',
        'api-tokens.read' => 'List API tokens',
        'api-tokens.write' => 'Create and revoke API tokens',
        'payment-methods.read' => 'Read the saved payment methods of clients (tokens are never returned)',
        'payment-methods.write' => 'Remove a client\'s saved payment methods',
        'autopay.read' => 'Read automatic payment attempts',
        'usage.read' => 'Read product meters, service usage and usage records',
        'usage.write' => 'Create, change and delete product meters; record and delete usage',
        'activity.read' => 'Read the activity log',
        'staff-users.read' => 'Read staff accounts (administrators only)',
        'staff-users.write' => 'Create and edit staff accounts, activate/deactivate them, send password links, reset their two-factor authentication (administrators only)',
        'impersonation.write' => 'Issue short-lived tokens that act as a customer',
    ];

    /** @return array<int, string> */
    public static function customer(): array
    {
        return array_keys(self::CUSTOMER);
    }

    /** @return array<int, string> */
    public static function staff(): array
    {
        return array_keys(self::STAFF);
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(self::customer(), self::staff())));
    }

    /** Abilities a token owned by this user may carry. @return array<int, string> */
    public static function forUser(User $user): array
    {
        return $user->isStaff() ? self::staff() : self::customer();
    }

    public static function isValid(string $ability, ?string $audience = null): bool
    {
        return in_array($ability, match ($audience) {
            'customer' => self::customer(),
            'staff' => self::staff(),
            default => self::all(),
        }, true);
    }

    /** Validation rule for one ability. */
    public static function rule(?string $audience = null): In
    {
        return Rule::in(match ($audience) {
            'customer' => self::customer(),
            'staff' => self::staff(),
            default => self::all(),
        });
    }

    /** @return array{customer: array<string, string>, staff: array<string, string>} */
    public static function catalog(): array
    {
        return ['customer' => self::CUSTOMER, 'staff' => self::STAFF];
    }
}
