<?php

namespace App\Plugins;

use App\Models\PluginRecord;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Installs, upgrades and removes plugins from a .zip archive.
 *
 * SECURITY: a plugin is arbitrary PHP that runs with full access to the app and its database. The checks here
 * (zip-slip, symlinks, size limits, manifest validation) protect against a malformed or hostile archive, not against
 * a malicious plugin. Only ever install plugins from sources you trust.
 */
class PluginInstaller
{
    public function __construct(private readonly PluginManager $plugins) {}

    public static function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public static function uploadEnabled(): bool
    {
        return (bool) config('billing.allow_plugin_upload', true);
    }

    /** Where uploaded plugins go: the extra path (Docker /data/plugins) if set, else the main plugins path. */
    public function targetRoot(): string
    {
        return rtrim((string) (config('billing.plugins_extra_path') ?: config('billing.plugins_path')), '/\\');
    }

    public function targetRootWritable(): bool
    {
        $root = $this->targetRoot();

        return is_dir($root) ? is_writable($root) : is_writable(dirname($root));
    }

    public function backupRoot(): string
    {
        return storage_path('app/plugin-backups');
    }

    /** Human-readable help for a folder the web server cannot write to. */
    public function unwritableHelp(string $dir): string
    {
        $user = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;
        $who = $user ? "the web server user ($user)" : 'the web server user';

        return "The plugin folder $dir is not writable by $who. On shared hosting set it to 0755 (or 0775) with your file manager or "
            .'`chmod -R u+rwX '.$dir.'` over SSH; with your own server `chown -R '.($user ?: 'www-data').' '.$dir.'`. '
            .'You can also point BILLING_EXTRA_PLUGINS_PATH at a writable folder, or upload the plugin folder by FTP / copy it in by hand and press Enable.';
    }

