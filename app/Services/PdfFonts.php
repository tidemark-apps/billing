<?php

namespace App\Services;

use App\Support\Settings;
use FontLib\Font;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CJK support for PDFs. dompdf cannot fall back per glyph, so when a document contains Chinese, Japanese or Korean
 * text, each run of CJK characters is wrapped in a span that selects an installed Noto Sans pack (see config/pdf-fonts.php).
 * Documents without CJK text are never touched, so Latin PDFs render exactly as before.
 */
class PdfFonts
{
    private const HANGUL = '\x{1100}-\x{11FF}\x{3130}-\x{318F}\x{A960}-\x{A97F}\x{AC00}-\x{D7FF}\x{FFA0}-\x{FFDC}';

    private const KANA = '\x{3040}-\x{30FF}\x{31F0}-\x{31FF}\x{FF66}-\x{FF9F}';

    private const HAN = '\x{2E80}-\x{2FDF}\x{3005}\x{3007}\x{3021}-\x{3029}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{20000}-\x{2FA1F}';

    /** CJK punctuation, full-width forms, brackets: no Latin font has them, so they join the neighbouring run. */
    private const MARKS = '\x{3000}-\x{303F}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF65}\x{FFE0}-\x{FFEF}';

    /** True when the text contains any Chinese, Japanese or Korean character. */
    public function hasCjk(string $text): bool
    {
        return (bool) preg_match('/['.self::HANGUL.self::KANA.self::HAN.']/u', $text);
    }

