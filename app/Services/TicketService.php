<?php

namespace App\Services;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketReplied;
use App\Events\TicketStatusChanged;
use App\Models\Client;
use App\Models\Department;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Activity;
use App\Support\Sequence;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything that happens to a ticket. Controllers (web, API) and the email intake all go through here so the rules
 * (locking, reopening, who can be assigned, what a customer may see) live in one place.
 */
class TicketService
{
    public function __construct(private readonly TicketAttachments $attachments, private readonly Notifier $notifier) {}

    public static function enabled(): bool
    {
        return Settings::bool('support.enabled', false);
    }

    /**
     * Open a ticket.
     *
     * @param  array{
     *   department: Department, subject: string, body: string, name?: string, email?: string, client?: ?Client, user?: ?User,
     *   service?: ?Service, custom?: array<string, mixed>, source?: string, priority?: string, opened_by?: ?User,
     *   files?: array<int, mixed>, message_id?: ?string, notify?: bool
     * }  $data  opened_by is set when staff open it on the customer's behalf
     */
    public function open(array $data): Ticket
    {
        /** @var Department $department */
        $department = $data['department'];
        if (! $department->is_active) {
            throw new \DomainException('That department is not accepting tickets.');
        }
        $client = $data['client'] ?? null;
        $user = $data['user'] ?? null;
        $service = $data['service'] ?? null;
        if ($service && (! $client || $service->client_id !== $client->id)) {
            throw new \DomainException('That service does not belong to this customer.');
        }
        $files = $data['files'] ?? [];
        $this->assertFiles($files);

        $name = trim((string) ($data['name'] ?? $user?->name ?? $client?->displayName() ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? $user?->email ?? $client?->email ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('A valid email address is needed to open a ticket.');
        }
        $openedBy = $data['opened_by'] ?? null;
        $source = $data['source'] ?? 'portal';
        $priority = TicketPriority::tryFrom($data['priority'] ?? '') ?? TicketPriority::Normal;

        $ticket = DB::transaction(function () use ($data, $department, $client, $user, $service, $name, $email, $openedBy, $source, $priority, $files) {
            $ticket = Ticket::create([
                'number' => Sequence::format((string) Settings::get('support.ticket_prefix', 'TKT-'), Sequence::next('ticket')),
                'department_id' => $department->id,
                'client_id' => $client?->id,
                'user_id' => $user?->id,
                'service_id' => $service?->id,
                'requester_name' => $name !== '' ? $name : $email,
                'requester_email' => $email,
                'subject' => mb_substr(trim($data['subject']), 0, 200),
                'status' => $openedBy ? TicketStatus::Answered : TicketStatus::Open,
                'priority' => $priority,
                'source' => $source,
                'custom_fields' => $this->cleanFields($department, $data['custom'] ?? [], $source === 'email' || (bool) $openedBy),
                'last_reply_at' => now(),
                'last_reply_by' => $openedBy ? 'staff' : 'customer',
            ]);
            $message = $this->addMessage($ticket, $openedBy ? 'staff' : 'customer', $openedBy ?? $user, $data['body'], 'reply', $source, $data['message_id'] ?? null, $name, $email);
            $this->attachments->store($ticket, $message, $files);

            return $ticket->refresh();
        });

        event(new TicketCreated($ticket, ['source' => $source]));
        if ($data['notify'] ?? true) {
            $this->notifier->ticketOpened($ticket, $openedBy !== null);
            $this->notifier->ticketForStaff($ticket, 'new');
        }

        return $ticket;
    }

    /**
     * A reply from the customer (author null for email from the requester) or from staff.
     *
     * @param  array<int, mixed>  $files
     * @param  array{status?: ?TicketStatus, message_id?: ?string, source?: string, name?: ?string, email?: ?string}  $options
     */
    public function reply(Ticket $ticket, string $body, ?User $author, bool $asStaff, array $files = [], array $options = []): TicketMessage
    {
        $source = $options['source'] ?? 'portal';
        if (! $asStaff && $ticket->is_locked) {
            throw new \DomainException('This ticket is locked, so it cannot be replied to.');
        }
        if (trim($body) === '' && ! array_filter($files)) {
            throw ValidationException::withMessages(['body' => 'Write a reply first.']);
        }
        $this->assertFiles($files);

        $message = DB::transaction(function () use ($ticket, $body, $author, $asStaff, $files, $options, $source) {
            $message = $this->addMessage($ticket, $asStaff ? 'staff' : 'customer', $author, $body, 'reply', $source, $options['message_id'] ?? null, $options['name'] ?? null, $options['email'] ?? null);
            $this->attachments->store($ticket, $message, $files);
            $to = $asStaff ? ($options['status'] ?? TicketStatus::Answered) : TicketStatus::CustomerReply;
            $from = $ticket->status;
            $ticket->update([
                'status' => $to,
                'closed_at' => $to->isClosed() ? ($ticket->closed_at ?? now()) : null,
                'last_reply_at' => now(),
                'last_reply_by' => $asStaff ? 'staff' : 'customer',
            ]);
            if ($from !== $to) {
                $this->eventLine($ticket, $author, $from->isClosed() ? 'Reopened' : 'Status changed to '.$to->label(), false);
            }

            return $message;
        });

        event(new TicketReplied($ticket->refresh(), ['message_id' => $message->id, 'by' => $asStaff ? 'staff' : 'customer']));
        if ($asStaff) {
            $this->notifier->ticketReplyToCustomer($ticket, $message);
            if ($ticket->status->isClosed()) {
                event(new TicketStatusChanged($ticket, ['status' => 'closed']));
            }
        } else {
            $this->notifier->ticketForStaff($ticket, 'reply');
        }

        return $message;
    }

    /**
     * An internal note: only staff ever see it, and the customer is not told.
     *
     * @param  array<int, mixed>  $files
     */
    public function note(Ticket $ticket, User $staff, string $body, array $files = []): TicketMessage
    {
        if (trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'Write a note first.']);
        }
        $this->assertFiles($files);