    /**
     * Install or upgrade from a zip on disk.
     *
     * @return array{slug: string, name: string, version: string, previous: ?string, action: string, backup: ?string, migrated: bool, path: string, warnings: array<int, string>}
     *
     * @throws PluginInstallException
     * @throws PluginConfirmationRequired when the slug is already installed and $confirmed is false
     */
    public function install(string $zipPath, bool $confirmed = false): array
    {
        $work = $this->tempDir();
        try {
            $package = $this->unpack($zipPath, $work);
            $manifest = $package['manifest'];
            $slug = $manifest['slug'];
            $existing = $this->plugins->manifest($slug);

            $relation = 'new';
            if ($existing) {
                $cmp = version_compare((string) ($existing['version'] ?? '0'), (string) $manifest['version']);
                $relation = $cmp < 0 ? 'upgrade' : ($cmp === 0 ? 'same' : 'downgrade');
                if (! $confirmed) {
                    throw new PluginConfirmationRequired($slug, $manifest['name'], (string) ($existing['version'] ?? '?'), $manifest['version'], $relation, ($existing['origin'] ?? 'bundled') === 'bundled');
                }
            }

            [$root, $replace] = $this->resolveTarget($slug, $existing);
            $this->assertWritable($root);

            return $this->place($package['dir'], $manifest, $root, $replace, $existing, $relation);
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Validate the zip and report what installing it would do, without changing anything. Null when it is a fresh install. */
    public function preview(string $zipPath): ?PluginConfirmationRequired
    {
        $work = $this->tempDir();
        try {
            $manifest = $this->unpack($zipPath, $work)['manifest'];
            $existing = $this->plugins->manifest($manifest['slug']);
            if (! $existing) {
                return null;
            }
            $cmp = version_compare((string) ($existing['version'] ?? '0'), (string) $manifest['version']);

            return new PluginConfirmationRequired($manifest['slug'], $manifest['name'], (string) ($existing['version'] ?? '?'), $manifest['version'], $cmp < 0 ? 'upgrade' : ($cmp === 0 ? 'same' : 'downgrade'), ($existing['origin'] ?? 'bundled') === 'bundled');
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Remove an uploaded plugin: uninstall() hook, optional settings, files, plugin record.
     *
     * @throws PluginInstallException
     */
    public function remove(string $slug, bool $dropSettings = false): void
    {
        $manifest = $this->plugins->manifest($slug) ?? throw new PluginInstallException("Plugin [$slug] is not installed.");
        if (($manifest['origin'] ?? 'bundled') !== 'uploaded') {
            throw new PluginInstallException(($manifest['name'] ?? $slug).' ships with the release (or was copied in by hand), so it cannot be deleted from here. You can disable it, or delete the folder '.$manifest['path'].' yourself.');
        }
        if ($this->plugins->isEnabled($slug)) {
            throw new PluginInstallException('Disable the plugin before deleting it.');
        }
        $path = $manifest['path'];
        if (! is_writable(dirname($path)) || ! is_writable($path)) {
            throw new PluginInstallException($this->unwritableHelp(dirname($path)));
        }

        try {
            $this->plugins->instance($slug)?->uninstall();
        } catch (\Throwable $e) {
            throw new PluginInstallException('The plugin\'s uninstall hook failed, so nothing was deleted: '.$e->getMessage());
        }

        $this->backup($path, $slug, (string) ($manifest['version'] ?? 'unknown'));
        if ($dropSettings) {
            Setting::where('key', 'like', 'plugin.'.$slug.'.%')->delete();
            Settings::flush();
        }
        $this->deleteTree($path);
        if (is_dir($path)) {
            throw new PluginInstallException('Could not delete all plugin files from '.$path.'. '.$this->unwritableHelp(dirname($path)));
        }
        PluginRecord::where('slug', $slug)->delete();
        $this->plugins->refresh();
    }

    /**
     * Download a zip over https into a temp file (size capped). Caller deletes the file.
     *
     * @throws PluginInstallException
     */
    public function download(string $url): string
    {
        if (! preg_match('#^https://#i', $url)) {
            throw new PluginInstallException('Only https:// URLs are accepted.');
        }
        $max = $this->maxBytes();
        $tmp = tempnam(sys_get_temp_dir(), 'plg');
        try {
            $response = Http::timeout(120)->withOptions([
                'sink' => $tmp,
                'allow_redirects' => ['max' => 5, 'protocols' => ['https']],
                'progress' => function ($total, $downloaded) use ($max): void {
                    if ($downloaded > $max || $total > $max) {
                        throw new \RuntimeException('too large');
                    }
                },
            ])->get($url);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw new PluginInstallException(str_contains($e->getMessage(), 'too large') ? 'The download is larger than the '.config('billing.plugin_upload.max_mb').' MB limit.' : 'Download failed: '.Str::limit($e->getMessage(), 200));
        }
        if (! $response->successful() || filesize($tmp) > $max) {
            @unlink($tmp);
            throw new PluginInstallException('Download failed (HTTP '.$response->status().') or the file is too large.');
        }

        return $tmp;
    }

    public function maxBytes(): int
    {
        return max(1, (int) config('billing.plugin_upload.max_mb', 16)) * 1024 * 1024;
    }

    // ---- unpacking ------------------------------------------------------------------------------------------------

    /**
     * Validate the archive and extract the plugin to $work/plugin.
     *
     * @return array{manifest: array<string, mixed>, dir: string}
     */
    private function unpack(string $zipPath, string $work): array
    {
        if (! self::zipAvailable()) {
            throw new PluginInstallException('The PHP zip extension (ZipArchive) is not installed, so archives cannot be unpacked. Enable ext-zip, or install the plugin by hand: unzip it so that plugin.json sits in plugins/<slug>/ (or run `php artisan plugin install <file.zip>` on a machine that has it), then press Enable.');
        }
        if (! is_file($zipPath)) {
            throw new PluginInstallException('The uploaded file could not be read.');
        }
        if (filesize($zipPath) > $this->maxBytes()) {
            throw new PluginInstallException('The archive is larger than the '.config('billing.plugin_upload.max_mb').' MB limit.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new PluginInstallException('That file is not a valid zip archive.');
        }

        try {
            $maxFiles = (int) config('billing.plugin_upload.max_files', 5000);
            $maxUnpacked = (int) config('billing.plugin_upload.max_unpacked_mb', 100) * 1024 * 1024;
            if ($zip->numFiles > $maxFiles) {
                throw new PluginInstallException("The archive contains more than $maxFiles files.");
            }

            $entries = [];
            $declared = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                $this->assertSafeName($name);
                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                    continue;
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                if ($opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000)) {
                    throw new PluginInstallException("The archive contains a symbolic link ($name), which is not allowed.");
                }
                $declared += (int) ($stat['size'] ?? 0);
                if ($declared > $maxUnpacked) {
                    throw new PluginInstallException('The archive unpacks to more than '.config('billing.plugin_upload.max_unpacked_mb').' MB.');
                }
                $entries[$i] = $name;
            }

            $prefix = $this->findRoot($entries);
            $dir = $work.'/plugin';
            File::ensureDirectoryExists($dir);
            $written = 0;
            foreach ($entries as $i => $name) {
                if (! str_starts_with($name, $prefix)) {
                    continue;
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || $rel === self::marker()) {
                    continue;
                }
                $dest = $dir.'/'.rtrim($rel, '/');
                if (str_ends_with($name, '/')) {
                    File::ensureDirectoryExists($dest);

                    continue;
                }
                File::ensureDirectoryExists(dirname($dest));
                $in = $zip->getStream($name);
                $out = $in ? fopen($dest, 'wb') : false;
                if (! $in || ! $out) {
                    throw new PluginInstallException("Could not unpack $name.");
                }
                while (! feof($in)) {
                    $chunk = fread($in, 65536);
                    $written += strlen((string) $chunk);
                    if ($written > $maxUnpacked) {
                        fclose($in);
                        fclose($out);
                        throw new PluginInstallException('The archive unpacks to more than '.config('billing.plugin_upload.max_unpacked_mb').' MB.');
                    }
                    fwrite($out, (string) $chunk);
                }
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }

        return ['manifest' => $this->validate($dir), 'dir' => $dir];
    }

    private static function marker(): string
    {
        return PluginManager::UPLOAD_MARKER;
    }

    private function assertSafeName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
            || in_array('..', explode('/', $name), true)) {
            throw new PluginInstallException('The archive contains an unsafe path ('.Str::limit(str_replace("\0", '', $name), 80).') and was rejected.');
        }
    }

    /**
     * plugin.json must be at the zip root or inside the single top-level folder (GitHub-style archives).
     *
     * @param  array<int, string>  $entries
     */
    private function findRoot(array $entries): string
    {
        $names = array_values($entries);
        if (in_array('plugin.json', $names, true)) {
            return '';
        }
        $tops = [];
        foreach ($names as $n) {
            $tops[explode('/', $n)[0]] = true;
        }
        if (count($tops) === 1) {
            $top = array_key_first($tops).'/';
            if (in_array($top.'plugin.json', $names, true)) {
                return $top;
            }
        }
        throw new PluginInstallException('No plugin.json found. It must sit at the top of the zip, or inside a single top-level folder.');
    }

    /** @return array<string, mixed> */
    private function validate(string $dir): array
    {
        $manifest = json_decode((string) file_get_contents($dir.'/plugin.json'), true);
        if (! is_array($manifest)) {
            throw new PluginInstallException('plugin.json is not valid JSON.');
        }
        foreach (['slug', 'name', 'version', 'namespace', 'provider'] as $key) {
            if (! isset($manifest[$key]) || ! is_string($manifest[$key]) || trim($manifest[$key]) === '') {
                throw new PluginInstallException("plugin.json is missing the required \"$key\" key.");
            }
        }
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $manifest['slug'])) {
            throw new PluginInstallException('The slug must be lowercase letters, numbers and dashes (and start with a letter or number).');
        }
        if (! preg_match('/^\d+(\.\d+){0,3}([-+][0-9A-Za-z.-]+)?$/', $manifest['version'])) {
            throw new PluginInstallException('The version "'.$manifest['version'].'" is not valid. Use something like 1.2.0.');
        }
        $ident = '[A-Za-z_][A-Za-z0-9_]*';
        if (! preg_match("/^$ident(\\\\$ident)*\\\\?$/", $manifest['namespace']) || ! preg_match("/^$ident(\\\\$ident)*$/", $manifest['provider'])) {
            throw new PluginInstallException('The namespace or provider in plugin.json is not a valid PHP name.');
        }
        $ns = rtrim($manifest['namespace'], '\\').'\\';
        if (preg_match('/^(App|Illuminate|Symfony|Laravel)\\\\/i', $ns)) {
            throw new PluginInstallException('The plugin namespace must not live inside the app\'s or a framework namespace.');
        }
        if (! str_starts_with($manifest['provider'], $ns)) {
            throw new PluginInstallException('The provider class must be inside the plugin\'s namespace.');
        }
        $file = $dir.'/src/'.str_replace('\\', '/', substr($manifest['provider'], strlen($ns))).'.php';
        if (! is_file($file)) {
            throw new PluginInstallException('The provider class file src/'.str_replace('\\', '/', substr($manifest['provider'], strlen($ns))).'.php is missing from the archive.');
        }
        $needs = $manifest['requires']['billing'] ?? null;
        if (is_string($needs) && ! self::satisfies((string) config('billing.version'), $needs)) {
            throw new PluginInstallException('This plugin needs Tidemark Billing '.$needs.' but you are running '.config('billing.version').'.');
        }

        return $manifest;
    }

