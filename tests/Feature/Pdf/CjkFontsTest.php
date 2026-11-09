<?php

namespace Tests\Feature\Pdf;

use App\Services\InvoiceService;
use App\Services\PdfFonts;
use App\Services\PdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CjkFontsTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    private string $fontBytes;

    private string $served = '';

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/tidemark-fonts-'.uniqid();
        // A small real TrueType font stands in for the multi-megabyte pack.
        $this->fontBytes = (string) file_get_contents(base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'));
        $this->served = $this->fontBytes;
        Http::fake(['fonts.test/*' => fn () => Http::response($this->served, $this->status)]);
        config([
            'pdf-fonts.work_dir' => $this->work,
            'pdf-fonts.packs.sc.url' => 'https://fonts.test/sc.ttf',
            'pdf-fonts.packs.sc.sha256' => hash('sha256', $this->fontBytes),
            'pdf-fonts.packs.sc.size' => strlen($this->fontBytes),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    public function test_detects_cjk_scripts(): void
    {
        $f = new PdfFonts;
        $this->assertTrue($f->hasCjk('你好世界'));
        $this->assertTrue($f->hasCjk('日本語のテスト'));
        $this->assertTrue($f->hasCjk('한국어 테스트'));
        $this->assertTrue($f->hasCjk('Order for 株式会社 Acme'));
        $this->assertFalse($f->hasCjk('Hébergement café – Ünïcode Консультация €'));
        $this->assertFalse($f->hasCjk(''));
        $this->assertSame(['han'], $f->scriptsIn('你好世界'));
        $this->assertSame(['han', 'kana'], $f->scriptsIn('日本語のテスト'));
        $this->assertSame(['hangul'], $f->scriptsIn('한국어 테스트'));
        $this->assertTrue($f->anyCjk(['plain', null, '中文']));
        $this->assertFalse($f->anyCjk(['plain', null]));
    }

    public function test_html_is_untouched_without_cjk_or_without_a_pack(): void
    {
        $f = new PdfFonts;
        $latin = '<html><head><title>x</title></head><body><p>Hello café</p></body></html>';
        $this->assertSame($latin, $f->apply($latin));

        Log::shouldReceive('warning')->once()->withArgs(fn ($m) => str_contains($m, 'billing:fonts:install'));
        $cjk = '<html><head></head><body><p>你好世界</p></body></html>';
        $this->assertSame($cjk, $f->apply($cjk));
    }

    public function test_install_verifies_checksum_and_registers_the_font(): void
    {
        $this->served = $this->fontBytes;
        $f = new PdfFonts;
        $f->install('sc');

        $this->assertFileExists($this->work.'/fonts/NotoSansSC-Regular.ttf');
        $this->assertFileExists($this->work.'/fonts/NotoSansSC-Regular.ufm');
        $this->assertSame(['sc'], $f->installed());
        $this->assertArrayHasKey('noto sans sc', json_decode(file_get_contents($this->work.'/fonts/installed-fonts.json'), true));

        $f->uninstall('sc');
        $this->assertSame([], $f->installed());
        $this->assertFileDoesNotExist($this->work.'/fonts/NotoSansSC-Regular.ttf');
    }

    public function test_install_rejects_checksum_mismatch_and_bad_responses(): void
    {
        $this->served = $this->fontBytes.'tampered';
        $f = new PdfFonts;
        try {
            $f->install('sc');
            $this->fail('A tampered download must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Checksum mismatch', $e->getMessage());
        }
        $this->assertSame([], $f->installed());
        $this->assertFileDoesNotExist($this->work.'/fonts/NotoSansSC-Regular.ttf');

        config(['pdf-fonts.packs.sc.sha256' => hash('sha256', 'not a font')]);
        $this->served = 'not a font';
        try {
            $f->install('sc');
            $this->fail('Bytes that are not a font must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TrueType', $e->getMessage());
        }
        $this->assertSame([], $f->installed());

        $this->served = '';
        $this->status = 404;
        $this->expectException(\RuntimeException::class);
        $f->install('sc');
    }

    public function test_install_command(): void
    {
        $this->served = $this->fontBytes.'x';
        $this->artisan('billing:fonts:install', ['--pack' => ['sc']])->assertFailed();
        $this->assertSame([], app(PdfFonts::class)->installed());

        $this->served = $this->fontBytes;
        $this->artisan('billing:fonts:install', ['--pack' => ['sc']])->assertSuccessful();
        $this->assertSame(['sc'], app(PdfFonts::class)->installed());

        $this->artisan('billing:fonts:install', ['--pack' => ['nope']])->assertFailed();
        $this->artisan('billing:fonts:install', ['--pack' => ['sc'], '--remove' => true])->assertSuccessful();
        $this->assertSame([], app(PdfFonts::class)->installed());
    }

    public function test_installed_pack_wraps_each_script_and_renders(): void
    {
        $this->served = $this->fontBytes;
        $f = app(PdfFonts::class);
        $f->install('sc');

        $html = $f->apply('<html><head><style>a{}</style></head><body><td>Name 你好</td><p>日本語 &amp; 한국어</p></body></html>');
        $this->assertStringContainsString("<span style=\"font-family:'Noto Sans SC'", $html);
        $this->assertStringContainsString('>你好</span>', $html);
        $this->assertStringContainsString('Name <span', $html);
        $this->assertStringContainsString('&amp;', $html);

        $client = $this->customer(['name' => '田中 太郎', 'company_name' => '株式会社サンプル 한국어', 'currency' => 'JPY', 'country' => 'JP'])->client;
        $invoice = app(InvoiceService::class)->create($client, [['description' => '你好世界 日本語のテスト 한국어 테스트', 'unit_price' => 1000]], ['issue' => true, 'send' => false]);
        $renderer = app(PdfRenderer::class);
        $this->assertFalse($renderer->needsCjkFonts($invoice));
        $this->assertStringStartsWith('%PDF', $renderer->invoice($invoice));
    }

    public function test_renders_cjk_without_pack_and_flags_it_to_admins(): void
    {
        $client = $this->customer(['name' => '田中 太郎', 'company_name' => '株式会社サンプル', 'currency' => 'JPY', 'country' => 'JP'])->client;
        $invoice = app(InvoiceService::class)->create($client, [['description' => '你好世界 日本語のテスト 한국어 테스트', 'unit_price' => 1000]], ['issue' => true, 'send' => false]);
        $renderer = app(PdfRenderer::class);

        $this->assertTrue($renderer->needsCjkFonts($invoice));
        $this->assertStringStartsWith('%PDF', $renderer->invoice($invoice));
        $this->actingAs($this->admin())->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('no PDF font pack is installed');

        $latin = app(InvoiceService::class)->create($this->customer()->client, [['description' => 'Hosting', 'unit_price' => 1000]], ['issue' => true, 'send' => false]);
        $this->assertFalse($renderer->needsCjkFonts($latin));
        $this->get(route('admin.invoices.show', $latin))->assertDontSee('no PDF font pack is installed');
    }

    public function test_admin_installs_fonts_from_settings(): void
    {
        $this->served = $this->fontBytes;
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.settings.index', 'system'))->assertOk()->assertSee('PDF fonts');
        $this->post(route('admin.settings.pdf-fonts'), ['pack' => 'sc'])->assertRedirect();
        $this->assertSame(['sc'], app(PdfFonts::class)->installed());
        $this->post(route('admin.settings.pdf-fonts'), ['pack' => 'sc', 'action' => 'remove'])->assertRedirect();
        $this->assertSame([], app(PdfFonts::class)->installed());
        $this->actingAs($this->customer())->post(route('admin.settings.pdf-fonts'), ['pack' => 'sc'])->assertForbidden();
    }
}
