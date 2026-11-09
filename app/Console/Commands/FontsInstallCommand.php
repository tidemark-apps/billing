<?php

namespace App\Console\Commands;

use App\Services\PdfFonts;
use Illuminate\Console\Command;

class FontsInstallCommand extends Command
{
    protected $signature = 'billing:fonts:install
        {--pack=* : Pack to install: sc, tc, jp, kr (repeatable). Default: sc, jp and kr}
        {--remove : Remove the named packs (or all) instead}';

    protected $description = 'Download the optional CJK font packs that let PDFs show Chinese, Japanese and Korean text';

    public function handle(PdfFonts $fonts): int
    {
        $keys = $this->option('pack') ?: ($this->option('remove') ? array_keys($fonts->packs()) : (array) config('pdf-fonts.default'));
        foreach ($keys as $key) {
            if (! isset($fonts->packs()[$key])) {
                $this->error("Unknown pack \"{$key}\". Available: ".implode(', ', array_keys($fonts->packs())));

                return self::INVALID;
            }
        }

        $failed = false;
        foreach ($keys as $key) {
            $pack = $fonts->packs()[$key];
            if ($this->option('remove')) {
                $fonts->uninstall($key);
                $this->line("Removed {$pack['label']}.");

                continue;
            }
            $this->line(sprintf('Downloading %s (%s, about %.1f MB)…', $pack['family'], $pack['label'], ($pack['size'] ?? 0) / 1048576));
            try {
                $fonts->install($key);
                $this->info("Installed {$pack['family']}.");
            } catch (\Throwable $e) {
                $failed = true;
                $this->error($e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