    /** Minimal constraint check: ">=0.1", ">=1.0 <2", "^1.2", "~1.2", "1.*", comma or space separated. Unknown/dev versions pass. */
    public static function satisfies(string $version, string $constraint): bool
    {
        if (! preg_match('/^\d/', $version)) {
            return true;
        }
        foreach (preg_split('/[\s,]+/', trim($constraint)) as $clause) {
            if ($clause === '' || $clause === '*') {
                continue;
            }
            if (! preg_match('/^(>=|<=|!=|==|>|<|=|\^|~)?v?(\d+(?:\.\d+)*)(\.\*)?$/', $clause, $m)) {
                return false;
            }
            [$op, $want] = [$m[1] ?: '=', $m[2]];
            if (($m[3] ?? '') === '.*') {
                $op = '^';
            }
            $ok = match ($op) {
                '^' => version_compare($version, $want, '>=') && version_compare($version, self::bump($want, 0), '<'),
                '~' => version_compare($version, $want, '>=') && version_compare($version, self::bump($want, count(explode('.', $want)) > 1 ? count(explode('.', $want)) - 2 : 0), '<'),
                '=', '==' => version_compare($version, $want, '=='),
                '!=' => version_compare($version, $want, '!='),
                default => version_compare($version, $want, $op),
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    private static function bump(string $v, int $index): string
    {
        $parts = array_map('intval', explode('.', $v));
        $parts = array_slice(array_pad($parts, $index + 1, 0), 0, $index + 1);
        $parts[$index]++;

        return implode('.', $parts);
    }

    // ---- placing files --------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array{0: string, 1: ?string} target root and, when an uploaded/bundled folder is replaced in place, its path
     */
    private function resolveTarget(string $slug, ?array $existing): array
    {
        $root = $this->targetRoot();
        if ($existing) {
            $existingRoot = rtrim(dirname($existing['path']), '/\\');
            // An uploaded plugin is replaced where it lives. A bundled one is shadowed from the extra path when there is one
            // (the extra path wins at discovery); otherwise its folder in plugins/ is replaced (after a backup).
            if (($existing['origin'] ?? '') === 'uploaded' || $existingRoot === $root) {
                return [$existingRoot, $existing['path']];
            }
        }

        return [$root, null];
    }

    private function assertWritable(string $root): void
    {
        if (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new PluginInstallException($this->unwritableHelp($root));
        }
        if (! is_writable($root)) {
            throw new PluginInstallException($this->unwritableHelp($root));
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>|null  $existing
     * @return array{slug: string, name: string, version: string, previous: ?string, action: string, backup: ?string, migrated: bool, path: string, warnings: array<int, string>}
     */
    private function place(string $source, array $manifest, string $root, ?string $replace, ?array $existing, string $relation): array
    {
        $slug = $manifest['slug'];
        $final = $root.'/'.$slug;
        $incoming = $root.'/.incoming-'.$slug.'-'.Str::random(8);
        $old = $root.'/.old-'.$slug.'-'.Str::random(8);

        // Another folder at the final path that is not the plugin we are replacing would be clobbered: refuse.
        if (is_dir($final) && $final !== $replace) {
            throw new PluginInstallException("The folder $final already exists and does not belong to this plugin. Remove or rename it first.");
        }

        $backup = null;
        $moved = false;
        try {
            File::copyDirectory($source, $incoming);
            file_put_contents($incoming.'/'.self::marker(), json_encode(['slug' => $slug, 'version' => $manifest['version'], 'installed_at' => now()->toIso8601String()]));

            if ($replace !== null && is_dir($replace)) {
                $backup = $this->backup($replace, $slug, (string) ($existing['version'] ?? 'unknown'));
                if (! @rename($replace, $old)) {
                    throw new PluginInstallException($this->unwritableHelp($root));
                }
                $moved = true;
            }
            if (! @rename($incoming, $final)) {
                throw new PluginInstallException('Could not move the plugin into place. '.$this->unwritableHelp($root));
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($incoming);
            if ($moved && ! is_dir($final)) {
                @rename($old, $replace);
            }
            throw $e instanceof PluginInstallException ? $e : new PluginInstallException('Install failed: '.$e->getMessage());
        }

        $this->plugins->refresh();
        $migrated = false;
        $record = PluginRecord::where('slug', $slug)->first();
        $original = $record?->only(['enabled', 'version', 'installed_at']);
        $migrationPaths = is_dir($final.'/database/migrations') ? [$final.'/database/migrations'] : [];
        $ran = [];
        try {
            if ($record?->enabled && $migrationPaths) {
                $migrated = true;
                $ran = app(PluginMigrationRunner::class)->run($migrationPaths); // rolls its own work back on failure
            }
            $record?->update(['version' => $manifest['version']]);
        } catch (\Throwable $e) {
            // Undo what ran (while the new files, and so the migration classes, still exist), then put the old files and record back.
            $failure = $e instanceof PluginMigrationFailed ? $e : app(PluginMigrationRunner::class)->abort($ran, $migrationPaths, $e, 'Updating the plugin failed');
            if ($original !== null) {
                try {
                    PluginRecord::where('slug', $slug)->update($original);
                } catch (\Throwable) {
                }
            }
            File::deleteDirectory($final);
            if ($moved) {
                @rename($old, $replace);
            }
            $this->plugins->refresh();
            throw new PluginInstallException('The plugin\'s migrations failed, so the previous version was restored. '.$failure->getMessage());
        }
        if ($moved) {
            File::deleteDirectory($old);
        }

        return [
            'slug' => $slug,
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'previous' => $existing['version'] ?? null,
            'action' => match ($relation) {
                'upgrade' => 'upgraded', 'same' => 'reinstalled', 'downgrade' => 'downgraded', default => 'installed',
            },
            'backup' => $backup,
            'migrated' => $migrated,
            'path' => $final,
            'warnings' => $this->migrationWarnings($migrationPaths),
        ];
    }

    /**
     * Non-blocking notices about a plugin's migrations.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function migrationWarnings(array $paths): array
    {
        $missing = PluginMigrationRunner::missingDown($paths);

        return $missing ? ['These migrations have no down() method, so they cannot be rolled back if an upgrade fails or the plugin is later reverted: '.implode(', ', $missing).'.'] : [];
    }

    private function backup(string $path, string $slug, string $version): string
    {
        $dest = $this->backupRoot().'/'.$slug.'-'.preg_replace('/[^0-9A-Za-z.-]/', '_', $version).'-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        File::ensureDirectoryExists(dirname($dest));
        File::copyDirectory($path, $dest);

        return $dest;
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }
        File::deleteDirectory($path);
    }

    private function tempDir(): string
    {
        $dir = storage_path('app/plugin-tmp/'.Str::random(16));
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
