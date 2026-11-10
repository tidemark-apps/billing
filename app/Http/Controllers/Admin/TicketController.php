<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Department;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketField;
use App\Models\User;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The staff ticket desk. Every action authorises with Ticket::canBeSeenBy (403 otherwise); the work itself is done
 * by TicketService so the rules are the same as for the API and email intake.
 */
class TicketController extends Controller
{
    public const TABS = ['mine' => 'My tickets', 'unassigned' => 'Unassigned', 'open' => 'All open', 'awaiting' => 'Awaiting staff', 'closed' => 'Closed'];

    public function __construct(private readonly TicketService $tickets, private readonly TicketAttachments $files) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'open';
        $filters = array_filter([
            'q' => trim((string) $request->query('q')),
            'department_id' => $request->query('department_id'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
        ], fn ($v) => $v !== null && $v !== '');

        $base = Ticket::visibleTo($user);
        $counts = [];
        foreach (self::TABS as $key => $label) {
            $counts[$key] = $this->tab(clone $base, $key, $user)->count();
        }

        $query = $this->tab(clone $base, $tab, $user)->with(['department', 'assignee', 'client']);
        if (isset($filters['q'])) {
            $term = $filters['q'];
            $query->where(fn ($w) => $w->whereContains('number', $term)->orWhereContains('subject', $term)
                ->orWhereContains('requester_name', $term)->orWhereContains('requester_email', $term));
        }
        if (isset($filters['department_id'])) {
            $query->where('department_id', (int) $filters['department_id']);
        }
        if (isset($filters['status']) && TicketStatus::tryFrom($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['priority']) && TicketPriority::tryFrom($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        return view('admin.tickets.index', [
            'tickets' => $query->orderByDesc('last_reply_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $counts,
            'filters' => $filters,
            'departments' => Department::orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.tickets.create', [
            'departments' => $this->workableDepartments($request->user()),
            'clients' => Client::orderBy('name')->limit(1000)->get(),
            'services' => Service::with('client', 'product')->orderByDesc('id')->limit(500)->get(),
            'clientId' => (int) $request->query('client_id'),
            'fieldsByDepartment' => $this->workableDepartments($request->user())->mapWithKeys(fn (Department $d) => [$d->id => $d->applicableFields()]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $departments = $this->workableDepartments($user);
        $department = $departments->firstWhere('id', (int) $request->input('department_id'));
        $rules = [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'department_id' => ['required', 'integer', Rule::in($departments->pluck('id')->all())],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:50000'],
            'priority' => ['required', Rule::in(array_map(fn ($p) => $p->value, TicketPriority::cases()))],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ] + ($department ? $this->customRules($department) : ['custom' => ['nullable', 'array']]);
        $data = $request->validate($rules);

        $client = Client::findOrFail($data['client_id']);
        try {
            $ticket = $this->tickets->open([
                'department' => $department,
                'client' => $client,
                'service' => ! empty($data['service_id']) ? Service::find($data['service_id']) : null,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'priority' => $data['priority'],
                'custom' => $data['custom'] ?? [],
                'source' => 'admin',
                'opened_by' => $user,
                'files' => $request->file('attachments', []),
            ]);
        } catch (\DomainException|ValidationException $e) {
            return back()->withInput()->with('error', $this->message($e));
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', "Opened {$ticket->number}.");
    }

    public function show(Request $request, Ticket $ticket)
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        $ticket->load(['department', 'client', 'service.product', 'assignee']);

        $fields = $ticket->department?->applicableFields() ?? collect();
        $labels = TicketField::whereIn('key', array_keys($ticket->custom_fields ?? []))->get()->groupBy('key');

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'messages' => $ticket->messages()->with(['attachments', 'user'])->get(),
            'fields' => $fields,
            'orphans' => collect($ticket->custom_fields ?? [])->except($fields->pluck('key')->all())
                ->mapWithKeys(fn ($v, $k) => [($labels[$k][0]->label ?? $k) => $v]),
            'assignable' => $this->tickets->assignable($ticket),
            'departments' => Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                ->filter(fn (Department $d) => $d->id === $ticket->department_id || $user->isAdmin() || $this->tickets->canWork($user, $d->id))->values(),
            'maxFiles' => $this->files->maxFiles(),
            'maxMb' => (int) ($this->files->maxBytes() / 1048576),
            'allowed' => $this->files->allowedExtensions(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:50000'],
            'status' => ['nullable', Rule::in(['answered', 'on_hold', 'closed'])],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ]);

        return $this->attempt(fn () => $this->tickets->reply(
            $ticket, (string) ($data['body'] ?? ''), $request->user(), true, $request->file('attachments', []),
            ['source' => 'admin', 'status' => TicketStatus::tryFrom($data['status'] ?? '') ?? TicketStatus::Answered],
        ), 'Reply sent.', $ticket, withInput: true);
    }

    public function note(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:50000'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ]);

        return $this->attempt(fn () => $this->tickets->note($ticket, $request->user(), (string) ($data['note'] ?? ''), $request->file('attachments', [])), 'Internal note added. The customer is not told.', $ticket, withInput: true);
    }

    public function status(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $data = $request->validate(['status' => ['required', Rule::in(array_map(fn ($s) => $s->value, TicketStatus::cases()))]]);

        return $this->attempt(fn () => $this->tickets->setStatus($ticket, TicketStatus::from($data['status']), $request->user()), 'Status updated.', $ticket);
    }

    public function claim(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);

        return $this->attempt(fn () => $this->tickets->claim($ticket, $request->user()), 'Assigned to you.', $ticket);
    }

    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $data = $request->validate(['user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $to = ! empty($data['user_id']) ? User::find($data['user_id']) : null;

        return $this->attempt(fn () => $this->tickets->assign($ticket, $to, $request->user()), $to ? "Assigned to {$to->name}." : 'Unassigned.', $ticket);
    }

    public function lock(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);

        return $this->attempt(fn () => $this->tickets->lock($ticket, $request->user()), 'Locked. The customer can no longer reply.', $ticket);
    }

    public function unlock(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);

        return $this->attempt(fn () => $this->tickets->unlock($ticket, $request->user()), 'Unlocked.', $ticket);
    }

    public function priority(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $data = $request->validate(['priority' => ['required', Rule::in(array_map(fn ($p) => $p->value, TicketPriority::cases()))]]);

        return $this->attempt(fn () => $this->tickets->setPriority($ticket, TicketPriority::from($data['priority']), $request->user()), 'Priority updated.', $ticket);
    }

    public function transfer(Request $request, Ticket $ticket): RedirectResponse
    {
        $user = $request->user();
        $this->authorizeTicket($user, $ticket);
        $data = $request->validate(['department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')->where('is_active', true)]]);
        $to = Department::findOrFail($data['department_id']);
        if (! $user->isAdmin() && ! $this->tickets->canWork($user, $to->id)) {
            return back()->with('error', 'You can only move tickets to departments you work in.');
        }

        $response = $this->attempt(fn () => $this->tickets->transfer($ticket, $to, $user), "Moved to {$to->name}.", $ticket);
        if (! $ticket->refresh()->canBeSeenBy($user)) {
            return redirect()->route('admin.tickets.index')->with('status', "Moved {$ticket->number} to {$to->name}. It is no longer in your queue.");
        }

        return $response;
    }

    /** Staff-only custom fields are editable by staff; answers the customer gave stay as they were. */
    public function fields(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        $editable = ($ticket->department?->applicableFields() ?? collect())->where('staff_only', true);
        if ($editable->isEmpty()) {
            return back();
        }
        $data = $request->validate($this->rulesFor($editable));
        $values = $ticket->custom_fields ?? [];
        foreach ($editable as $field) {
            $v = Arr::get($data, 'custom.'.$field->key);
            if ($v === null || $v === '' || ($field->type === 'checkbox' && ! $v)) {
                unset($values[$field->key]);
            } else {
                $values[$field->key] = $field->type === 'checkbox' ? true : ($field->type === 'number' ? $v + 0 : (string) $v);
            }
        }
        $ticket->update(['custom_fields' => $values ?: null]);

        return back()->with('status', 'Details saved.');
    }

    public function download(Request $request, Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        $this->authorizeTicket($request->user(), $ticket);
        abort_unless($attachment->ticket_id === $ticket->id, 404);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $number = $ticket->number;
        $this->tickets->delete($ticket, $request->user());

        return redirect()->route('admin.tickets.index')->with('status', "Deleted {$number}.");
    }

    private function authorizeTicket(User $user, Ticket $ticket): void
    {
        abort_unless($ticket->canBeSeenBy($user), 403, 'You do not have access to this ticket.');
    }

    private function tab($query, string $tab, User $user)
    {
        return match ($tab) {
            'mine' => $query->where('assigned_to', $user->id)->open(),
            'unassigned' => $query->whereNull('assigned_to')->open(),
            'awaiting' => $query->whereIn('status', ['open', 'customer_reply']),
            'closed' => $query->where('status', 'closed'),
            default => $query->open(),
        };
    }

    /** Departments this person may open tickets in: active ones they work in (admins: all). */
    private function workableDepartments(User $user)
    {
        return Department::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->filter(fn (Department $d) => $this->tickets->canWork($user, $d->id))->values();
    }

    /** @return array<string, array<int, mixed>> rules for custom[key] inputs, staff-only ones included */
    private function customRules(Department $department): array
    {
        return $this->rulesFor($department->applicableFields());
    }

    /** @param  iterable<TicketField>  $fields @return array<string, array<int, mixed>> */
    private function rulesFor(iterable $fields): array
    {
        $rules = ['custom' => ['nullable', 'array']];
        foreach ($fields as $field) {
            $rules['custom.'.$field->key] = $field->rules();
        }

        return $rules;
    }

    /** Run a service call, flashing its DomainException / ValidationException message instead of failing. */
    private function attempt(Closure $work, string $done, Ticket $ticket, bool $withInput = false): RedirectResponse
    {
        try {
            $work();
        } catch (\DomainException|ValidationException $e) {
            $back = back()->with('error', $this->message($e));

            return $withInput ? $back->withInput() : $back;
        }

        return redirect()->route('admin.tickets.show', $ticket)->with('status', $done);
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
    }
}
