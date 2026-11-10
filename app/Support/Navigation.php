<?php

namespace App\Support;

use App\Models\Ticket;
use App\Plugins\Extensions;
use App\Services\TicketService;
use Illuminate\Support\Facades\Route;

/** Builds the sidebar for the admin and customer areas: core links first, then whatever plugins added. */
class Navigation
{
    /** @return array<string, array<int, array{label: string, url: string, icon: string, active: bool}>> */
    public static function for(string $area): array
    {
        $groups = [];
        foreach (self::core($area) as $group => $items) {
            foreach ($items as $item) {
                [$label, $route, $icon, $match] = $item;
                $only = $item[4] ?? null;
                if (! Route::has($route) || ($only === 'admin' && ! auth()->user()?->isAdmin())) {
                    continue;
                }
                $url = route($route);
                $groups[$group][] = ['label' => $label, 'url' => $url, 'icon' => $icon, 'active' => request()->is($match)] + (isset($item[5]) ? ['badge' => $item[5]] : []);
            }
        }
        foreach (app(Extensions::class)->menu($area, auth()->user()) as $group => $items) {
            $groups[$group] = array_merge($groups[$group] ?? [], $items);
        }

        return array_filter($groups);
    }

    /** The Support group: only while tickets are switched on. Tickets carries the count waiting for staff. @return array<int, array<int, mixed>> */
    private static function support(): array
    {
        if (! TicketService::enabled()) {
            return [];
        }
        $user = auth()->user();
        $waiting = $user ? Ticket::visibleTo($user)->whereIn('status', ['open', 'customer_reply'])->count() : 0;

        return [
            ['Tickets', 'admin.tickets.index', 'life-buoy', 'admin/tickets*', null, $waiting ?: null],
            ['Departments', 'admin.departments.index', 'layers', 'admin/departments*', 'admin'],
            ['Ticket fields', 'admin.ticket-fields.index', 'list-checks', 'admin/ticket-fields*', 'admin'],
        ];
    }

    /** @return array<string, array<int, array{0: string, 1: string, 2: string, 3: string, 4?: ?string, 5?: ?int}>> label, route name, icon, active path pattern, optional 'admin' (administrators only) */
    private static function core(string $area): array
    {
        if ($area === 'admin') {
            return [
                'Overview' => [['Dashboard', 'admin.dashboard', 'layout-dashboard', 'admin']],
                'Billing' => [
                    ['Invoices', 'admin.invoices.index', 'receipt', 'admin/invoices*'],
                    ['Quotes', 'admin.quotes.index', 'file-text', 'admin/quotes*'],
                    ['Payments', 'admin.payments.index', 'wallet', 'admin/payments*'],
                ],
                'Customers' => [
                    ['Clients', 'admin.clients.index', 'users', 'admin/clients*'],
                    ['Services', 'admin.services.index', 'boxes', 'admin/services*'],
                ],
                'Catalog' => [
                    ['Products', 'admin.products.index', 'package', 'admin/products*'],
                    ['Servers', 'admin.servers.index', 'server', 'admin/servers*'],
                ],
                'Support' => self::support(),
                'System' => [
                    ['Staff', 'admin.staff.index', 'id-card', 'admin/staff*', 'admin'],
                    ['Plugins', 'admin.plugins.index', 'puzzle', 'admin/plugins*'],
                    ['API & webhooks', 'admin.api.index', 'webhook', 'admin/api*'],
                    ['Settings', 'admin.settings.index', 'settings', 'admin/settings*'],
                ],
            ];
        }

        return [
            'Account' => [
                ['Overview', 'portal.dashboard', 'layout-dashboard', 'portal'],
                ['Services', 'portal.services.index', 'boxes', 'portal/services*'],
                ['Invoices', 'portal.invoices.index', 'receipt', 'portal/invoices*'],
                ['Quotes', 'portal.quotes.index', 'file-text', 'portal/quotes*'],
            ],
            'Shop' => [['Order services', 'portal.store', 'shopping-cart', 'portal/store*']],
            'Support' => TicketService::enabled() ? [['Tickets', 'portal.tickets.index', 'life-buoy', 'portal/tickets*']] : [],
            'Settings' => [
                ['Profile', 'portal.profile', 'user', 'portal/profile*'],
                ['Payment methods', 'portal.payment-methods.index', 'credit-card', 'portal/payment-methods*'],
                ['Security', 'portal.security.index', 'shield-check', 'portal/security*'],
                ['API access', 'portal.api-tokens', 'key', 'portal/api*'],
            ],
        ];
    }
}
