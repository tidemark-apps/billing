<?php

namespace App\Services;

use App\Mail\BillingMail;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlanChange;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Every email the billing system sends. Failures are reported but never interrupt the business action. */
class Notifier
{
    /** @param  array<int, string>  $lines */
    public function send(string $to, string $subject, string $heading, array $lines, ?string $actionLabel = null, ?string $actionUrl = null, ?\Closure $pdfs = null): void
    {
        if (! Settings::bool('mail.enabled', true) || $to === '') {
            return;
        }
        try {
            Mail::to($to)->send(new BillingMail($subject, $heading, $lines, $actionLabel, $actionUrl, $pdfs ? $pdfs() : []));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function adminAddress(): string
    {
        return (string) (Settings::get('company.email') ?: config('mail.from.address'));
    }

    public function welcome(User $user): void
    {
        $this->send($user->email, 'Welcome to '.$this->company(), 'Welcome, '.$user->name, ['Your account is ready. You can order services, pay invoices and manage everything from your client area.'], 'Open client area', $this->link('portal.dashboard'));
    }

    public function invoiceIssued(Invoice $invoice): void
    {
        $invoice->loadMissing('client');
        $this->send($invoice->client->email, "Invoice {$invoice->number} from ".$this->company(), 'New invoice '.$invoice->number, [
            'Amount due: '.Money::format($invoice->balance(), $invoice->currency),
            'Due date: '.$invoice->due_date?->format('j M Y'),
        ], 'View and pay invoice', $this->link('invoice.public', $invoice->uuid), fn () => $this->invoicePdf($invoice));
    }

    public function invoiceReminder(Invoice $invoice): void
    {
        $invoice->loadMissing('client');
        $overdue = $invoice->isOverdue();
        $this->send($invoice->client->email, ($overdue ? 'Overdue: ' : 'Reminder: ')."invoice {$invoice->number}", $overdue ? 'Invoice '.$invoice->number.' is overdue' : 'Invoice '.$invoice->number.' is due soon', [
            'Amount due: '.Money::format($invoice->balance(), $invoice->currency),
            'Due date: '.$invoice->due_date?->format('j M Y'),
            $overdue ? 'Services linked to this invoice may be suspended if it stays unpaid.' : 'Pay before the due date to keep your services running.',
        ], 'Pay now', $this->link('invoice.public', $invoice->uuid), fn () => $this->invoicePdf($invoice));
    }

    public function paymentReceived(Payment $payment): void
    {
        $payment->loadMissing('client', 'invoice');
        $this->send($payment->client->email, 'Payment received', 'Thank you for your payment', array_filter([
            'We received '.Money::format($payment->amount, $payment->currency).($payment->invoice ? ' for invoice '.$payment->invoice->number : '').'.',
        ]), $payment->invoice ? 'View invoice' : null, $payment->invoice ? $this->link('invoice.public', $payment->invoice->uuid) : null, $payment->invoice ? fn () => $this->invoicePdf($payment->invoice) : null);
    }

    public function quoteSent(Quote $quote): void
    {
        $quote->loadMissing('client');
        $this->send($quote->client->email, "Quote {$quote->number} from ".$this->company(), 'Quote '.$quote->number, array_filter([
            $quote->title,
            'Total: '.Money::format($quote->total, $quote->currency),
            $quote->valid_until ? 'Valid until '.$quote->valid_until->format('j M Y') : null,
        ]), 'Review quote', $this->link('quote.public', $quote->uuid));
    }

    public function quoteAccepted(Quote $quote): void
    {
        $quote->loadMissing('client');
        $this->send($this->adminAddress(), "Quote {$quote->number} accepted", 'Quote accepted', [$quote->client->displayName().' accepted quote '.$quote->number.' ('.Money::format($quote->total, $quote->currency).').'], 'Open quote', $this->link('admin.quotes.show', $quote));
    }

    public function serviceActivated(Service $service): void
    {
        $service->loadMissing('client', 'product');
        $this->send($service->client->email, $service->title().' is ready', 'Your service is active', array_filter([
            $service->title().' has been set up.',
            $service->label ? 'Reference: '.$service->label : null,
        ]), 'Manage service', $this->link('portal.services.show', $service));
    }

    public function trialStarted(Service $service): void
    {
        $service->loadMissing('client', 'product');
        $this->send($service->client->email, 'Your trial of '.$service->title().' has started', 'Your free trial has started', array_filter([
            'Your trial runs until '.$service->trial_ends_at->format('j F Y').'.',
            $service->amount > 0 ? 'After that you will be invoiced '.Money::format($service->amount, $service->currency).' '.strtolower($service->billing_cycle->label()).'. Cancel any time before then and you will not be charged.' : null,
        ]), 'Manage service', $this->link('portal.services.show', $service));
    }

    public function trialEnding(Service $service): void
    {
        $service->loadMissing('client', 'product');
        $this->send($service->client->email, 'Your trial of '.$service->title().' ends soon', 'Your trial is ending', [
            'Your free trial ends on '.$service->trial_ends_at->format('j F Y').'.',
            'We will then invoice '.Money::format($service->amount, $service->currency).' for the first '.strtolower($service->billing_cycle->label()).' period. Cancel before then if you do not want to continue.',
        ], 'Manage service', $this->link('portal.services.show', $service));
    }

    public function planChanged(Service $service, PlanChange $change): void
    {
        $service->loadMissing('client', 'product');
        $when = $change->timing === 'renewal' ? 'on '.$change->effective_date->format('j F Y') : 'now';
        $this->send($service->client->email, $service->title().': plan '.($change->timing === 'renewal' ? 'change scheduled' : 'changed'), 'Your plan '.($change->timing === 'renewal' ? 'will change' : 'has changed'), [
            ($change->fromProduct?->name ?? 'Previous plan').' → '.($change->toProduct?->name ?? 'New plan').' ('.$change->to_cycle->label().') takes effect '.$when.'.',
            'New price: '.Money::format($change->to_amount, $change->currency).' '.strtolower($change->to_cycle->label()).'.',
        ], 'Manage service', $this->link('portal.services.show', $service));
    }

    /** The customer's acknowledgement of a new ticket (when the department has auto-reply on). */
    public function ticketOpened(Ticket $ticket, bool $byStaff = false): void
    {
        $ticket->loadMissing('department');
        if (! $ticket->department?->auto_reply && ! $byStaff) {
            return;
        }
        $first = $ticket->messages()->where('kind', 'reply')->first();
        $this->ticketMail($ticket, $first, $this->ticketSubject($ticket), $byStaff ? 'We opened a ticket for you' : 'We received your request', array_filter([
            $byStaff ? 'Our team opened ticket '.$ticket->number.' for you.' : 'Thanks for getting in touch. Your ticket number is '.$ticket->number.' and we will reply as soon as we can.',
            $byStaff ? $first?->body : null,
            'You can reply to this email to add to the ticket.',
        ]), 'View ticket');
    }

    /** A staff reply, emailed to the customer. */
    public function ticketReplyToCustomer(Ticket $ticket, TicketMessage $message): void
    {
        $count = $message->attachments()->count();
        $this->ticketMail($ticket, $message, $this->ticketSubject($ticket), 'New reply on '.$ticket->number, array_filter([
            $message->body,
            $count > 0 ? $count.' attachment'.($count === 1 ? '' : 's').' — open the ticket to download '.($count === 1 ? 'it' : 'them').'.' : null,
            $ticket->is_locked ? 'This ticket is locked, so replies are closed.' : 'Reply to this email to answer.',
        ]), 'View ticket', 'staff');
    }

    public function ticketClosed(Ticket $ticket): void
    {
        $this->ticketMail($ticket, null, $this->ticketSubject($ticket), 'Your ticket was closed', [
            'Ticket '.$ticket->number.' ("'.$ticket->subject.'") has been closed.',
            $ticket->is_locked ? null : 'If you still need help, reply to this email or open the ticket to reopen it.',
        ], 'View ticket');
    }

    /** Tell the people who work the queue: the assignee, or the whole department when nobody has it yet. */
    public function ticketForStaff(Ticket $ticket, string $what): void
    {
        if (! Settings::bool('support.notify_staff', true)) {
            return;
        }
        $ticket->loadMissing('department', 'assignee');
        $recipients = $ticket->assignee ? collect([$ticket->assignee]) : ($ticket->department?->staff()->where('is_active', true)->get() ?? collect());
        $emails = $recipients->pluck('email')->filter()->unique()->values();
        if ($emails->isEmpty()) {
            $emails = collect([$this->adminAddress()])->filter();
        }
        $last = $ticket->messages()->where('kind', 'reply')->latest('id')->first();
        $heading = $what === 'new' ? 'New ticket '.$ticket->number : 'New reply on '.$ticket->number;
        foreach ($emails as $to) {
            $this->send($to, '['.$ticket->number.'] '.$ticket->subject, $heading, array_filter([
                $ticket->requester_name.' <'.$ticket->requester_email.'>'.($ticket->department ? ' · '.$ticket->department->name : ''),
                Str::limit((string) $last?->body, 600),
            ]), 'Open ticket', $this->link('admin.tickets.show', $ticket));
        }
    }

    public function ticketAssigned(Ticket $ticket, User $staff): void
    {
        if (! Settings::bool('support.notify_staff', true)) {
            return;
        }
        $this->send($staff->email, '['.$ticket->number.'] assigned to you', 'A ticket was assigned to you', [$ticket->number.': '.$ticket->subject], 'Open ticket', $this->link('admin.tickets.show', $ticket));
    }

    private function ticketSubject(Ticket $ticket): string
    {
        return '['.$ticket->number.'] '.$ticket->subject;
    }

    /** Customer-facing ticket email: Reply-To is the department address, with Message-ID/In-Reply-To so mail clients thread it. */
    private function ticketMail(Ticket $ticket, ?TicketMessage $message, string $subject, string $heading, array $lines, string $action, ?string $only = null): void
    {
        if (! Settings::bool('mail.enabled', true) || $ticket->requester_email === '') {
            return;
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost';
        $messageId = $message ? 'tkt-'.$ticket->uuid.'-'.$message->id.'@'.$host : null;
        $previous = $ticket->messages()->whereNotNull('message_id')->when($message, fn ($q) => $q->where('id', '<', $message->id))->latest('id')->value('message_id');
        try {
            Mail::to($ticket->requester_email)->send(new BillingMail(
                $subject, $heading, array_merge(['##- Please type your reply above this line -##'], $lines),
                $action, $ticket->client_id ? url('/portal/tickets/'.$ticket->uuid) : null, [],
                $ticket->department?->email, $messageId, $previous ? trim($previous, '<>') : null,
            ));
            if ($message && $messageId && $message->message_id === null) {
                $message->update(['message_id' => $messageId]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function provisioningFailed(Service $service, string $action, string $message): void
    {
        $this->send($this->adminAddress(), 'Provisioning failed: '.$service->title(), 'Provisioning needs attention', [
            'Action: '.$action,
            'Error: '.$message,
        ], 'Open service', $this->link('admin.services.show', $service));
    }

    /** The invoice PDF as [filename => bytes] when attaching is on. Rendering problems are logged and the email goes without it. @return array<string, string> */
    private function invoicePdf(Invoice $invoice): array
    {
        if (! Settings::bool('billing.attach_pdf', true)) {
            return [];
        }
        try {
            $renderer = app(PdfRenderer::class);

            return [$renderer->filename($invoice) => $renderer->invoice($invoice)];
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** A route URL that falls back to the site root if that page is not installed, so an email never fails over a link. */
    private function link(string $route, mixed ...$params): string
    {
        return Route::has($route) ? route($route, $params) : url('/');
    }

    private function company(): string
    {
        return (string) Settings::get('company.name', config('billing.name'));
    }
}
