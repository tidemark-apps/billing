<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Support\Settings;
use Illuminate\Mail\Mailables\Content;

/**
 * A staff reply sent to the customer in the style of a help-desk comment notification: a "reply above this line"
 * marker, a one-line status, the conversation newest first (the reply you are sending, then earlier public replies),
 * and a short grey footer. Lightly styled HTML (no card, heading, button or avatars) plus a plain-text part.
 */
class TicketReplyMail extends BillingMail
{
    public function __construct(
        public Ticket $ticket,
        public TicketMessage $reply,
        string $subject,
        public ?string $ticketUrl,
        ?string $replyTo,
        ?string $messageId,
        ?string $inReplyTo,
    ) {
        parent::__construct($subject, '', [], null, null, [], $replyTo, $messageId, $inReplyTo);
    }

    public function content(): Content
    {
        $thread = $this->ticket->messages()->where('kind', 'reply')->where('id', '<=', $this->reply->id)
            ->reorder('id', 'desc')->limit(10)->with('user')->get();
        $company = Settings::get('company.name') ?: config('billing.name');

        return new Content(view: 'emails.ticket-reply-html', text: 'emails.ticket-reply', with: [
            'company' => $company,
            'thread' => $thread,
            'attachments' => $this->reply->attachments()->count(),
        ]);
    }
}
