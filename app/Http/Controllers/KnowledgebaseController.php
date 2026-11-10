<?php

namespace App\Http\Controllers;

use App\Models\KbArticle;
use App\Services\Knowledgebase\Knowledgebase;
use App\Services\Knowledgebase\Markdown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The reader side of the knowledge base: home, categories, articles, search, images and the "was this helpful" vote. */
class KnowledgebaseController extends Controller
{
    public function __construct(private readonly Knowledgebase $kb, private readonly Markdown $markdown) {}

    private function staff(): bool
    {
        return (bool) auth()->user()?->isStaff();
    }

    public function index()
    {
        $staff = $this->staff();
        $tree = $this->kb->tree($staff);
        $articles = KbArticle::published()->get()->filter(fn (KbArticle $a) => $this->kb->isPublic($a));

        return view('kb.index', [
            'kb' => $this->kb,
            'staff' => $staff,
            'tree' => $tree,
            'root' => $this->kb->rootArticles($staff),
            'popular' => $articles->where('views', '>', 0)->sortByDesc('views')->take(6)->values(),
            'recent' => $articles->sortByDesc(fn (KbArticle $a) => $a->updated_at)->take(6)->values(),
        ]);
    }

    public function show(string $path)
    {
        $staff = $this->staff();
        $found = $this->kb->resolve($path, $staff);
        abort_if($found === null, 404);

        if (isset($found['article'])) {
            return $this->article($found['article'], $staff);
        }
        $category = $found['category'];

        return view('kb.category', [
            'kb' => $this->kb,
            'staff' => $staff,
            'tree' => $this->kb->tree($staff),
            'category' => $category,
            'crumbs' => $this->kb->ancestry($category),
            'children' => $this->kb->tree($staff, $category->id),
            'articles' => $this->kb->articlesIn($category, $staff),
        ]);
    }

    private function article(KbArticle $article, bool $staff)
    {
        $html = $this->markdown->html($article->body);
        if (! $staff && ! session()->has('kb.viewed.'.$article->id)) {
            session()->put('kb.viewed.'.$article->id, true);
            KbArticle::whereKey($article->id)->increment('views');
        }
        $category = $article->category;

        return view('kb.article', [
            'kb' => $this->kb,
            'staff' => $staff,
            'tree' => $this->kb->tree($staff),
            'article' => $article,
            'html' => $html,
            'toc' => $this->markdown->toc($html),
            'crumbs' => $this->kb->ancestry($category),
            'neighbours' => $this->kb->neighbours($article, $staff),
            'voted' => session('kb.voted.'.$article->id),
        ]);
    }

    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $results = $this->kb->search($q)->map(function (KbArticle $a) use ($q) {
            $a->setAttribute('snippet', $this->snippet($a, $q));

            return $a;
        });

        return view('kb.search', ['kb' => $this->kb, 'staff' => $this->staff(), 'tree' => $this->kb->tree($this->staff()), 'q' => $q, 'results' => $results]);
    }

    private function snippet(KbArticle $article, string $q): string
    {
        if (filled($article->summary)) {
            return (string) $article->summary;
        }
        $text = $this->markdown->text($article->body);
        $first = collect(preg_split('/\s+/', $q))->filter()->first();
        $pos = $first ? mb_stripos($text, $first) : false;
        $from = $pos === false ? 0 : max(0, $pos - 60);

        return ($from > 0 ? '…' : '').mb_substr($text, $from, 180).(mb_strlen($text) > $from + 180 ? '…' : '');
    }

    public function feedback(Request $request, KbArticle $article)
    {
        abort_unless($this->kb->isPublic($article) || $this->staff(), 404);
        $data = $request->validate(['helpful' => ['required', 'in:yes,no']]);
        if (! session()->has('kb.voted.'.$article->id)) {
            KbArticle::whereKey($article->id)->increment($data['helpful'] === 'yes' ? 'helpful_yes' : 'helpful_no');
            session()->put('kb.voted.'.$article->id, $data['helpful']);
        }

        return back()->with('kb_thanks', true);
    }

    public function media(string $file): Response
    {
        $disk = Storage::disk('kb');
        abort_unless(preg_match('/^[a-z0-9]{24}\.(png|jpg|gif|webp)$/', $file) && $disk->exists($file), 404);
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][pathinfo($file, PATHINFO_EXTENSION)];

        return response($disk->get($file), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => (Knowledgebase::publicAccess() ? 'public' : 'private').', max-age=86400',
        ]);
    }
}