    /** @return list<string> which of 'han', 'kana', 'hangul' occur in the text */
    public function scriptsIn(string $text): array
    {
        $found = [];
        foreach (['han' => self::HAN, 'kana' => self::KANA, 'hangul' => self::HANGUL] as $name => $class) {
            if (preg_match('/['.$class.']/u', $text)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** @param  iterable<string|null>  $texts */
    public function anyCjk(iterable $texts): bool
    {
        foreach ($texts as $t) {
            if (is_string($t) && $t !== '' && $this->hasCjk($t)) {
                return true;
            }
        }

        return false;
    }

    /** Pack keys whose font file is present and registered. @return list<string> */
    public function installed(): array
    {
        $out = [];
        foreach (array_keys($this->packs()) as $key) {
            if (is_file($this->path($key)) && isset($this->registered()[strtolower($this->packs()[$key]['family'])])) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public function packs(): array
    {
        return (array) config('pdf-fonts.packs', []);
    }

    public function dir(): string
    {
        return rtrim((string) config('pdf-fonts.work_dir'), '/').'/fonts';
    }

    public function path(string $key): string
    {
        return $this->dir().'/'.$this->packs()[$key]['file'];
    }

    /**
     * Wraps CJK runs of the document body in font-family spans. Returns the HTML unchanged when there is no CJK
     * text or no pack is installed (a hint is logged in that case).
     */
    public function apply(string $html): string
    {
        $bodyAt = stripos($html, '<body');
        if ($bodyAt === false || ! $this->hasCjk(substr($html, $bodyAt))) {
            return $html;
        }
        $head = substr($html, 0, $bodyAt);
        $body = substr($html, $bodyAt);

        $installed = $this->installed();
        $han = $this->hanPack($installed);

        if (! $installed) {
            Log::warning('A PDF contains Chinese, Japanese or Korean text but no CJK font pack is installed, so it shows empty boxes. Run `php artisan billing:fonts:install` or use Settings > System > PDF fonts.');

            return $html;
        }

        $family = fn (string $key) => "'".$this->packs()[$key]['family']."'";
        $classes = '['.self::HANGUL.self::KANA.self::HAN.self::MARKS.']+';

        $body = preg_replace_callback('/>([^<>]*['.self::HANGUL.self::KANA.self::HAN.'][^<>]*)</u', function ($m) use ($classes, $han, $installed, $family) {
            $text = preg_replace_callback('/'.$classes.'/u', function ($r) use ($han, $installed, $family) {
                $out = '';
                foreach ($this->split($r[0], $han, $installed) as [$key, $text]) {
                    $out .= '<span style="font-family:'.$family($key).', \'DejaVu Sans\', sans-serif">'.$text.'</span>';
                }

                return $out;
            }, $m[1]);

            return '>'.$text.'<';
        }, $body) ?? $body;

        return $head.$body;
    }

    /** @var array<string, array<int, true>> code points each installed font has a glyph for, read from its metrics file */
    private array $coverage = [];

    /**
     * Splits a run of CJK characters into spans, each in the pack that suits it: kana in Japanese, hangul in Korean,
     * ideographs in the run's own style (Japanese beside kana, Korean beside hangul, else the Chinese form). A character
     * the preferred pack lacks (say a simplified ideograph inside Japanese text) goes to the first pack that has it.
     *
     * @return list<array{0: string, 1: string}> pairs of [pack key, text]
     */
    private function split(string $run, string $han, array $installed): array
    {
        $hasKana = (bool) preg_match('/['.self::KANA.']/u', $run);
        $hasHangul = (bool) preg_match('/['.self::HANGUL.']/u', $run);
        $ideo = $hasKana ? 'jp' : ($hasHangul ? 'kr' : $han);

        $pieces = [];
        $last = null;
        foreach (mb_str_split($run) as $ch) {
            if (preg_match('/['.self::HANGUL.']/u', $ch)) {
                $pref = 'kr';
            } elseif (preg_match('/['.self::KANA.']/u', $ch)) {
                $pref = 'jp';
            } elseif (preg_match('/['.self::MARKS.']/u', $ch)) {
                $pref = $last ?? $ideo;
            } else {
                $pref = $ideo;
            }
            $order = array_values(array_filter(array_unique([$pref, $han, 'sc', 'tc', 'jp', 'kr']), fn ($k) => in_array($k, $installed, true)));
            $key = $order[0] ?? $installed[0];
            foreach ($order as $candidate) {
                if (isset($this->glyphs($candidate)[mb_ord($ch)])) {
                    $key = $candidate;
                    break;
                }
            }
            $last = $key;
            if ($pieces && $pieces[array_key_last($pieces)][0] === $key) {
                $pieces[array_key_last($pieces)][1] .= $ch;
            } else {
                $pieces[] = [$key, $ch];
            }
        }

        return $pieces;
    }

    /** @return array<int, true> */
    private function glyphs(string $key): array
    {
        if (! isset($this->coverage[$key])) {
            $set = [];
            $file = $this->dir().'/'.pathinfo($this->packs()[$key]['file'], PATHINFO_FILENAME).'.ufm';
            $fh = @fopen($file, 'r');
            while ($fh && ($line = fgets($fh)) !== false) {
                if (str_starts_with($line, 'U ')) {
                    $set[(int) substr($line, 2)] = true;
                }
            }
            if ($fh) {
                fclose($fh);
            }
            $this->coverage[$key] = $set;
        }

        return $this->coverage[$key];
    }

    /** The pack for ideographs outside kana or hangul text: the configured Chinese form (Settings billing.pdf_han: sc or tc). */
    private function hanPack(array $installed): string
    {
        $pref = Settings::get('billing.pdf_han', 'sc') === 'tc' ? 'tc' : 'sc';

        return in_array($pref, $installed, true) ? $pref : ($installed ? ($installed[0]) : $pref);
    }

    /**
     * Downloads a pack, verifies size and SHA-256, installs it atomically and registers it with dompdf.
     *
     * @throws \RuntimeException on download failure, size or checksum mismatch, or an unwritable folder
     */
    public function install(string $key): void
    {
        $pack = $this->packs()[$key] ?? throw new \RuntimeException("Unknown font pack \"{$key}\".");
        if (! is_dir($this->dir()) && ! @mkdir($this->dir(), 0775, true) && ! is_dir($this->dir())) {
            throw new \RuntimeException('Cannot create '.$this->dir().'. Make storage/ writable.');
        }

        $response = Http::timeout(600)->get($pack['url']);
        if (! $response->successful()) {
            throw new \RuntimeException("Download of {$pack['label']} failed (HTTP {$response->status()}).");
        }
        $bytes = $response->body();
        $max = (int) ($pack['size'] ?? 0) * 1.05 + 1024;
        if ($bytes === '' || ($max > 1024 && strlen($bytes) > $max)) {
            throw new \RuntimeException("The {$pack['label']} download has an unexpected size.");
        }
        if (! hash_equals(strtolower($pack['sha256']), hash('sha256', $bytes))) {
            throw new \RuntimeException("Checksum mismatch for {$pack['label']}: the download is not the expected file and was discarded.");
        }

        $tmp = $this->path($key).'.part';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || ! @rename($tmp, $this->path($key))) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write '.$this->path($key).'. Make storage/ writable.');
        }

        // dompdf needs a metrics file (.ufm) next to every font; a stale JSON cache of an older file must go.
        $name = pathinfo($pack['file'], PATHINFO_FILENAME);
        @unlink($this->dir()."/{$name}.ufm.json");
        try {
            $font = Font::load($this->path($key));
            $font->parse();
            $font->saveAdobeFontMetrics($this->dir()."/{$name}.ufm");
            $font->close();
        } catch (\Throwable $e) {
            $this->uninstall($key);
            throw new \RuntimeException("{$pack['label']} could not be read as a TrueType font: ".$e->getMessage());
        }
        if (! is_file($this->dir()."/{$name}.ufm")) {
            $this->uninstall($key);
            throw new \RuntimeException("Could not create font metrics for {$pack['label']}.");
        }

        $families = $this->registered();
        $families[strtolower($pack['family'])] = ['normal' => $name, 'bold' => $name, 'italic' => $name, 'bold_italic' => $name];
        $this->saveRegistered($families);
    }

    public function uninstall(string $key): void
    {
        $pack = $this->packs()[$key] ?? throw new \RuntimeException("Unknown font pack \"{$key}\".");
        $base = $this->dir().'/'.pathinfo($pack['file'], PATHINFO_FILENAME);
        @unlink($base.'.ttf');
        @unlink($base.'.ufm');
        @unlink($base.'.ufm.json');
        $families = $this->registered();
        unset($families[strtolower($pack['family'])]);
        $this->saveRegistered($families);
    }

    /** dompdf's per-installation font registry (installed-fonts.json in the font folder). @return array<string, array<string, string>> */
    private function registered(): array
    {
        $file = $this->dir().'/installed-fonts.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return is_array($data) ? $data : [];
    }

    private function saveRegistered(array $families): void
    {
        file_put_contents($this->dir().'/installed-fonts.json', json_encode($families, JSON_PRETTY_PRINT));
    }
}
