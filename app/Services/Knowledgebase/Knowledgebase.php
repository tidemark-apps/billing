<?php

namespace App\Services\Knowledgebase;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The knowledgebase as a tree: who may see it, the nested categories with their articles, and URL paths
 * (/kb/getting-started/quick-start). The whole tree is read once per request; a help centre is small.
 */
class Knowledgebase
{
    /** @var array{cats: Collection<int, KbCategory>, articles: Collection<int, KbArticle>}|null */
    private ?array $loaded = null;

    public static function enabled(): bool
    {
        return Settings::bool('kb.enabled', false);
    }

    public static function publicAccess(): bool
    {
        return Settings::bool('kb.public', true);
    }

    public static function title(): string
    {
        return trim((string) Settings::get('kb.title', '')) ?: 'Help center';
    }

    /** Anyone while guests are allowed; otherwise only signed-in people. */
    public function canView(?User $user): bool
    {
        return self::enabled() && (self::publicAccess() || $user !== null);
    }

    public function forget(): void
    {
        $this->loaded = null;
    }

    /** @return array{cats: Collection<int, KbCategory>, articles: Collection<int, KbArticle>} */
    private function load(): array
    {
        return $this->loaded ??= [
            'cats' => KbCategory::orderBy('sort')->orderBy('name')->get()->keyBy('id'),
            'articles' => KbArticle::select(['id', 'category_id', 'title', 'slug', 'summary', 'status', 'sort', 'published_at', 'updated_at', 'views', 'helpful_yes', 'helpful_no'])->orderBy('sort')->orderBy('title')->get(),
        ];
    }

    /** Slugs from the top down, e.g. ['getting-started', 'install']. @return array<int, string> */
    public function slugs(?KbCategory $category): array
    {
        $out = [];
        $cats = $this->load()['cats'];
        for ($c = $category, $guard = 0; $c && $guard < 50; $c = $c->parent_id ? $cats->get($c->parent_id) : null, $guard++) {
            array_unshift($out, $c->slug);
        }

        return $out;
    }

    public function categoryPath(KbCategory $category): string
    {
        return implode('/', $this->slugs($category));
    }

    public function categoryUrl(KbCategory $category): string
    {
        return route('kb.show', ['path' => $this->categoryPath($category)]);
    }

    public function articleUrl(KbArticle $article): string
    {
        $slugs = $this->slugs($article->category_id ? $this->load()['cats']->get($article->category_id) : null);
        $slugs[] = $article->slug;

        return route('kb.show', ['path' => implode('/', $slugs)]);
    }

    /** The category chain from the top down, for breadcrumbs. @return Collection<int, KbCategory> */
    public function ancestry(?KbCategory $category): Collection
    {
        $cats = $this->load()['cats'];
        $chain = collect();
        for ($c = $category, $guard = 0; $c && $guard < 50; $c = $c->parent_id ? $cats->get($c->parent_id) : null, $guard++) {
            $chain->prepend($c);
        }

        return $chain;
    }

    /** True when the category, or any category above it, is hidden. */
    public function isHidden(?KbCategory $category): bool
    {
        return $this->ancestry($category)->contains(fn (KbCategory $c) => ! $c->is_visible);
    }

    /** Whether this article is on the public side: published and not inside a hidden category. */
    public function isPublic(KbArticle $article): bool
    {
        return $article->isPublished() && ! $this->isHidden($article->category_id ? $this->load()['cats']->get($article->category_id) : null);
    }

    /**
     * The tree below a category (or the top level). Each node: category, children, articles, count (articles anywhere below).
     * Visitors see published articles in visible categories; staff see everything.
     *
     * @return Collection<int, array{category: KbCategory, children: Collection, articles: Collection, count: int}>
     */
    public function tree(bool $staff = false, ?int $parent = null): Collection
    {
        ['cats' => $cats, 'articles' => $articles] = $this->load();
        $build = function (?int $parentId) use (&$build, $cats, $articles, $staff): Collection {
            return $cats->filter(fn (KbCategory $c) => $c->parent_id === $parentId && ($staff || $c->is_visible))
                ->sortBy([['sort', 'asc'], ['name', 'asc']])->values()
                ->map(function (KbCategory $c) use (&$build, $articles, $staff) {
                    $children = $build($c->id);
                    $mine = $articles->filter(fn (KbArticle $a) => $a->category_id === $c->id && ($staff || $a->isPublished()))->values();

                    return ['category' => $c, 'children' => $children, 'articles' => $mine, 'count' => $mine->count() + $children->sum('count')];
                })
                ->filter(fn (array $n) => $staff || $n['count'] > 0)->values();
        };

        return $build($parent);
    }

    /** Articles that sit at the top level, outside any category. @return Collection<int, KbArticle> */
    public function rootArticles(bool $staff = false): Collection
    {
        return $this->load()['articles']->filter(fn (KbArticle $a) => $a->category_id === null && ($staff || $a->isPublished()))->values();
    }

    /** @return Collection<int, KbArticle> */
    public function articlesIn(?KbCategory $category, bool $staff = false): Collection
    {
        $id = $category?->id;

        return $this->load()['articles']->filter(fn (KbArticle $a) => $a->category_id === $id && ($staff || $a->isPublished()))->values();
    }

