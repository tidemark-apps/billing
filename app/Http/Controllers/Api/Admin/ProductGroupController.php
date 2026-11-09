<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\Reorders;
use App\Http\Resources\ProductGroupResource;
use App\Models\ProductGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductGroupController extends ApiController
{
    use Reorders;

    public function index(Request $request)
    {
        return $this->paginate(ProductGroup::withCount('products')->orderBy('sort')->orderBy('name'), ProductGroupResource::class, $request);
    }

    public function show(ProductGroup $group): ProductGroupResource
    {
        return new ProductGroupResource($group->loadCount('products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $group = ProductGroup::create([
            'name' => $data['name'],
            'slug' => $this->slug($data['name']),
            'description' => $data['description'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'is_visible' => $data['is_visible'] ?? true,
        ]);
        $this->audit('product_group.created', $group);

        return (new ProductGroupResource($group->loadCount('products')))->response()->setStatusCode(201);
    }

    public function update(Request $request, ProductGroup $group): ProductGroupResource
    {
        $group->update($request->validate($this->rules(true)));
        $this->audit('product_group.updated', $group);

        return new ProductGroupResource($group->refresh()->loadCount('products'));
    }

    /** Products keep existing and become ungrouped. */
    public function destroy(Request $request, ProductGroup $group)
    {
        $this->requireAdmin($request, 'Only administrators can delete product groups.');
        $this->audit('product_group.deleted', null, 'Deleted product group '.$group->name);
        $group->delete();

        return response()->noContent();
    }

    /** Display order of the groups: send the group ids in the order you want. */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:product_groups,id']]);
        $this->applyOrder(ProductGroup::class, $data['ids']);
        $this->audit('product_group.reordered', null, 'Reordered product groups');

        return ProductGroupResource::collection(ProductGroup::withCount('products')->orderBy('sort')->orderBy('name')->get());
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }

    private function slug(string $name): string
    {
        $base = $slug = Str::slug($name) ?: 'group';
        for ($n = 2; ProductGroup::where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
