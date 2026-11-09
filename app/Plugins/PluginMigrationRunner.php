<?php

namespace App\Plugins;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs a plugin's migrations so that a failure leaves nothing behind, as far as the database allows:
 *
 *  - On databases with transactional DDL (SQLite, PostgreSQL) the whole run is one transaction, so a failure undoes everything.
 *  - Everywhere (and this is all MySQL/MariaDB has, as DDL there commits implicitly) the migrations that completed during
 *    this run are found by comparing the `migrations` table before and after, and reverted in reverse order with their down().
 *    The migration that failed itself may be partly applied on MySQL; that cannot be undone.
 *  - A migration without down(), or whose down() throws, is reported by name, and its row stays in `migrations` because
 *    its changes are still in the database (removing the row would make a later re-run fail on "table already exists").
 */
class PluginMigrationRunner
{
    /**
     * @param  array<int, string>  $paths  absolute migration directories
     * @return array<int, string> names of the migrations that ran
     *
     * @throws PluginMigrationFailed
     */
    public function run(array $paths): array
    {
        $paths = array_values(array_filter($paths, 'is_dir'));
        if (! $paths) {
            return [];
        }
        $names = array_keys($this->migrator()->getMigrationFiles($paths));
        $before = $this->ranAmong($names);

        try {
            $this->migrate($paths);
        } catch (\Throwable $e) {
            throw $this->abort(array_values(array_diff($this->ranAmong($names), $before)), $paths, $e, 'The plugin migrations failed');
        }

        return array_values(array_diff($this->ranAmong($names), $before));
    }

    /**
     * Revert the given completed migrations (reverse order) and build the exception that reports it.
     *
     * @param  array<int, string>  $ran  migrations that completed in this operation
     * @param  array<int, string>  $paths
     */
    public function abort(array $ran, array $paths, \Throwable $cause, string $what): PluginMigrationFailed
    {
        [$rolledBack, $failed] = $this->revert($ran, $paths);
        $parts = [$what.': '.Str::limit($cause->getMessage(), 300).'.'];

        if ($rolledBack) {
            $parts[] = 'Rolled back: '.implode(', ', $rolledBack).'.';
        } elseif (! $failed) {
            $parts[] = $this->transactional()
                ? 'The database transaction was rolled back, nothing was left behind.'
                : 'No migration had completed, so there was nothing to roll back (the one that failed may be partly applied: this database cannot undo DDL).';
        }
        if ($failed) {
            $list = [];
            foreach ($failed as $name => $why) {
                $list[] = "$name ($why)";
            }
            $parts[] = 'COULD NOT be rolled back, undo by hand: '.implode('; ', $list).'.';
        }

        return new PluginMigrationFailed(implode(' ', $parts), $rolledBack, $failed, $cause);
    }

    /**
     * @param  array<int, string>  $names
     * @param  array<int, string>  $paths
     * @return array{0: array<int, string>, 1: array<string, string>} rolled back, and name => reason for the ones that could not be
     */
    public function revert(array $names, array $paths): array
    {
        $migrator = $this->migrator();
        $files = $paths ? $migrator->getMigrationFiles(array_values(array_filter($paths, 'is_dir'))) : [];
        $names = array_values($names);
        rsort($names);

        $rolledBack = $failed = [];
        foreach ($names as $name) {
            try {
                if (! isset($files[$name])) {
                    throw new MigrationNotRevertible('its file is no longer available');
                }
                $migration = (fn () => $this->resolvePath($files[$name]))->call($migrator);
                if (! method_exists($migration, 'down')) {
                    throw new MigrationNotRevertible('it has no down() method');
                }
                $down = fn () => $migration->down();
                if ($this->transactional() && ($migration->withinTransaction ?? true)) {
                    DB::transaction($down);
                } else {
                    $down();
                }
                $migrator->getRepository()->delete((object) ['migration' => $name]);
                $rolledBack[] = $name;
            } catch (\Throwable $e) {
                $failed[$name] = $e instanceof MigrationNotRevertible ? $e->getMessage() : 'down() failed: '.Str::limit($e->getMessage(), 150);
            }
        }

        return [$rolledBack, $failed];
    }

    /**
     * Migration files in the given directories that define no down() method (a plain text check: nothing is executed).
     *
     * @param  array<int, string>  $paths
     * @return array<int, string> file names
     */
    public static function missingDown(array $paths): array
    {
        $missing = [];
        foreach ($paths as $dir) {
            foreach (glob(rtrim($dir, '/\\').'/*.php') ?: [] as $file) {
                if (! preg_match('/function\s+down\s*\(/i', (string) file_get_contents($file))) {
                    $missing[] = basename($file);
                }
            }
        }
        sort($missing);

        return $missing;
    }

    private function migrate(array $paths): void
    {
        $run = function () use ($paths): void {
            foreach ($paths as $path) {
                if (Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]) !== 0) {
                    throw new \RuntimeException(trim(Artisan::output()) ?: 'migrate exited with an error');
                }
            }
        };
        $this->transactional() ? DB::transaction($run) : $run();
    }

    protected function transactional(): bool
    {
        try {
            // Laravel's own flag omits SQLite, which does roll DDL back with the transaction.
            return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql', 'sqlsrv'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function ranAmong(array $names): array
    {
        $migrator = $this->migrator();
        if (! $migrator->repositoryExists()) {
            return [];
        }

        return array_values(array_intersect($migrator->getRepository()->getRan(), $names));
    }

    private function migrator(): Migrator
    {
        return app('migrator');
    }
}

/** @internal */
final class MigrationNotRevertible extends \RuntimeException {}
