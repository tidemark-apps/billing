<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\Support\OpenApi\Builder;
use Illuminate\Console\Command;

/**
 * Regenerates resources/openapi.json from the route list and the metadata in app/Http/Controllers/Api/Support/OpenApi/.
 * Run it after adding or changing an API route; `--check` (used by the test suite and CI) fails when the file is stale.
 */
class ApiOpenapiCommand extends Command
{
    protected $signature = 'api:openapi {--check : Do not write; exit with an error if resources/openapi.json is out of date} {--path= : Write somewhere else}';

    protected $description = 'Generate resources/openapi.json from the API routes and their documentation metadata';

    public function handle(Builder $builder): int
    {
        try {
            $json = self::encode($builder->build());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $path = $this->option('path') ?: resource_path('openapi.json');

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $json) {
                $this->error('resources/openapi.json is out of date. Run: php artisan api:openapi');

                return self::FAILURE;
            }
            $this->info('resources/openapi.json is up to date.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $count = array_sum(array_map('count', json_decode($json, true)['paths']));
        $this->info("Wrote $path ($count operations).");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $spec */
    public static function encode(array $spec): string
    {
        return json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
