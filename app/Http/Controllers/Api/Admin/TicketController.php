<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\HandlesTicketInput;
use App\Http\Resources\TicketResource;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Support tickets for staff tokens. Non-admin staff only reach tickets in their departments and tickets assigned to them
 * (Ticket::visibleTo); anything else is a 404, exactly like in the admin screens. Administrators see everything.
 */
class TicketController extends ApiController
{
    use HandlesTicketInput;

    public function __construct(private readonly TicketService $tickets, private readonly TicketAttachments $attachments) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Ticket::visibleTo($user)->with('department', 'service', 'assignee')->latest('id');
        if ($request->filled('status')) {
            $query->whereIn('status', array_filter(explode(',', (string) $request->query('status'))));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->query('priority'));
        }
        foreach (['department_id', 'assigned_to', 'client_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->boolean('mine')) {
            $query->where('assigned_to', $user->id);
        }
        if ($request->boolean('unassigned')) {
            $query->whereNull('assigned_to');
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->whereContains('number', $q)->orWhereContains('subject', $q)->orWhereContains('requester_email', $q)->orWhereContains('requester_name', $q));
        }

        return $this->paginate($query, TicketResource::class, $request);
    }

    /** Open a ticket on a client's behalf. It starts as "answered": the first message is the staff member's. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'user_id' => ['nullable', 'integer'],
            'department_id' => ['required', 'integer', 'exists:ticket_departments,id'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:50000'],
            'service_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'notify' => ['nullable', 'boolean'],
        ] + $this->fileRules());
        $client = Client::find($data['client_id']) ?? throw ValidationException::withMessages(['client_id' => ['That client was not found.']]);
        $department = Department::findOrFail($data['department_id']);
        abort_unless($this->tickets->canWork($request->user(), $department->id), 403, 'You do not work in that department.');
        $customer = null;
        if (! empty($data['user_id'])) {
            $customer = User::where('id', $data['user_id'])->where('client_id', $client->id)->where('role', 'customer')->first()
                ?? throw ValidationException::withMessages(['user_id' => ['That login does not belong to this client.']]);
        }
        $service = null;
        if (! empty($data['service_id'])) {
            $service = $client->services()->whereKey($data['service_id'])->first()
                ?? throw ValidationException::withMessages(['service_id' => ['That service does not belong to this client.']]);
        }
        $custom = $this->validatedCustom($request, $department, forCustomer: false);
        $files = $this->incomingFiles($request);

        $ticket = $this->tickets->open([
            'department' => $department, 'subject' => $data['subject'], 'body' => $data['message'], 'client' => $client, 'user' => $customer,
            'service' => $service, 'custom' => $custom, 'source' => 'api', 'priority' => $data['priority'] ?? 'normal', 'opened_by' => $request->user(),
            'files' => $files, 'notify' => $request->boolean('notify', true),
        ]);
        $this->audit('ticket.created', $ticket, $ticket->number.' opened for '.$client->displayName());

        return (new TicketResource($this->loadThread($ticket, true)))->response()->setStatusCode(201);
    }

    public function show(Request $request, Ticket $ticket): TicketResource
    {
        return new TicketResource($this->loadThread($this->visible($request, $ticket), true));
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $this->visible($request, $ticket);
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:50000'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
        ] + $this->fileRules());
        $message = $this->tickets->reply($ticket, (string) ($data['message'] ?? ''), $request->user(), true, $this->incomingFiles($request), [
            'source' => 'api', 'status' => isset($data['status']) ? TicketStatus::from($data['status']) : null,
        ]);

        return $this->messageResource($ticket, $message)->response()->setStatusCode(201);
    }

    public function note(Request $request, Ticket $ticket)
    {
        $this->visible($request, $ticket);
        $data = $request->validate(['message' => ['required', 'string', 'max:50000']] + $this->fileRules());
        $message = $this->tickets->note($ticket, $request->user(), $data['message'], $this->incomingFiles($request));

        return $this->messageResource($ticket, $message)->response()->setStatusCode(201);
    }

    /** Change status, priority and/or custom field answers. A custom field set to null or "" is cleared. */
    public function update(Request $request, Ticket $ticket): TicketResource
    {
        $this->visible($request, $ticket);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'priority' => ['sometimes', Rule::enum(TicketPriority::class)],
        ]);
        $custom = null;
        if ($request->has('custom_fields')) {
            $request->merge(['custom' => $request->input('custom_fields')]);
            $custom = $this->validatedCustom($request, $ticket->department ?? throw new \DomainException('This ticket has no department, so it has no custom fields.'), forCustomer: false, partial: true);
        }

        $by = $request->user();
        if (isset($data['priority'])) {
            $this->tickets->setPriority($ticket, TicketPriority::from($data['priority']), $by);
        }
        if (isset($data['status'])) {
            $this->tickets->setStatus($ticket, TicketStatus::from($data['status']), $by);
        }
        if ($custom !== null) {
            $values = (array) ($ticket->custom_fields ?? []);
            foreach ($ticket->department->applicableFields() as $field) {
                if (! array_key_exists($field->key, $custom)) {
                    continue;
                }
                $value = $custom[$field->key];
                if ($value === null || $value === '') {
                    unset($values[$field->key]);
                } elseif (($stored = $this->fieldValue($field, $value)) !== null) {
                    $values[$field->key] = $stored;
                }
            }
            $ticket->update(['custom_fields' => $values ?: null]);
        }
        $this->audit('ticket.updated', $ticket, $ticket->number.' updated', ['fields' => array_merge(array_keys($data), $custom !== null ? ['custom_fields'] : [])]);

        return $this->fresh($ticket);
    }

    public function assign(Request $request, Ticket $ticket): TicketResource
    {
        $this->visible($request, $ticket);
        $data = $request->validate(['user_id' => ['present', 'nullable', 'integer', 'exists:users,id']]);
        $to = $data['user_id'] ? User::findOrFail($data['user_id']) : null;

        return $this->fresh($this->tickets->assign($ticket, $to, $request->user()));
    }

    public function claim(Request $request, Ticket $ticket): TicketResource
    {
        return $this->fresh($this->tickets->claim($this->visible($request, $ticket), $request->user()));
    }

    public function lock(Request $request, Ticket $ticket): TicketResource
    {
        return $this->fresh($this->tickets->lock($this->visible($request, $ticket), $request->user()));
    }

    public function unlock(Request $request, Ticket $ticket): TicketResource
    {
        return $this->fresh($this->tickets->unlock($this->visible($request, $ticket), $request->user()));
    }

    public function close(Request $request, Ticket $ticket): TicketResource
    {
        return $this->fresh($this->tickets->close($this->visible($request, $ticket), $request->user()));
    }

    public function reopen(Request $request, Ticket $ticket): TicketResource
    {
        $this->visible($request, $ticket);
        if (! $ticket->isClosed()) {
            throw new \DomainException('This ticket is not closed.');
        }

        return $this->fresh($this->tickets->setStatus($ticket, TicketStatus::Open, $request->user()));
    }

    public function transfer(Request $request, Ticket $ticket): TicketResource
    {
        $this->visible($request, $ticket);
        $data = $request->validate(['department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')->where('is_active', true)]]);

        return $this->fresh($this->tickets->transfer($ticket, Department::findOrFail($data['department_id']), $request->user()));
    }

    public function destroy(Request $request, Ticket $ticket)
    {
        $this->requireAdmin($request, 'Only administrators can delete tickets.');
        $this->audit('ticket.deleted', $ticket, $ticket->number.' deleted');
        $this->tickets->delete($ticket, $request->user());

        return response()->noContent();
    }

    public function download(Request $request, Ticket $ticket, string $attachment)
    {
        $this->visible($request, $ticket);

        return $this->attachments->download($ticket->attachments()->where('uuid', $attachment)->firstOrFail());
    }

    /** A ticket the token's user may not see does not exist as far as they are concerned. */
    private function visible(Request $request, Ticket $ticket): Ticket
    {
        abort_unless($ticket->canBeSeenBy($request->user()), 404, 'The requested resource was not found.');

        return $ticket;
    }

    private function fresh(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->refresh()->load('department', 'service', 'assignee'));
    }
}
