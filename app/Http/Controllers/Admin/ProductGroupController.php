<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductGroup;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductGroupController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $group = ProductGroup::create($this->fill($data, $request));
        Activity::log('product_group.created', $group);

        return back()->with('status', 'Group created.');
    }

    public function update(Request $request, ProductGroup $group): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $group->update($this->fill($data, $request, $group));

        return back()->with('status', 'Group saved.');
    }

    public function destroy(Request $request, ProductGroup $group): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $group->delete(); // products keep existing, ungrouped (nullOnDelete)

        return back()->with('status', 'Group deleted. Its products are now ungrouped.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'sort' => ['nullable', 'integer', 'min:0']];
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private function fill(array $data, Request $request, ?ProductGroup $group = null): array
    {
        $slug = Str::slug($data['name']) ?: 'group';
        $base = $slug;
        for ($n = 2; ProductGroup::where('slug', $slug)->when($group, fn ($q) => $q->where('id', '!=', $group->id))->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return ['name' => $data['name'], 'slug' => $group?->slug ?? $slug, 'description' => $data['description'] ?? null, 'sort' => $data['sort'] ?? 0, 'is_visible' => $request->boolean('is_visible', true)];
    }
}
