<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ticket attachments, stored and served defensively:
 *  - files live on the private disk (never under public/), under a random name that is not derived from the upload;
 *  - the type is detected from the content, the client-supplied type and name are never trusted;
 *  - executables, scripts and web-page types are refused outright (and an optional allow-list can narrow it further);
 *  - they are only ever served through an authorised controller, as a forced download with nosniff and a sandboxing CSP,
 *    so an uploaded HTML or SVG file can never run in the site's origin.
 */
class TicketAttachments
{
    /** Never accepted, whatever else is allowed. Checked against every dot-separated part of the name (so "x.php.jpg" is refused too). */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc', 'htaccess', 'htpasswd',
        'exe', 'dll', 'com', 'bat', 'cmd', 'scr', 'msi', 'msp', 'cpl', 'pif', 'gadget', 'lnk', 'reg',
        'js', 'jse', 'mjs', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1', 'sh', 'bash', 'zsh', 'csh', 'command', 'jar', 'jnlp', 'app', 'apk', 'dmg', 'bin', 'so', 'dylib',
        'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'hta', 'swf', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp', 'jspx',
    ];

    /** Types that are safe to hand back with their real type. Everything else is sent as an opaque download. */
    private const SAFE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    public function maxBytes(): int
    {
        return max(1, (int) Settings::get('support.max_attachment_mb', 10)) * 1024 * 1024;
    }

    public function maxFiles(): int
    {
        return max(0, (int) Settings::get('support.max_attachments', 5));
    }

    /** @return array<int, string> lowercase extensions, or [] for "anything not blocked" */
    public function allowedExtensions(): array
    {
        return collect(explode(',', (string) Settings::get('support.allowed_extensions', '')))
            ->map(fn ($e) => strtolower(trim($e, " .\t")))->filter()->values()->all();
    }

    /** A safe display name: no paths, no control characters, bounded length. */
    public function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/[\/:*?"<>|]+/', '_', $name) ?? '';
        $name = trim($name, " .\t");
        $name = Str::limit($name, 150, '');

        return $name === '' ? 'attachment' : $name;
    }

    /** Why a file cannot be accepted, or null. */
    public function problem(string $name, int $size, ?string $path = null, ?string $bytes = null): ?string
    {
        $rawName = $name;
        $name = $this->cleanName($name);
        if ($size <= 0) {
            return $name.' is empty.';
        }
        if ($size > $this->maxBytes()) {
            return $name.' is larger than '.($this->maxBytes() / 1024 / 1024).' MB.';
        }
        // A leading dot is trimmed from the display name, so check the raw name for dotfiles like ".htaccess" too.
        $raw = strtolower(basename(str_replace('\\', '/', $rawName)));
        if (str_starts_with($raw, '.') && in_array(ltrim($raw, '.'), self::BLOCKED_EXTENSIONS, true)) {
            return $name.' is a type of file that cannot be attached.';
        }
        $parts = array_map('strtolower', array_slice(explode('.', $name), 1));
        foreach ($parts as $part) {
            if (in_array($part, self::BLOCKED_EXTENSIONS, true)) {
                return $name.' is a type of file that cannot be attached.';
            }
        }
        $allowed = $this->allowedExtensions();
        if ($allowed && ! in_array(end($parts) ?: '', $allowed, true)) {
            return $name.' is not an allowed type (allowed: '.implode(', ', $allowed).').';
        }
        $mime = $this->detect($path, $bytes);
        if (preg_match('#^(text/html|application/xhtml|image/svg|text/xml|application/xml|application/x-httpd-php|text/x-php|application/x-php|application/javascript|text/javascript|application/x-sh|application/x-executable|application/x-dosexec|application/x-msdownload|application/x-mach-binary)#', $mime)) {
            return $name.' is a type of file that cannot be attached.';
        }

        return null;
    }

    /**
     * Check every file up front so nothing is stored if one is bad.
     *
     * @param  array<int, UploadedFile|array{name: string, content: string}>  $files
     * @return array<int, string> problems
     */
    public function problems(array $files): array
    {
        $files = array_values(array_filter($files));
        if (count($files) > $this->maxFiles()) {
            return ['You can attach up to '.$this->maxFiles().' files.'];
        }
        $out = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                if (! $file->isValid()) {
                    $out[] = $this->cleanName($file->getClientOriginalName()).' could not be uploaded.';

                    continue;
                }
                $p = $this->problem($file->getClientOriginalName(), (int) $file->getSize(), $file->getRealPath() ?: null);
            } else {
                $p = $this->problem($file['name'], strlen($file['content']), null, $file['content']);
            }
            if ($p) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Store files (already checked with problems()) against a message.
     *
     * @param  array<int, UploadedFile|array{name: string, content: string}>  $files
     * @return array<int, TicketAttachment>
     */
    public function store(Ticket $ticket, TicketMessage $message, array $files): array
    {
        $stored = [];
        foreach (array_values(array_filter($files)) as $file) {
            $uploaded = $file instanceof UploadedFile;
            $name = $this->cleanName($uploaded ? $file->getClientOriginalName() : $file['name']);
            $bytes = $uploaded ? (string) file_get_contents($file->getRealPath()) : $file['content'];
            $path = $ticket->uuid.'/'.Str::lower((string) Str::ulid());
            Storage::disk($this->disk())->put($path, $bytes);
            $stored[] = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message->id,
                'disk' => $this->disk(),
                'path' => $path,
                'original_name' => $name,
                'mime' => $this->detect(null, $bytes),
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ]);
        }

        return $stored;
    }

    /** The download. The caller must already have authorised access to the ticket (and that internal-note files stay staff-only). */
    public function download(TicketAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);
        $type = in_array($attachment->mime, self::SAFE_TYPES, true) ? $attachment->mime : 'application/octet-stream';

        return response()->streamDownload(function () use ($disk, $attachment) {
            $stream = $disk->readStream($attachment->path);
            fpassthru($stream);
            fclose($stream);
        }, $attachment->original_name, [
            'Content-Type' => $type,
            'Content-Length' => (string) $attachment->size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ], 'attachment');
    }

    public function purge(Ticket $ticket): void
    {
        Storage::disk($this->disk())->deleteDirectory($ticket->uuid);
    }

    private function disk(): string
    {
        return 'tickets';
    }

    private function detect(?string $path, ?string $bytes): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $path !== null ? $finfo->file($path) : $finfo->buffer((string) $bytes);

        return is_string($mime) ? strtolower($mime) : 'application/octet-stream';
    }
}
