<?php

namespace App\Support;

use App\Models\Ticket;
use App\Plugins\Extensions;
use App\Services\Knowledgebase\Knowledgebase;
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

    /**
     * The client area's top menu, laid out the way hosting customers expect (Home, Services, Billing, Support) with the account
     * pages in the user menu. Plugin links join as their own dropdowns.
     *
     * @return array<int, array{label: string, url: ?string, active: bool, children: array<int, array{label: string, url: string, icon: string}>}>
     */
    public static function portal(): array
    {
        $link = fn (string $label, string $route, string $icon) => Route::has($route) ? ['label' => $label, 'url' => route($route), 'icon' => $icon] : null;
        $menu = [
            ['label' => 'Home', 'url' => route('portal.dashboard'), 'active' => request()->is('portal'), 'children' => []],
            ['label' => 'Services', 'url' => null, 'active' => request()->is('portal/services*', 'portal/store*', 'portal/order*', 'store', 'store/*'), 'children' => [
                $link('My services', 'portal.services.index', 'boxes'),
                $link('Order new services', 'portal.store', 'shopping-cart'),
            ]],
            ['label' => 'Billing', 'url' => null, 'active' => request()->is('portal/invoices*', 'portal/quotes*', 'portal/payment-methods*'), 'children' => [
                $link('My invoices', 'portal.invoices.index', 'receipt'),
                $link('My quotes', 'portal.quotes.index', 'file-text'),
                $link('Payment methods', 'portal.payment-methods.index', 'credit-card'),
            ]],
            ['label' => 'Support', 'url' => null, 'active' => request()->is('portal/tickets*', 'kb*'), 'children' => array_merge(
                TicketService::enabled() ? [$link('My tickets', 'portal.tickets.index', 'life-buoy'), $link('Open a ticket', 'portal.tickets.create', 'plus')] : [],
                Knowledgebase::enabled() ? [$link('Knowledge base', 'kb.index', 'book-open')] : [],
            )],
        ];
        foreach (app(Extensions::class)->menu('portal', auth()->user()) as $group => $items) {
            $menu[] = ['label' => $group, 'url' => null, 'active' => collect($items)->contains('active', true), 'children' => collect($items)->map(fn ($i) => ['label' => $i['label'], 'url' => $i['url'], 'icon' => $i['icon'] ?? 'puzzle'])->all()];
        }
        foreach ($menu as &$m) {
            $m['children'] = array_values(array_filter($m['children']));
        }
        unset($m);

        return array_values(array_filter($menu, fn ($m) => $m['url'] || $m['children']));
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
                'Knowledge base' => Knowledgebase::enabled() ? [['Articles', 'admin.kb.index', 'book-open', 'admin/kb*']] : [],
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
            'Support' => array_merge(TicketService::enabled() ? [['Tickets', 'portal.tickets.index', 'life-buoy', 'portal/tickets*']] : [], Knowledgebase::enabled() ? [['Help center', 'kb.index', 'book-open', 'kb*']] : []),
            'Settings' => [
                ['Profile', 'portal.profile', 'user', 'portal/profile*'],
                ['Payment methods', 'portal.payment-methods.index', 'credit-card', 'portal/payment-methods*'],
                ['Security', 'portal.security.index', 'shield-check', 'portal/security*'],
                ['API access', 'portal.api-tokens', 'key', 'portal/api*'],
            ],
        ];
    }
}
