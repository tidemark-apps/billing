<?php

namespace Tests\Feature\Knowledgebase;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Services\Knowledgebase\Markdown;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgebaseTest extends TestCase
{
    use RefreshDatabase;

    private KbCategory $guides;

    private KbCategory $install;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('kb.enabled', true);
        Settings::set('kb.public', true);
        $this->guides = KbCategory::create(['name' => 'Guides', 'slug' => 'guides']);
        $this->install = KbCategory::create(['name' => 'Install', 'slug' => 'install', 'parent_id' => $this->guides->id]);
    }

    private function article(array $attrs = []): KbArticle
    {
        return KbArticle::create($attrs + ['category_id' => $this->install->id, 'title' => 'Server setup', 'slug' => 'server-setup', 'body' => "## Steps\n\nDo the thing.", 'status' => 'published', 'published_at' => now()]);
    }

    public function test_everything_is_a_404_while_the_knowledge_base_is_off(): void
    {
        $this->article();
        Settings::set('kb.enabled', false);

        $this->get('/kb')->assertNotFound();
        $this->get('/kb/guides/install/server-setup')->assertNotFound();
        $this->actingAs($this->admin())->get(route('admin.kb.index'))->assertNotFound();
    }

    public function test_guests_read_nested_categories_and_articles(): void
    {
        $this->article();

        $this->get('/kb')->assertOk()->assertSee('Guides');
        $this->get('/kb/guides')->assertOk()->assertSee('Install');
        $this->get('/kb/guides/install')->assertOk()->assertSee('Server setup');
        $this->get('/kb/guides/install/server-setup')->assertOk()->assertSee('Do the thing.')->assertSeeInOrder(['Help center', 'Guides', 'Install']);
        $this->get('/kb/guides/server-setup')->assertNotFound();
        $this->get('/kb/nope')->assertNotFound();
    }

    public function test_drafts_and_hidden_categories_are_staff_only(): void
    {
        $this->article(['title' => 'Draft one', 'slug' => 'draft-one', 'status' => 'draft']);
        $secret = KbCategory::create(['name' => 'Internal', 'slug' => 'internal', 'is_visible' => false]);
        KbArticle::create(['category_id' => $secret->id, 'title' => 'Staff notes', 'slug' => 'staff-notes', 'body' => 'Hidden text', 'status' => 'published']);

        $this->get('/kb/guides/install/draft-one')->assertNotFound();
        $this->get('/kb/internal')->assertNotFound();
        $this->get('/kb/internal/staff-notes')->assertNotFound();
        $this->get('/kb')->assertDontSee('Internal');
        $this->get('/kb/search?q=Hidden')->assertOk()->assertDontSee('Staff notes');

        $this->actingAs($this->customer())->get('/kb/internal/staff-notes')->assertNotFound();
        $this->actingAs($this->admin())->get('/kb/guides/install/draft-one')->assertOk()->assertSee('This article is a draft');
        $this->actingAs($this->admin())->get('/kb/internal/staff-notes')->assertOk()->assertSee('Hidden text');
    }

    public function test_guest_toggle_sends_visitors_to_sign_in_but_lets_customers_read(): void
    {
        $this->article();
        Settings::set('kb.public', false);

        $this->get('/kb')->assertRedirect(route('login'));
        $this->get('/kb/guides/install/server-setup')->assertRedirect(route('login'));
        $this->get('/kb/search?q=thing')->assertRedirect(route('login'));
        $this->actingAs($this->customer())->get('/kb/guides/install/server-setup')->assertOk()->assertSee('Do the thing.');
    }

    public function test_search_matches_title_summary_and_body_of_published_articles(): void
    {
        $this->article(['summary' => 'Getting a box ready']);
        $this->article(['title' => 'Other', 'slug' => 'other', 'body' => 'unrelated words']);
        $this->article(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'body' => 'needle hidden in a draft']);

        $this->get('/kb/search?q=server')->assertOk()->assertSee('1 result')->assertSee('Server setup');
        $this->get('/kb/search?q=box')->assertOk()->assertSee('Server setup');
        $this->get('/kb/search?q=thing')->assertOk()->assertSee('Server setup');
        $this->get('/kb/search?q=needle')->assertOk()->assertSee('No matching articles');
        $this->get('/kb/search?q=100%25')->assertOk()->assertSee('No matching articles');
        $this->get('/kb/search')->assertOk()->assertSee('Type a few words');
    }

    public function test_views_count_once_per_session_and_feedback_votes_once(): void
    {
        $a = $this->article();

        $this->get('/kb/guides/install/server-setup')->assertOk();
        $this->get('/kb/guides/install/server-setup')->assertOk();
        $this->assertSame(1, $a->fresh()->views);

        $this->post(route('kb.feedback', $a), ['helpful' => 'yes'])->assertRedirect();
        $this->post(route('kb.feedback', $a), ['helpful' => 'no'])->assertRedirect();
        $this->assertSame([1, 0], [$a->fresh()->helpful_yes, $a->fresh()->helpful_no]);
    }

    public function test_markdown_renders_rich_content_and_strips_anything_unsafe(): void
    {
        $md = app(Markdown::class);
        $html = $md->html("## Heading\n\n> [!WARNING]\n> Careful\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n- [x] done\n\n```php\necho 1;\n```\n\nPress <kbd>Ctrl</kbd> and <details><summary>More</summary>hidden</details>");

        $this->assertStringContainsString('<h2 id="heading">', $html);
        $this->assertStringContainsString('callout callout-warning', $html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('<kbd>Ctrl</kbd>', $html);
        $this->assertStringContainsString('<details>', $html);
        $this->assertSame([['level' => 2, 'id' => 'heading', 'text' => 'Heading']], $md->toc($html));

        $bad = $md->html("[x](javascript:alert(1)) <script>alert(1)</script> <img src=x onerror=alert(1)> <iframe src=\"https://evil.test\"></iframe> <a href=\"JaVa\nScript:alert(1)\" onclick=\"x()\">y</a> <form action=\"/x\"><input name=a></form>");
        foreach (['javascript:', '<script', 'onerror', 'onclick', '<iframe', '<form'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $bad);
        }
    }

    public function test_the_marker_can_stand_alone_in_the_quote_as_the_editor_writes_it(): void
    {
        $html = app(Markdown::class)->html("> \\[!TIP\\]\n>\n> Use the editor");

        $this->assertStringContainsString('callout callout-tip', $html);
        $this->assertStringNotContainsString('[!TIP]', $html);
        $this->assertStringContainsString('Use the editor', $html);
    }

    public function test_staff_manage_nested_categories_and_articles(): void
    {
        $staff = $this->admin();

        $this->actingAs($staff)->post(route('admin.kb.categories.store'), ['name' => 'Windows', 'parent_id' => $this->install->id, 'is_visible' => 1])->assertRedirect(route('admin.kb.index'));
        $windows = KbCategory::where('slug', 'windows')->firstOrFail();
        $this->assertSame($this->install->id, $windows->parent_id);

        $this->actingAs($staff)->post(route('admin.kb.articles.store'), ['title' => 'Install on Windows', 'category_id' => $windows->id, 'body' => "## Hi\r\n\r\nText", 'status' => 'published'])->assertRedirect();
        $article = KbArticle::where('slug', 'install-on-windows')->firstOrFail();
        $this->assertSame("## Hi\n\nText\n", $article->body);
        $this->assertNotNull($article->published_at);
        $this->get('/kb/guides/install/windows/install-on-windows')->assertOk()->assertSee('Text');

        // two things cannot share an address under one parent
        $this->actingAs($staff)->post(route('admin.kb.articles.store'), ['title' => 'Again', 'slug' => 'install-on-windows', 'category_id' => $windows->id, 'body' => 'x', 'status' => 'draft'])->assertSessionHasErrors('slug');
        $this->actingAs($staff)->post(route('admin.kb.articles.store'), ['title' => 'Install on Windows', 'category_id' => $windows->id, 'body' => 'x', 'status' => 'draft'])->assertRedirect();
        $this->assertTrue(KbArticle::where('slug', 'install-on-windows-2')->exists());
        $this->actingAs($staff)->post(route('admin.kb.articles.store'), ['title' => 'Search', 'body' => 'x', 'status' => 'draft'])->assertRedirect();
        $this->assertTrue(KbArticle::where('slug', 'search-2')->doesntExist(), 'reserved top-level slugs are refused, not renamed');
    }

    public function test_reserved_top_level_address_is_refused(): void
    {
        $this->actingAs($this->admin())->post(route('admin.kb.articles.store'), ['title' => 'Media', 'slug' => 'media', 'body' => 'x', 'status' => 'draft'])->assertSessionHasErrors('slug');
    }

    public function test_a_category_cannot_move_into_itself_and_only_empty_ones_delete(): void
    {
        $staff = $this->admin();

        $this->actingAs($staff)->put(route('admin.kb.categories.update', $this->guides), ['name' => 'Guides', 'parent_id' => $this->install->id])->assertSessionHasErrors('parent_id');

        $this->article();
        $this->actingAs($staff)->delete(route('admin.kb.categories.destroy', $this->install))->assertSessionHasErrors('category');
        $this->assertTrue($this->install->fresh() !== null);
        $this->actingAs($staff)->delete(route('admin.kb.categories.destroy', $this->guides))->assertSessionHasErrors('category');

        KbArticle::query()->delete();
        $this->actingAs($staff)->delete(route('admin.kb.categories.destroy', $this->install))->assertRedirect(route('admin.kb.index'));
        $this->assertNull(KbCategory::find($this->install->id));
    }

    public function test_articles_and_categories_reorder(): void
    {
        $a = $this->article(['title' => 'A', 'slug' => 'a', 'sort' => 0]);
        $b = $this->article(['title' => 'B', 'slug' => 'b', 'sort' => 1]);

        $this->actingAs($this->admin())->post(route('admin.kb.articles.move', $b), ['direction' => 'up'])->assertRedirect();
        $this->assertTrue($b->fresh()->sort < $a->fresh()->sort);

        $other = KbCategory::create(['name' => 'Another', 'slug' => 'another', 'sort' => 5]);
        $this->actingAs($this->admin())->post(route('admin.kb.categories.move', $other), ['direction' => 'up'])->assertRedirect();
        $this->assertTrue($other->fresh()->sort < $this->guides->fresh()->sort);
    }

    public function test_admin_list_filters_by_status_search_and_category(): void
    {
        $this->article(['title' => 'Alpha guide', 'slug' => 'alpha']);
        $this->article(['title' => 'Beta draft', 'slug' => 'beta', 'status' => 'draft']);
        $other = KbCategory::create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        KbArticle::create(['category_id' => $other->id, 'title' => 'Gamma', 'slug' => 'gamma', 'body' => 'needle text', 'status' => 'published']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.kb.index'))->assertOk()->assertSee('Guides')->assertSee('Alpha guide');
        $this->actingAs($admin)->get(route('admin.kb.index', ['view' => 'draft']))->assertOk()->assertSee('Beta draft')->assertDontSee('Alpha guide');
        $this->actingAs($admin)->get(route('admin.kb.index', ['q' => 'needle']))->assertOk()->assertSee('Gamma')->assertDontSee('Alpha guide');
        $this->actingAs($admin)->get(route('admin.kb.index', ['category' => $this->guides->id]))->assertOk()->assertSee('Alpha guide')->assertDontSee('Gamma');
        $this->actingAs($admin)->get(route('admin.kb.index', ['q' => 'nothing-like-this']))->assertOk()->assertSee('No articles found');
        $this->actingAs($admin)->get(route('admin.kb.index', ['view' => 'bogus']))->assertSessionHasErrors('view');
    }

    public function test_customers_cannot_reach_the_admin_side(): void
    {
        $this->actingAs($this->customer())->get(route('admin.kb.index'))->assertForbidden();
        $this->actingAs($this->customer())->post(route('admin.kb.articles.store'), ['title' => 'x', 'body' => 'x', 'status' => 'draft'])->assertForbidden();
        $this->assertSame(0, KbArticle::count());
    }

    public function test_image_upload_is_private_checked_and_served_through_the_reader_route(): void
    {
        Storage::fake('kb');
        $staff = $this->admin();
        $png = UploadedFile::fake()->image('shot.png', 20, 20);

        $url = $this->actingAs($staff)->postJson(route('admin.kb.upload'), ['image' => $png])->assertOk()->json('url');
        $this->assertStringStartsWith('/kb/media/', $url);
        $name = basename($url);
        Storage::disk('kb')->assertExists($name);

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/kb/media/../../.env')->assertNotFound();
        $this->get('/kb/media/'.str_repeat('a', 24).'.png')->assertNotFound();

        $this->actingAs($staff)->postJson(route('admin.kb.upload'), ['image' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')])->assertStatus(422);
        $this->actingAs($staff)->postJson(route('admin.kb.upload'), ['image' => UploadedFile::fake()->createWithContent('fake.png', 'not really an image')])->assertStatus(422);
        $this->actingAs($this->customer())->postJson(route('admin.kb.upload'), ['image' => UploadedFile::fake()->image('b.png')])->assertForbidden();

        Settings::set('kb.public', false);
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_navigation_shows_the_knowledge_base_only_while_enabled(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee('Knowledge base');
        $this->actingAs($this->customer())->get(route('portal.dashboard'))->assertSee('Knowledge base');

        Settings::set('kb.enabled', false);
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertDontSee(route('admin.kb.index'), false);
        $this->actingAs($this->customer())->get(route('portal.dashboard'))->assertDontSee(route('kb.index'), false);
    }

    public function test_settings_tab_has_the_toggles(): void
    {
        $this->actingAs($this->admin())->get(route('admin.settings.index', 'knowledgebase'))->assertOk()->assertSee('Enable the knowledge base')->assertSee('Let guests read it');
        $this->actingAs($this->admin())->put(route('admin.settings.update', 'knowledgebase'), ['s' => ['kb__enabled' => '0', 'kb__public' => '0', 'kb__title' => 'Docs', 'kb__intro' => 'Hello']])->assertRedirect();
        $this->assertFalse(Settings::bool('kb.enabled'));
        $this->assertFalse(Settings::bool('kb.public'));
        $this->assertSame('Docs', Settings::get('kb.title'));
    }
}
