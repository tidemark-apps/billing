<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Knowledgebase\Knowledgebase;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Knowledge base categories, nested to any depth. */
class KbCategoryController extends Controller
{
    public function __construct(private readonly Knowledgebase $kb) {}

    public function create(Request $request)
    {
        $category = new KbCategory(['parent_id' => $request->integer('parent') ?: null]);

        return view('admin.kb.category', ['category' => $category, 'options' => $this->kb->options(), 'kb' => $this->kb]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort'] = (int) KbCategory::where('parent_id', $data['parent_id'])->max('sort') + 1;
        $category = KbCategory::create($data);
        Activity::log('kb.category.created', $category, "Created knowledge base category {$category->name}");

        return redirect()->route('admin.kb.index')->with('status', 'Category added.');
    }

    public function edit(KbCategory $category)
    {
        return view('admin.kb.category', ['category' => $category, 'options' => $this->kb->options($category), 'kb' => $this->kb, 'articles' => $category->articles()->count(), 'children' => $category->children()->count()]);
    }

    public function update(Request $request, KbCategory $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        if ($data['parent_id'] !== $category->parent_id) {
            $data['sort'] = (int) KbCategory::where('parent_id', $data['parent_id'])->max('sort') + 1;
        }
        $category->update($data);
        Activity::log('kb.category.updated', $category, "Updated knowledge base category {$category->name}");

        return redirect()->route('admin.kb.index')->with('status', 'Category saved.');
    }

    public function move(Request $request, KbCategory $category): RedirectResponse
    {
        $request->validate(['direction' => ['required', 'in:up,down']]);
        $siblings = KbCategory::where('parent_id', $category->parent_id)->orderBy('sort')->orderBy('name')->get();
        $i = $siblings->search(fn (KbCategory $c) => $c->id === $category->id);
        $j = $request->input('direction') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($siblings[$j])) {
            $order = $siblings->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $n => $c) {
                if ($c->sort !== $n) {
                    $c->update(['sort' => $n]);
                }
            }
        }

        return back();
    }

    public function destroy(KbCategory $category): RedirectResponse
    {
        if ($category->children()->exists() || KbArticle::where('category_id', $category->id)->exists()) {
            return back()->withErrors(['category' => 'This category still has articles or subcategories. Move or delete them first.']);
        }
        $category->delete();
        Activity::log('kb.category.deleted', $category, "Deleted knowledge base category {$category->name}");

        return redirect()->route('admin.kb.index')->with('status', 'Category deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?KbCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'parent_id' => ['nullable', 'integer', 'exists:kb_categories,id'],
            'description' => ['nullable', 'string', 'max:300'],
            'icon' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'is_visible' => ['nullable', 'boolean'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and single hyphens.', 'icon.regex' => 'Use an icon name such as book-open or settings.']);
        $data['parent_id'] = $data['parent_id'] ?? null;
        if ($category && $data['parent_id'] && in_array($data['parent_id'], $this->kb->descendantIds($category), true)) {
            throw ValidationException::withMessages(['parent_id' => 'A category cannot go inside itself.']);
        }
        $slug = $data['slug'] ?? '';
        if ($slug === '') {
            $data['slug'] = $category && $category->parent_id === $data['parent_id'] && $category->name === $data['name']
                ? $category->slug
                : $this->kb->uniqueSlug($data['name'], $data['parent_id'], $category);
        } elseif ($this->kb->slugTaken($slug, $data['parent_id'], $category)) {
            throw ValidationException::withMessages(['slug' => 'Something at this level already uses this address.']);
        }
        if ($data['parent_id'] === null && in_array($data['slug'], KbArticleController::RESERVED, true)) {
            throw ValidationException::withMessages(['slug' => 'That address is reserved. Choose another.']);
        }
        $data['is_visible'] = $request->boolean('is_visible');
        $data['description'] = isset($data['description']) ? trim($data['description']) ?: null : null;
        $data['icon'] = $data['icon'] ?? null;

        return $data;
    }
}
