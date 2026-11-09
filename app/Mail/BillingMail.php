<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One flexible, branded transactional email: a heading, some lines of text, and an optional button. */
class BillingMail extends Mailable
{
    /** @param  array<int, string>  $lines */
    public function __construct(
        public string $mailSubject,
        public string $heading,
        public array $lines,
        public ?string $actionLabel = null,
        public ?string $actionUrl = null,
        /** @var array<string, string> filename => raw bytes of PDFs to attach */
        public array $pdfs = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return collect($this->pdfs)->map(fn (string $bytes, string $name) => Attachment::fromData(fn () => $bytes, $name)->withMime('application/pdf'))->values()->all();
    }

    public function content(): Content
    {
        return new Content(view: 'emails.generic');
    }
}