        return DB::transaction(function () use ($ticket, $staff, $body, $files) {
            $message = $this->addMessage($ticket, 'staff', $staff, $body, 'note', 'admin');
            $this->attachments->store($ticket, $message, $files);

            return $message;
        });
    }

    public function setStatus(Ticket $ticket, TicketStatus $status, ?User $by = null): Ticket
    {
        if ($ticket->status === $status) {
            return $ticket;
        }
        $from = $ticket->status;
        $ticket->update(['status' => $status, 'closed_at' => $status->isClosed() ? now() : null]);
        $this->eventLine($ticket, $by, $status->isClosed() ? 'Closed' : ($from->isClosed() ? 'Reopened as '.$status->label() : 'Status changed to '.$status->label()));
        Activity::log('ticket.status', $ticket, $ticket->number.' → '.$status->label());
        event(new TicketStatusChanged($ticket, ['from' => $from->value, 'status' => $status->value]));
        if ($status->isClosed() && Settings::bool('support.notify_customer_on_close', true)) {
            $this->notifier->ticketClosed($ticket);
        }

        return $ticket;
    }

    public function close(Ticket $ticket, ?User $by = null): Ticket
    {
        return $this->setStatus($ticket, TicketStatus::Closed, $by);
    }

    /** A customer closing their own ticket. */
    public function closeByCustomer(Ticket $ticket, User $user): Ticket
    {
        if ($ticket->is_locked) {
            throw new \DomainException('This ticket is locked.');
        }

        return $this->setStatus($ticket, TicketStatus::Closed, $user);
    }

    public function lock(Ticket $ticket, User $by): Ticket
    {
        if (! $ticket->is_locked) {
            $ticket->update(['is_locked' => true, 'locked_by' => $by->id]);
            $this->eventLine($ticket, $by, 'Locked: the customer can no longer reply');
        }

        return $ticket;
    }

    public function unlock(Ticket $ticket, User $by): Ticket
    {
        if ($ticket->is_locked) {
            $ticket->update(['is_locked' => false, 'locked_by' => null]);
            $this->eventLine($ticket, $by, 'Unlocked');
        }

        return $ticket;
    }

    public function setPriority(Ticket $ticket, TicketPriority $priority, ?User $by = null): Ticket
    {
        if ($ticket->priority !== $priority) {
            $ticket->update(['priority' => $priority]);
            $this->eventLine($ticket, $by, 'Priority set to '.$priority->label());
        }

        return $ticket;
    }

    /** Staff assigning themselves. */
    public function claim(Ticket $ticket, User $staff): Ticket
    {
        return $this->assign($ticket, $staff, $staff);
    }

    /** Assign to a staff member who can see the ticket, or null to unassign. */
    public function assign(Ticket $ticket, ?User $to, ?User $by = null): Ticket
    {
        if ($to && (! $to->isStaff() || ! $to->is_active || ! $this->canWork($to, $ticket->department_id))) {
            throw new \DomainException($to->name.' is not in this ticket\'s department.');
        }
        if ($ticket->assigned_to === $to?->id) {
            return $ticket;
        }
        $ticket->update(['assigned_to' => $to?->id]);
        $this->eventLine($ticket, $by, $to ? ($by && $by->is($to) ? 'Assigned to themselves' : 'Assigned to '.$to->name) : 'Unassigned');
        Activity::log('ticket.assigned', $ticket, $ticket->number.' → '.($to?->name ?? 'nobody'));
        event(new TicketAssigned($ticket, ['assigned_to' => $to?->id]));
        if ($to && (! $by || ! $by->is($to))) {
            $this->notifier->ticketAssigned($ticket, $to);
        }

        return $ticket;
    }

    /** Move to another department. An assignee who is not in it any more is cleared. */
    public function transfer(Ticket $ticket, Department $to, ?User $by = null): Ticket
    {
        if ($ticket->department_id === $to->id) {
            return $ticket;
        }
        $from = $ticket->department?->name ?? 'none';
        $ticket->update(['department_id' => $to->id]);
        $this->eventLine($ticket, $by, 'Moved from '.$from.' to '.$to->name);
        if ($ticket->assignee && ! $this->canWork($ticket->assignee, $to->id)) {
            $this->assign($ticket->refresh(), null, $by);
        }

        return $ticket->refresh();
    }

    /** Staff who can be assigned: admins and members of the department. @return \Illuminate\Support\Collection<int, User> */
    public function assignable(Ticket $ticket)
    {
        return User::whereIn('role', ['admin', 'staff'])->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $this->canWork($u, $ticket->department_id))->values();
    }

    public function canWork(User $user, ?int $departmentId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $departmentId !== null && $user->departments()->whereKey($departmentId)->exists();
    }

    /** Delete a ticket and its files. */
    public function delete(Ticket $ticket, ?User $by = null): void
    {
        Activity::log('ticket.deleted', $ticket, $ticket->number.' deleted');
        $this->attachments->purge($ticket);
        $ticket->delete();
    }

    private function assertFiles(array $files): void
    {
        if ($problems = $this->attachments->problems($files)) {
            throw ValidationException::withMessages(['attachments' => $problems]);
        }
    }

    private function addMessage(Ticket $ticket, string $authorType, ?User $user, string $body, string $kind, string $source, ?string $messageId = null, ?string $name = null, ?string $email = null): TicketMessage
    {
        return $ticket->messages()->create([
            'user_id' => $user?->id,
            'kind' => $kind,
            'author_type' => $authorType,
            'author_name' => $name ?: $user?->name,
            'author_email' => $email ?: $user?->email,
            'body' => $body,
            'message_id' => $messageId,
            'source' => $source,
        ]);
    }

    /** A system line in the staff-only timeline. */
    private function eventLine(Ticket $ticket, ?User $by, string $text, bool $named = true): void
    {
        $ticket->messages()->create([
            'user_id' => $by?->id,
            'kind' => 'event',
            'author_type' => 'system',
            'author_name' => $by?->name,
            'body' => $text,
            'source' => 'system',
        ]);
    }

    /**
     * Keep only answers to fields that apply to the department; customers cannot fill staff-only ones. Values are
     * validated by the controllers with TicketField::rules(); this normalises what is stored.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function cleanFields(Department $department, array $input, bool $trustedSource): ?array
    {
        $out = [];
        foreach ($department->applicableFields(forCustomer: ! $trustedSource) as $field) {
            if (! array_key_exists($field->key, $input) || $input[$field->key] === null || $input[$field->key] === '') {
                continue;
            }
            $value = $input[$field->key];
            $out[$field->key] = match ($field->type) {
                'checkbox' => (bool) $value,
                'number' => is_numeric($value) ? $value + 0 : null,
                default => is_scalar($value) ? mb_substr((string) $value, 0, 5000) : null,
            };
        }

        return $out ?: null;
    }
}
