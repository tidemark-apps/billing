<?php

namespace Tests\Feature\Plugins;

use App\Models\PluginRecord;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginInstallException;
use App\Plugins\PluginManager;
use App\Plugins\PluginMigrationFailed;
use App\Plugins\PluginMigrationRunner;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use ZipArchive;

/** A failing plugin migration must not leave the database ahead of the (restored) code. */
class PluginMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = storage_path('framework/testing/plugin-mig-'.uniqid());
        $this->root = $this->scratch.'/plugins';
        File::ensureDirectoryExists($this->root);
        config(['billing.plugins_path' => $this->root, 'billing.plugins_extra_path' => null, 'billing.allow_plugin_upload' => true]);
        $this->resetManager();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        foreach (glob(storage_path('app/plugin-backups/migdemo-*')) ?: [] as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function resetManager(): void
    {
        $this->app->forgetInstance(PluginManager::class);
        $this->app->singleton(PluginManager::class);
        $this->app->forgetInstance(PluginInstaller::class);
    }

    /** Simulate MySQL: no transactional DDL, so only the down() rollback is available. */
    private function withoutTransactionalDdl(): void
    {
        $this->app->bind(PluginMigrationRunner::class, fn () => new class extends PluginMigrationRunner
        {
            protected function transactional(): bool
            {
                return false;
            }
        });
    }

    private function migration(string $table, bool $down = true, bool $fail = false): string
    {
        $up = $fail
            ? "throw new \\RuntimeException('boom in $table');"
            : "\\Illuminate\\Support\\Facades\\Schema::create('$table', fn (\$t) => \$t->id());";
        $downCode = $down ? "public function down(): void { \\Illuminate\\Support\\Facades\\Schema::dropIfExists('$table'); }" : '';

        return "<?php\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { $up } $downCode };\n";
    }

    /** @param  array<string, string>  $migrations  file => contents */
    private function zip(string $version, array $migrations = [], string $provider = 'Main', string $body = ''): string
    {
        $files = [
            'plugin.json' => json_encode(['slug' => 'migdemo', 'name' => 'Migdemo', 'version' => $version, 'namespace' => 'Plugins\\Migdemo\\', 'provider' => 'Plugins\\Migdemo\\'.$provider]),
            'src/'.$provider.'.php' => "<?php\nnamespace Plugins\\Migdemo;\nclass $provider extends \\App\\Plugins\\Plugin { $body }\n",
        ];
        foreach ($migrations as $name => $body) {
            $files['database/migrations/'.$name] = $body;
        }
        $path = $this->scratch.'/'.uniqid().'.zip';
        $z = new ZipArchive;
        $z->open($path, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();

        return $path;
    }

    private function installer(): PluginInstaller
    {
        return app(PluginInstaller::class);
    }

    /** Installed + enabled v1.0.0 with one migration (migdemo_one) already applied and a saved setting. */
    private function enabledV1(): void
    {
        $this->installer()->install($this->zip('1.0.0', ['2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one')]));
        app(PluginManager::class)->enable('migdemo');
        Settings::set('plugin.migdemo.api_key', 'abc');
        $this->assertTrue(Schema::hasTable('migdemo_one'));
    }

    private function migrationRows(): array
    {
        return DB::table('migrations')->where('migration', 'like', '%migdemo%')->orderBy('migration')->pluck('migration')->all();
    }

    private function assertV1Intact(): void
    {
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/migdemo/plugin.json'));
        $this->assertFileDoesNotExist($this->root.'/migdemo/database/migrations/2099_01_01_000002_migdemo_two.php');
        $record = PluginRecord::find('migdemo');
        $this->assertTrue($record->enabled);
        $this->assertSame('1.0.0', $record->version);
        $this->assertSame('abc', Settings::get('plugin.migdemo.api_key'));
        $this->assertTrue(Schema::hasTable('migdemo_one'));
        $this->assertSame(['2099_01_01_000001_migdemo_one'], $this->migrationRows());
    }

    private function badV2(bool $secondHasDown = true): string
    {
        return $this->zip('2.0.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two', $secondHasDown),
            '2099_01_01_000003_migdemo_three.php' => $this->migration('migdemo_three', fail: true),
        ]);
    }

    public function test_failed_upgrade_leaves_nothing_behind_with_transactional_ddl(): void
    {
        $this->enabledV1();

        try {
            $this->installer()->install($this->badV2(), true);
            $this->fail('expected failure');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
            $this->assertStringContainsString('boom in migdemo_three', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('migdemo_two'));
        $this->assertV1Intact();
    }

    public function test_failed_upgrade_rolls_back_ran_migrations_with_down_when_ddl_is_not_transactional(): void
    {
        $this->withoutTransactionalDdl();
        $this->enabledV1();

        try {
            $this->installer()->install($this->badV2(), true);
            $this->fail('expected failure');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
            $this->assertStringContainsString('Rolled back: 2099_01_01_000002_migdemo_two', $e->getMessage());
            $this->assertStringNotContainsString('COULD NOT', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('migdemo_two'));
        $this->assertV1Intact();

        // the migrations table is clean, so a corrected version installs fine afterwards
        $this->installer()->install($this->zip('2.1.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two'),
        ]), true);
        $this->assertTrue(Schema::hasTable('migdemo_two'));
        $this->assertSame('2.1.0', PluginRecord::find('migdemo')->version);
    }

    public function test_migration_without_down_is_reported_and_others_are_still_rolled_back(): void
    {
        $this->withoutTransactionalDdl();
        $this->enabledV1();
        $zip = $this->zip('2.0.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two', down: false),
            '2099_01_01_000003_migdemo_three.php' => $this->migration('migdemo_three', down: true),
            '2099_01_01_000004_migdemo_four.php' => $this->migration('migdemo_four', fail: true),
        ]);

        try {
            $this->installer()->install($zip, true);
            $this->fail('expected failure');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
            $this->assertStringContainsString('boom in migdemo_four', $e->getMessage(), 'the original error is not hidden');
            $this->assertStringContainsString('Rolled back: 2099_01_01_000003_migdemo_three', $e->getMessage());
            $this->assertStringContainsString('COULD NOT be rolled back', $e->getMessage());
            $this->assertStringContainsString('2099_01_01_000002_migdemo_two (it has no down() method)', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('migdemo_three'));
        $this->assertTrue(Schema::hasTable('migdemo_two'), 'cannot be reverted without down()');
        $this->assertContains('2099_01_01_000002_migdemo_two', $this->migrationRows(), 'row kept: its table still exists');
        $this->assertSame('1.0.0', PluginRecord::find('migdemo')->version);
        $this->assertStringContainsString('1.0.0', File::get($this->root.'/migdemo/plugin.json'));
    }

    public function test_down_that_throws_is_reported(): void
    {
        $this->withoutTransactionalDdl();
        $this->enabledV1();
        $throwingDown = "<?php\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create('migdemo_two', fn (\$t) => \$t->id()); } public function down(): void { throw new \\RuntimeException('cannot drop'); } };\n";

        try {
            $this->installer()->install($this->zip('2.0.0', [
                '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
                '2099_01_01_000002_migdemo_two.php' => $throwingDown,
                '2099_01_01_000003_migdemo_three.php' => $this->migration('migdemo_three', fail: true),
            ]), true);
            $this->fail('expected failure');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('boom in migdemo_three', $e->getMessage());
            $this->assertStringContainsString('down() failed: cannot drop', $e->getMessage());
        }
    }

    public function test_first_time_enable_failure_rolls_back_and_creates_no_record(): void
    {
        $this->withoutTransactionalDdl();
        $this->installer()->install($this->zip('1.0.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two', fail: true),
        ]));

        try {
            app(PluginManager::class)->enable('migdemo');
            $this->fail('expected failure');
        } catch (PluginMigrationFailed $e) {
            $this->assertStringContainsString('boom in migdemo_two', $e->getMessage());
            $this->assertSame(['2099_01_01_000001_migdemo_one'], $e->rolledBack);
            $this->assertSame([], $e->notRolledBack);
        }

        $this->assertFalse(Schema::hasTable('migdemo_one'));
        $this->assertSame([], $this->migrationRows());
        $this->assertNull(PluginRecord::find('migdemo'));
    }

    public function test_first_time_enable_failure_with_transactional_ddl(): void
    {
        $this->installer()->install($this->zip('1.0.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two', fail: true),
        ]));

        $this->expectException(PluginMigrationFailed::class);
        try {
            app(PluginManager::class)->enable('migdemo');
        } finally {
            $this->assertFalse(Schema::hasTable('migdemo_one'));
            $this->assertSame([], $this->migrationRows());
            $this->assertNull(PluginRecord::find('migdemo'));
        }
    }

    public function test_install_hook_failure_on_first_enable_rolls_migrations_back(): void
    {
        $this->installer()->install($this->zip('1.0.0', ['2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one')], 'HookMain', "public function install(): void { throw new \\RuntimeException('hook broke'); }"));

        try {
            app(PluginManager::class)->enable('migdemo');
            $this->fail('expected failure');
        } catch (PluginMigrationFailed $e) {
            $this->assertStringContainsString('hook broke', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('migdemo_one'));
        $this->assertSame([], $this->migrationRows());
        $this->assertNull(PluginRecord::find('migdemo'));
    }

    public function test_apply_update_flow_failure_reports_and_keeps_state(): void
    {
        $this->withoutTransactionalDdl();
        $this->enabledV1();
        // files were replaced by hand with 2.0.0; the record still says 1.0.0 -> "Apply update"
        File::put($this->root.'/migdemo/plugin.json', json_encode(['slug' => 'migdemo', 'name' => 'Migdemo', 'version' => '2.0.0', 'namespace' => 'Plugins\\Migdemo\\', 'provider' => 'Plugins\\Migdemo\\Main']));
        File::put($this->root.'/migdemo/database/migrations/2099_01_01_000002_migdemo_two.php', $this->migration('migdemo_two'));
        File::put($this->root.'/migdemo/database/migrations/2099_01_01_000003_migdemo_three.php', $this->migration('migdemo_three', fail: true));
        $this->resetManager();

        $this->actingAs($this->admin())->post(route('admin.plugins.enable', 'migdemo'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'boom in migdemo_three') && str_contains($m, 'Rolled back: 2099_01_01_000002_migdemo_two'));

        $this->assertFalse(Schema::hasTable('migdemo_two'));
        $this->assertSame(['2099_01_01_000001_migdemo_one'], $this->migrationRows());
        $this->assertSame('1.0.0', PluginRecord::find('migdemo')->version);
        $this->assertTrue(PluginRecord::find('migdemo')->enabled);
    }

    public function test_cli_enable_failure_prints_the_report(): void
    {
        $this->installer()->install($this->zip('1.0.0', ['2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one', fail: true)]));

        $this->artisan('plugin', ['action' => 'enable', 'slug' => 'migdemo'])->expectsOutputToContain('boom in migdemo_one')->assertFailed();
    }

    public function test_successful_upgrade_runs_migrations_unchanged(): void
    {
        $this->enabledV1();

        $result = $this->installer()->install($this->zip('1.1.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one'),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two'),
        ]), true);

        $this->assertTrue($result['migrated']);
        $this->assertSame([], $result['warnings']);
        $this->assertTrue(Schema::hasTable('migdemo_two'));
        $this->assertSame(['2099_01_01_000001_migdemo_one', '2099_01_01_000002_migdemo_two'], $this->migrationRows());
        $this->assertSame('1.1.0', PluginRecord::find('migdemo')->version);
        $this->assertSame('abc', Settings::get('plugin.migdemo.api_key'));
    }

    public function test_install_warns_about_migrations_without_down(): void
    {
        $result = $this->installer()->install($this->zip('1.0.0', [
            '2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one', down: false),
            '2099_01_01_000002_migdemo_two.php' => $this->migration('migdemo_two'),
        ]));

        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('2099_01_01_000001_migdemo_one.php', $result['warnings'][0]);
        $this->assertStringNotContainsString('migdemo_two', $result['warnings'][0]);

        $zip = $this->zip('1.0.1', ['2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one', down: false)]);
        $this->actingAs($this->admin())->post(route('admin.plugins.upload'), ['plugin' => new UploadedFile($zip, 'p.zip', 'application/zip', null, true)])->assertRedirectContains('/admin/plugins/upload/');
        $zip = $this->zip('1.0.2', ['2099_01_01_000001_migdemo_one.php' => $this->migration('migdemo_one', down: false)]);
        $out = new BufferedOutput;
        $code = Artisan::call('plugin', ['action' => 'install', 'slug' => $zip, '--force' => true], $out);
        $text = $out->fetch();
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('no down()', $text);
    }
}