    /** @return Collection<int, KbCategory> direct children, hidden ones only for staff */
    public function childrenOf(?KbCategory $category, bool $staff = false): Collection
    {
        $id = $category?->id;

        return $this->load()['cats']->filter(fn (KbCategory $c) => $c->parent_id === $id && ($staff || $c->is_visible))->sortBy([['sort', 'asc'], ['name', 'asc']])->values();
    }

    /** Resolves "a/b/c" to a category, or to an article when the last part is one. @return array{category?: KbCategory, article?: KbArticle}|null */
    public function resolve(string $path, bool $staff = false): ?array
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));
        if ($parts === []) {
            return null;
        }
        ['cats' => $cats, 'articles' => $articles] = $this->load();
        $parent = null;
        $current = null;
        foreach ($parts as $i => $slug) {
            $match = $cats->first(fn (KbCategory $c) => $c->parent_id === $parent && $c->slug === $slug && ($staff || $c->is_visible));
            if ($match) {
                $parent = $match->id;
                $current = $match;

                continue;
            }
            if ($i === count($parts) - 1) {
                $article = $articles->first(fn (KbArticle $a) => $a->category_id === $parent && $a->slug === $slug && ($staff || $a->isPublished()));

                return $article ? ['article' => KbArticle::with(['author:id,name'])->find($article->id), 'category' => $current] : null;
            }

            return null;
        }

        return $current ? ['category' => $current] : null;
    }

    /** Neighbours within the same category for "previous / next". @return array{prev: ?KbArticle, next: ?KbArticle} */
    public function neighbours(KbArticle $article, bool $staff = false): array
    {
        $siblings = $this->articlesIn($article->category_id ? $this->load()['cats']->get($article->category_id) : null, $staff);
        $i = $siblings->search(fn (KbArticle $a) => $a->id === $article->id);

        return ['prev' => $i > 0 ? $siblings[$i - 1] : null, 'next' => $i !== false && $i < $siblings->count() - 1 ? $siblings[$i + 1] : null];
    }

    /** A slug that is free among its siblings (categories and articles share one namespace under a parent). */
    public function uniqueSlug(string $wanted, ?int $parentId, ?KbCategory $exceptCategory = null, ?KbArticle $exceptArticle = null): string
    {
        $base = Str::slug($wanted) ?: 'untitled';
        $slug = $base;
        for ($n = 2; $this->slugTaken($slug, $parentId, $exceptCategory, $exceptArticle); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }

    public function slugTaken(string $slug, ?int $parentId, ?KbCategory $exceptCategory = null, ?KbArticle $exceptArticle = null): bool
    {
        return KbCategory::where('parent_id', $parentId)->where('slug', $slug)->when($exceptCategory, fn ($q) => $q->where('id', '!=', $exceptCategory->id))->exists()
            || KbArticle::where('category_id', $parentId)->where('slug', $slug)->when($exceptArticle, fn ($q) => $q->where('id', '!=', $exceptArticle->id))->exists();
    }

    /** Category ids at and below a category (to stop a category being moved into itself). @return array<int, int> */
    public function descendantIds(KbCategory $category): array
    {
        $cats = $this->load()['cats'];
        $ids = [$category->id];
        $queue = [$category->id];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($cats->where('parent_id', $id) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /** A flat, indented list for a category picker. @return array<int, array{id: int, label: string, depth: int}> */
    public function options(?KbCategory $except = null): array
    {
        $skip = $except ? $this->descendantIds($except) : [];
        $out = [];
        $walk = function (Collection $nodes, int $depth) use (&$walk, &$out, $skip) {
            foreach ($nodes as $n) {
                if (in_array($n['category']->id, $skip, true)) {
                    continue;
                }
                $out[] = ['id' => $n['category']->id, 'label' => str_repeat('— ', $depth).$n['category']->name, 'depth' => $depth];
                $walk($n['children'], $depth + 1);
            }
        };
        $walk($this->tree(true), 0);

        return $out;
    }

    /** Published, visible articles matching the words. @return Collection<int, KbArticle> */
    public function search(string $query, int $limit = 30): Collection
    {
        $terms = collect(preg_split('/\s+/', trim($query)) ?: [])->filter(fn ($t) => mb_strlen($t) >= 2)->take(6)->values();
        if ($terms->isEmpty()) {
            return collect();
        }
        $cats = $this->load()['cats'];
        $q = KbArticle::published();
        foreach ($terms as $t) {
            $q->where(fn ($w) => $w->whereContains('title', $t)->orWhereContains('summary', $t)->orWhereContains('body', $t));
        }

        return $q->limit(200)->get()
            ->filter(fn (KbArticle $a) => ! $this->isHidden($a->category_id ? $cats->get($a->category_id) : null))
            ->sortByDesc(fn (KbArticle $a) => $terms->sum(fn ($t) => (int) (stripos($a->title, $t) !== false)) * 2 + $terms->sum(fn ($t) => (int) (stripos((string) $a->summary, $t) !== false)))
            ->take($limit)->values();
    }
}
