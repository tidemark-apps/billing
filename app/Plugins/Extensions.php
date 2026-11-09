<?php

namespace App\Plugins;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * The UI extension registry. Core pages render named "slots" and plugins add items to them.
 *
 * Slots used by the core:
 *   admin.menu / portal.menu           sidebar links  {label, route|url, icon?, group?, match?, sort?, when?}
 *   admin.dashboard / portal.dashboard widgets       {view, data?, when?}
 *   admin.service / portal.service     panels on a service page      (data: service)
 *   admin.client                       panels on a client page       (data: client)
 *   admin.invoice / portal.invoice     panels on an invoice page     (data: invoice)
 *   admin.product                      panels on the product form    (data: product)
 *
 * Panels are rendered with {!! extensions('portal.service', ['service' => $service]) !!}.
 */
class Extensions
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $slots = [];

    /** @param  array<string, mixed>  $item */
    public function add(string $slot, array $item): void
    {
        $this->slots[$slot][] = $item;
    }

    /** @param  array<string, mixed>  $context @return Collection<int, array<string, mixed>> */
    public function items(string $slot, array $context = []): Collection
    {
        return collect($this->slots[$slot] ?? [])
            ->filter(fn ($i) => ! isset($i['when']) || ($i['when'])($context))
            ->sortBy(fn ($i) => $i['sort'] ?? 100)
            ->values();
    }

    /** @param  array<string, mixed>  $data */
    public function render(string $slot, array $data = []): HtmlString
    {
        $html = '';
        foreach ($this->items($slot, $data) as $item) {
            if (isset($item['view'])) {
                $extra = is_callable($item['data'] ?? null) ? ($item['data'])($data) : ($item['data'] ?? []);
                $html .= view($item['view'], array_merge($data, $extra))->render();
            }
        }

        return new HtmlString($html);
    }

    /**
     * Sidebar items added by plugins, grouped by label.
     *
     * @return array<string, array<int, array{label: string, url: string, icon: string, active: bool}>>
     */
    public function menu(string $area, ?User $user = null): array
    {
        $groups = [];
        foreach ($this->items($area.'.menu', ['user' => $user]) as $i) {
            $url = isset($i['route']) ? route($i['route']) : ($i['url'] ?? '#');
            $match = $i['match'] ?? ltrim(parse_url($url, PHP_URL_PATH) ?: '', '/').'*';
            $groups[$i['group'] ?? 'Plugins'][] = [
                'label' => $i['label'],
                'url' => $url,
                'icon' => $i['icon'] ?? 'puzzle',
                'active' => request()->is($match),
            ];
        }

        return $groups;
    }
}
