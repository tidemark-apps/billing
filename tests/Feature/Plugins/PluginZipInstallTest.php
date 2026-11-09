<?php

namespace Tests\Feature\Plugins;

use App\Models\ActivityLog;
use App\Models\PluginRecord;
use App\Models\User;
use App\Plugins\PluginConfirmationRequired;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class PluginZipInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = storage_path('framework/testing/plugin-zip-'.uniqid());
        $this->root = $this->scratch.'/plugins';
        File::ensureDirectoryExists($this->root);
        config([
            'billing.plugins_path' => $this->root,
            'billing.plugins_extra_path' => null,
            'billing.allow_plugin_upload' => true,
            'billing.plugin_upload' => ['max_mb' => 16, 'max_files' => 5000, 'max_unpacked_mb' => 100],
        ]);
        $this->resetManager();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        File::deleteDirectory(storage_path('app/plugin-uploads'));
        foreach (['zipdemo', 'rmdemo', 'keepdemo'] as $slug) {
            foreach (glob(storage_path('app/plugin-backups/'.$slug.'-*')) ?: [] as $dir) {
                File::deleteDirectory($dir);
            }
        }
        parent::tearDown();
    }

    private function resetManager(): void
    {
        $this->app->forgetInstance(PluginManager::class);
        $this->app->singleton(PluginManager::class);
        $this->app->forgetInstance(PluginInstaller::class);
    }

    /** @param  array<string, string>  $extra  additional zip entries */
    private function zip(string $slug = 'zipdemo', string $version = '1.0.0', array $extra = [], string $prefix = '', array $manifest = [], bool $withManifest = true): string
    {
        $ns = 'Plugins\\'.ucfirst($slug).'\\';
        $manifest += ['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version, 'namespace' => $ns, 'provider' => $ns.'Main'];
        $files = [];
        if ($withManifest) {
            $files['plugin.json'] = json_encode($manifest);
        }
        $files['src/Main.php'] = "<?php\nnamespace ".rtrim($ns, '\\').";\nclass Main extends \\App\\Plugins\\Plugin\n{\n    public function uninstall(): void { \\App\\Support\\Settings::set('plugin.$slug.bye', 'yes'); }\n}\n";
        $path = $this->scratch.'/'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE);
        foreach ($files + $extra as $name => $content) {
            $z->addFromString($prefix.$name, $content);
        }
        $z->close();

        return $path;
    }

    private function upload(string $zip, ?string $name = null)
    {
        return $this->actingAs($this->admin())->post(route('admin.plugins.upload'), ['plugin' => new UploadedFile($zip, $name ?? 'plugin.zip', 'application/zip', null, true)]);
    }

    public function test_valid_zip_installs_and_is_logged(): void
    {
        $this->upload($this->zip())->assertRedirect(route('admin.plugins.index'))->assertSessionHas('status');

        $this->assertFileExists($this->root.'/zipdemo/plugin.json');
        $this->assertFileExists($this->root.'/zipdemo/.tidemark-installed');
        $this->assertSame('uploaded', app(PluginManager::class)->manifest('zipdemo')['origin']);
        $this->assertTrue(ActivityLog::where('action', 'plugin.installed')->exists());
        $this->actingAs($this->admin())->get(route('admin.plugins.index'))->assertOk()->assertSee('arbitrary PHP')->assertSee('Zipdemo');
    }

    public function test_github_style_nested_root_installs(): void
    {
        $this->upload($this->zip(prefix: 'zipdemo-main/'))->assertSessionHas('status');

        $this->assertFileExists($this->root.'/zipdemo/src/Main.php');
    }

    public function test_zip_slip_is_rejected(): void
    {
        foreach (['../evil.php', '/abs/evil.php', 'src/../../evil.php', 'C:/evil.php'] as $bad) {
            $this->upload($this->zip(extra: [$bad => 'x']))->assertSessionHas('error');
        }
        $this->assertDirectoryDoesNotExist($this->root.'/zipdemo');
        $this->assertFileDoesNotExist($this->scratch.'/evil.php');
    }

    public function test_symlink_entries_are_rejected(): void
    {
        $path = $this->zip();
        $z = new ZipArchive;
        $z->open($path);
        $z->addFromString('src/link', '/etc/passwd');
        $z->setExternalAttributesName('src/link', ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $z->close();

        $this->upload($path)->assertSessionHas('error');
        $this->assertDirectoryDoesNotExist($this->root.'/zipdemo');
    }

    public function test_missing_manifest_is_rejected(): void
    {
        $this->upload($this->zip(withManifest: false))->assertSessionHas('error', fn ($m) => str_contains($m, 'No plugin.json'));
    }

    public function test_bad_slug_and_missing_keys_are_rejected(): void
    {
        $this->upload($this->zip(manifest: ['slug' => 'Bad Slug!']))->assertSessionHas('error', fn ($m) => str_contains($m, 'slug'));
        $this->upload($this->zip(manifest: ['provider' => 'Plugins\\Zipdemo\\Missing']))->assertSessionHas('error', fn ($m) => str_contains($m, 'missing from the archive'));
        $this->upload($this->zip(manifest: ['requires' => ['billing' => '>=99']]))->assertSessionHas('error', fn ($m) => str_contains($m, 'needs Tidemark'));
        $path = $this->zip();
        $z = new ZipArchive;
        $z->open($path);
        $z->addFromString('plugin.json', json_encode(['slug' => 'zipdemo']));
        $z->close();
        $this->upload($path)->assertSessionHas('error', fn ($m) => str_contains($m, 'required'));
    }

    public function test_oversize_and_zip_bomb_are_rejected(): void
    {
        config(['billing.plugin_upload.max_unpacked_mb' => 1]);
        $this->upload($this->zip(extra: ['big.bin' => str_repeat('0', 3 * 1024 * 1024)]))->assertSessionHas('error', fn ($m) => str_contains($m, 'unpacks to more'));

        config(['billing.plugin_upload.max_files' => 2]);
        $this->upload($this->zip(extra: ['a' => '1', 'b' => '2']))->assertSessionHas('error', fn ($m) => str_contains($m, 'more than 2 files'));

        config(['billing.plugin_upload.max_mb' => 1, 'billing.plugin_upload.max_files' => 5000, 'billing.plugin_upload.max_unpacked_mb' => 100]);
        $this->expectException(PluginInstallException::class);
        $big = $this->zip(extra: ['noise.bin' => random_bytes(1100000)]);
        app(PluginInstaller::class)->install($big);
    }

    public function test_upgrade_needs_confirmation_keeps_settings_and_backs_up(): void
    {
        $this->upload($this->zip(version: '1.0.0'));
        PluginRecord::create(['slug' => 'zipdemo', 'enabled' => true, 'version' => '1.0.0', 'installed_at' => now()]);
        Settings::set('plugin.zipdemo.api_key', 'abc');

        $response = $this->upload($this->zip(version: '1.1.0', extra: ['database/migrations/2099_01_01_000000_zipdemo_noop.php' => "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nreturn new class extends Migration { public function up(): void {} public function down(): void {} };\n"]));
        $response->assertRedirectContains('/admin/plugins/upload/');
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/zipdemo/plugin.json'));

        $confirm = $response->headers->get('Location');
        $this->get($confirm)->assertOk()->assertSee('1.0.0')->assertSee('1.1.0');
        $this->post($confirm)->assertRedirect(route('admin.plugins.index'))->assertSessionHas('status');

        $this->assertStringContainsString('1.1.0', File::get($this->root.'/zipdemo/plugin.json'));
        $this->assertTrue(PluginRecord::find('zipdemo')->enabled);
        $this->assertSame('1.1.0', PluginRecord::find('zipdemo')->version);
        $this->assertSame('abc', Settings::get('plugin.zipdemo.api_key'));
        $this->assertNotEmpty(glob(storage_path('app/plugin-backups/zipdemo-1.0.0-*')));
        $this->assertTrue(ActivityLog::where('action', 'plugin.upgraded')->exists());
    }

    public function test_failed_migration_rolls_back_files(): void
    {
        $this->upload($this->zip(version: '1.0.0'));
        PluginRecord::create(['slug' => 'zipdemo', 'enabled' => true, 'version' => '1.0.0', 'installed_at' => now()]);

        $bad = $this->zip(version: '2.0.0', extra: ['database/migrations/2099_01_01_000001_zipdemo_boom.php' => "<?php\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new \\RuntimeException('boom'); } };\n"]);
        try {
            app(PluginInstaller::class)->install($bad, true);
            $this->fail('expected failure');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
        }
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/zipdemo/plugin.json'));
        $this->assertSame('1.0.0', PluginRecord::find('zipdemo')->version);
    }

    public function test_same_or_lower_version_needs_explicit_confirmation(): void
    {
        $this->upload($this->zip(version: '2.0.0'));

        foreach (['2.0.0' => 'same', '1.0.0' => 'downgrade'] as $version => $relation) {
            try {
                app(PluginInstaller::class)->install($this->zip(version: $version));
                $this->fail('expected confirmation');
            } catch (PluginConfirmationRequired $e) {
                $this->assertSame($relation, $e->relation);
            }
        }
        $response = $this->upload($this->zip(version: '1.0.0'));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('older version');
        $this->post($response->headers->get('Location'));
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/zipdemo/plugin.json'));
        $this->assertTrue(ActivityLog::where('action', 'plugin.downgraded')->exists());
    }

    public function test_bundled_plugin_is_shadowed_from_extra_path_only_after_confirmation(): void
    {
        File::ensureDirectoryExists($this->root.'/zipdemo/src');
        File::put($this->root.'/zipdemo/plugin.json', json_encode(['slug' => 'zipdemo', 'name' => 'Zipdemo', 'version' => '1.0.0', 'namespace' => 'Plugins\\Zipdemo\\', 'provider' => 'Plugins\\Zipdemo\\Main']));
        config(['billing.plugins_extra_path' => $this->scratch.'/extra']);
        $this->resetManager();

        $r = $this->upload($this->zip(version: '1.5.0'));
        $r->assertRedirectContains('/admin/plugins/upload/');
        $this->get($r->headers->get('Location'))->assertSee('ships with Tidemark Billing');
        $this->post($r->headers->get('Location'));

        $this->assertFileExists($this->scratch.'/extra/zipdemo/plugin.json');
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/zipdemo/plugin.json'));
        $this->assertSame('1.5.0', app(PluginManager::class)->manifest('zipdemo')['version']);
    }

    public function test_remove_runs_uninstall_drops_settings_and_deletes_files(): void
    {
        $this->upload($this->zip('rmdemo'));
        Settings::set('plugin.rmdemo.key', 'v');
        PluginRecord::create(['slug' => 'rmdemo', 'enabled' => false, 'version' => '1.0.0', 'installed_at' => now()]);

        $this->actingAs($this->admin())->delete(route('admin.plugins.destroy', 'rmdemo'), ['drop_settings' => 1])->assertRedirect(route('admin.plugins.index'))->assertSessionHas('status');

        $this->assertDirectoryDoesNotExist($this->root.'/rmdemo');
        $this->assertNull(PluginRecord::find('rmdemo'));
        $this->assertNull(Settings::get('plugin.rmdemo.key'));
        $this->assertNull(Settings::get('plugin.rmdemo.bye')); // uninstall() hook ran before settings were dropped
        $this->assertNotEmpty(glob(storage_path('app/plugin-backups/rmdemo-*')));
        $this->assertTrue(ActivityLog::where('action', 'plugin.removed')->exists());
    }

    public function test_remove_keeps_settings_by_default_and_refuses_enabled_and_bundled(): void
    {
        $this->upload($this->zip('keepdemo'));
        Settings::set('plugin.keepdemo.key', 'v');
        PluginRecord::create(['slug' => 'keepdemo', 'enabled' => true, 'version' => '1.0.0', 'installed_at' => now()]);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.plugins.destroy', 'keepdemo'))->assertSessionHas('error');
        $this->assertDirectoryExists($this->root.'/keepdemo');

        PluginRecord::where('slug', 'keepdemo')->update(['enabled' => false]);
        $this->actingAs($admin)->delete(route('admin.plugins.destroy', 'keepdemo'))->assertSessionHas('status');
        $this->assertSame('v', Settings::get('plugin.keepdemo.key'));

        File::ensureDirectoryExists($this->root.'/handmade');
        File::put($this->root.'/handmade/plugin.json', json_encode(['slug' => 'handmade', 'name' => 'H', 'version' => '1', 'namespace' => 'Plugins\\Handmade\\', 'provider' => 'Plugins\\Handmade\\Main']));
        app(PluginManager::class)->refresh();
        $this->actingAs($admin)->delete(route('admin.plugins.destroy', 'handmade'))->assertSessionHas('error');
        $this->assertDirectoryExists($this->root.'/handmade');
    }

    public function test_upload_and_delete_are_blocked_when_disabled_by_env(): void
    {
        config(['billing.allow_plugin_upload' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.plugins.upload'), ['plugin' => new UploadedFile($this->zip(), 'p.zip', 'application/zip', null, true)])->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.plugins.destroy', 'whatever'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.plugins.index'))->assertOk()->assertSee('BILLING_ALLOW_PLUGIN_UPLOAD=false');
        $this->assertDirectoryDoesNotExist($this->root.'/zipdemo');
    }

    public function test_staff_and_customers_cannot_install_or_delete(): void
    {
        $zip = $this->zip();
        $staff = User::factory()->staff()->create();
        $file = fn () => new UploadedFile($zip, 'p.zip', 'application/zip', null, true);

        $this->actingAs($staff)->post(route('admin.plugins.upload'), ['plugin' => $file()])->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.plugins.destroy', 'zipdemo'))->assertForbidden();
        $this->actingAs($this->customer())->post(route('admin.plugins.upload'), ['plugin' => $file()])->assertForbidden();
        $this->actingAs($staff)->get(route('admin.plugins.index'))->assertOk()->assertDontSee('Install or upgrade a plugin');
        $this->assertDirectoryDoesNotExist($this->root.'/zipdemo');
    }

    public function test_non_zip_upload_is_rejected(): void
    {
        $f = $this->scratch.'/x.txt';
        File::put($f, 'hello');
        $this->upload($f, 'x.txt')->assertSessionHas('error');
        $bogus = $this->scratch.'/bogus.zip';
        File::put($bogus, 'not a zip');
        $this->upload($bogus, 'bogus.zip')->assertSessionHas('error', fn ($m) => str_contains($m, 'not a valid zip'));
    }

    public function test_unwritable_target_gives_instructions(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores file permissions');
        }
        chmod($this->root, 0555);
        try {
            $this->upload($this->zip())->assertSessionHas('error', fn ($m) => str_contains($m, 'not writable') && str_contains($m, 'chmod'));
        } finally {
            chmod($this->root, 0755);
        }
    }

    public function test_cli_install_and_remove(): void
    {
        $zip = $this->zip('clidemo');
        $this->artisan('plugin', ['action' => 'install', 'slug' => $zip])->assertSuccessful();
        $this->assertFileExists($this->root.'/clidemo/plugin.json');
        $this->artisan('plugin', ['action' => 'install', 'slug' => 'http://example.com/x.zip'])->assertFailed();
        $this->artisan('plugin', ['action' => 'remove', 'slug' => 'clidemo', '--force' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist($this->root.'/clidemo');
    }

    public function test_version_constraints(): void
    {
        $this->assertTrue(PluginInstaller::satisfies('0.1.0', '>=0.1'));
        $this->assertTrue(PluginInstaller::satisfies('1.4.0', '^1.2'));
        $this->assertFalse(PluginInstaller::satisfies('2.0.0', '^1.2'));
        $this->assertTrue(PluginInstaller::satisfies('1.2.5', '~1.2.3'));
        $this->assertFalse(PluginInstaller::satisfies('1.3.0', '~1.2.3'));
        $this->assertTrue(PluginInstaller::satisfies('1.5.0', '>=1.0 <2'));
        $this->assertTrue(PluginInstaller::satisfies('dev', '>=9'));
    }
}
