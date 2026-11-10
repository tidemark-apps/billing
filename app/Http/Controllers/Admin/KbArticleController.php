<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Knowledgebase\Knowledgebase;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Knowledge base articles and the overview tree (all staff). */
class KbArticleController extends Controller
{
    /** Top-level slugs the reader routes already use. */
    public const RESERVED = ['search', 'media', 'feedback'];

    public function __construct(private readonly Knowledgebase $kb) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'view' => ['nullable', 'in:published,draft'],
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:kb_categories,id'],
        ]);
        $view = $filters['view'] ?? 'all';
        $q = trim((string) ($filters['q'] ?? ''));
        $category = ! empty($filters['category']) ? KbCategory::find($filters['category']) : null;
        $counts = ['all' => KbArticle::count(), 'published' => KbArticle::published()->count(), 'draft' => KbArticle::where('status', 'draft')->count()];
        $filtering = $view !== 'all' || $q !== '' || $category !== null;

        $articles = null;
        if ($filtering) {
            $articles = KbArticle::query()
                ->when($view !== 'all', fn ($w) => $w->where('status', $view))
                ->when($category, fn ($w) => $w->whereIn('category_id', $this->kb->descendantIds($category)))
                ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->whereContains('title', $q)->orWhereContains('summary', $q)->orWhereContains('body', $q)))
                ->latest('updated_at')->paginate(25)->withQueryString();
        }

        return view('admin.kb.index', [
            'tree' => $this->kb->tree(true),
            'root' => $this->kb->rootArticles(true),
            'kb' => $this->kb,
            'view' => $view,
            'q' => $q,
            'category' => $category,
            'counts' => $counts,
            'filtering' => $filtering,
            'articles' => $articles,
            'options' => $this->kb->options(),
            'totalCategories' => KbCategory::count(),
        ]);
    }

    public function create(Request $request)
    {
        $article = new KbArticle(['category_id' => $request->integer('category') ?: null]);

        return view('admin.kb.article', ['article' => $article, 'options' => $this->kb->options(), 'kb' => $this->kb]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['sort'] = (int) KbArticle::where('category_id', $data['category_id'])->max('sort') + 1;
        $data['author_id'] = $request->user()->id;
        $data['published_at'] = $data['status'] === 'published' ? now() : null;
        $article = KbArticle::create($data);
        Activity::log('kb.article.created', $article, "Created knowledge base article {$article->title}");

        return redirect()->route('admin.kb.articles.edit', $article)->with('status', $article->isPublished() ? 'Article published.' : 'Draft saved.');
    }

    public function edit(KbArticle $article)
    {
        return view('admin.kb.article', ['article' => $article, 'options' => $this->kb->options(), 'kb' => $this->kb]);
    }

    public function update(Request $request, KbArticle $article): RedirectResponse
    {
        $data = $this->validated($request, $article);
        if ($data['status'] === 'published' && ! $article->published_at) {
            $data['published_at'] = now();
        }
        if ($data['category_id'] !== $article->category_id) {
            $data['sort'] = (int) KbArticle::where('category_id', $data['category_id'])->max('sort') + 1;
        }
        $article->update($data);
        Activity::log('kb.article.updated', $article, "Updated knowledge base article {$article->title}");

        return redirect()->route('admin.kb.articles.edit', $article)->with('status', $article->isPublished() ? 'Article saved.' : 'Draft saved.');
    }

    /** Moves an article one place up or down among the articles of its category. */
    public function move(Request $request, KbArticle $article): RedirectResponse
    {
        $request->validate(['direction' => ['required', 'in:up,down']]);
        $siblings = KbArticle::where('category_id', $article->category_id)->orderBy('sort')->orderBy('title')->get();
        $i = $siblings->search(fn (KbArticle $a) => $a->id === $article->id);
        $j = $request->input('direction') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($siblings[$j])) {
            $order = $siblings->all();
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            foreach ($order as $n => $a) {
                if ($a->sort !== $n) {
                    $a->update(['sort' => $n]);
                }
            }
        }

        return back();
    }

    public function destroy(KbArticle $article): RedirectResponse
    {
        $article->delete();
        Activity::log('kb.article.deleted', $article, "Deleted knowledge base article {$article->title}");

        return redirect()->route('admin.kb.index')->with('status', 'Article deleted.');
    }

    /** An image dropped or pasted into the editor. Stored privately and served by kb.media. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'max:5120', 'mimes:png,jpg,jpeg,gif,webp']], ['image.mimes' => 'Use a PNG, JPG, GIF or WebP image.', 'image.max' => 'Images can be up to 5 MB.']);
        $file = $request->file('image');
        if (@getimagesize($file->getRealPath()) === false) {
            throw ValidationException::withMessages(['image' => 'That file is not a valid image.']);
        }
        $ext = strtolower($file->guessExtension() ?: 'png');
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $name = Str::lower(Str::random(24)).'.'.$ext;
        Storage::disk('kb')->put($name, file_get_contents($file->getRealPath()));

        return response()->json(['url' => route('kb.media', $name, false)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?KbArticle $article = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['nullable', 'integer', 'exists:kb_categories,id'],
            'summary' => ['nullable', 'string', 'max:300'],
            'body' => ['required', 'string', 'max:500000'],
            'status' => ['required', 'in:draft,published'],
        ], ['slug.regex' => 'Use lowercase letters, numbers and single hyphens.', 'body.required' => 'Write the article first.']);
        $data['category_id'] = $data['category_id'] ?? null;
        $data['body'] = rtrim(str_replace(["\r\n", "\r"], "\n", $data['body']))."\n";
        $slug = $data['slug'] ?? '';
        if ($slug === '') {
            $data['slug'] = $article && $article->category_id === $data['category_id'] && $article->title === $data['title']
                ? $article->slug
                : $this->kb->uniqueSlug($data['title'], $data['category_id'], null, $article);
        } elseif ($this->kb->slugTaken($slug, $data['category_id'], null, $article)) {
            throw ValidationException::withMessages(['slug' => 'Something in that category already uses this address.']);
        }
        if ($data['category_id'] === null && in_array($data['slug'], self::RESERVED, true)) {
            throw ValidationException::withMessages(['slug' => 'That address is reserved. Choose another.']);
        }
        $data['summary'] = isset($data['summary']) ? trim($data['summary']) ?: null : null;

        return $data;
    }
}
