<?php

namespace App\Http\Controllers\Portal;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Customer support tickets. Every lookup is scoped to the signed-in customer's client; customers only ever see public replies. */
class TicketController extends PortalController
{
    public function __construct(private readonly TicketService $tickets, private readonly TicketAttachments $attachments) {}

    public function index()
    {
        $tickets = Ticket::where('client_id', $this->client()->id)->with('department')
            ->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
            ->orderByDesc('last_reply_at')->orderByDesc('id')
            ->paginate(20);

        return view('portal.tickets.index', ['tickets' => $tickets]);
    }

    public function create(Request $request)
    {
        $listed = Department::listed()->get();
        $selected = null;
        if ($slug = $request->query('department')) {
            $selected = Department::openable()->where('slug', (string) $slug)->first();
        }
        $selected ??= $listed->first();
        // An unlisted department reached by direct link is shown as the only choice.
        $locked = $selected && ! $listed->contains('id', $selected->id);
        $departments = $locked ? collect([$selected]) : $listed;
        $service = $request->query('service') ? $this->client()->services()->where('uuid', (string) $request->query('service'))->first() : null;

        return view('portal.tickets.create', [
            'departments' => $departments,
            'selected' => $selected,
            'locked' => $locked,
            'fields' => $departments->mapWithKeys(fn (Department $d) => [$d->slug => $d->applicableFields(forCustomer: true)]),
            'services' => $this->client()->services()->with('product')->latest()->get(),
            'selectedService' => $service?->uuid,
            'limits' => $this->limits(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->client();
        $data = $request->validate([
            'department' => ['required', 'string'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'service' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
        ]);
        $department = Department::openable()->where('slug', $data['department'])->first();
        if (! $department) {
            return back()->withInput()->withErrors(['department' => 'Choose a department.']);
        }
        $service = null;
        if (filled($data['service'] ?? null)) {
            $service = $client->services()->where('uuid', $data['service'])->first();
            if (! $service) {
                return back()->withInput()->withErrors(['service' => 'Choose one of your own services.']);
            }
        }

        // Only the chosen department's customer-visible fields are validated and kept.
        $fields = $department->applicableFields(forCustomer: true);
        $rules = [];
        $labels = [];
        foreach ($fields as $field) {
            $rules['custom.'.$field->key] = $field->rules();
            $labels['custom.'.$field->key] = $field->label;
        }
        $custom = $rules ? Validator::make($request->all(), $rules, [], $labels)->validate()['custom'] ?? [] : [];

        try {
            $ticket = $this->tickets->open([
                'department' => $department, 'subject' => $data['subject'], 'body' => $data['body'],
                'client' => $client, 'user' => auth()->user(), 'service' => $service, 'custom' => $custom,
                'source' => 'portal', 'files' => $this->files($request),
            ]);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect()->route('portal.tickets.show', $ticket->uuid)->with('status', 'Your ticket '.$ticket->number.' has been opened.');
    }

    public function show(string $ticket)
    {
        $ticket = $this->ticket($ticket);
        $ticket->load(['department', 'service.product']);
        $messages = $ticket->publicMessages()->with('attachments')->get();
        $fields = $ticket->department
            ? $ticket->department->applicableFields(forCustomer: true)->filter(fn ($f) => isset(($ticket->custom_fields ?? [])[$f->key]))
            : collect();

        return view('portal.tickets.show', ['ticket' => $ticket, 'messages' => $messages, 'fields' => $fields, 'limits' => $this->limits()]);
    }

    public function reply(Request $request, string $ticket): RedirectResponse
    {
        $ticket = $this->ticket($ticket);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:20000'], 'attachments' => ['nullable', 'array']]);
        if ($ticket->is_locked) {
            return back()->with('error', 'This ticket is locked, so it cannot be replied to.');
        }
        try {
            $this->tickets->reply($ticket, (string) ($data['body'] ?? ''), auth()->user(), false, $this->files($request));
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.tickets.show', $ticket->uuid)->with('status', 'Your reply has been sent.');
    }

    public function close(string $ticket): RedirectResponse
    {
        $ticket = $this->ticket($ticket);
        try {
            $this->tickets->closeByCustomer($ticket, auth()->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.tickets.show', $ticket->uuid)->with('status', 'Ticket closed.');
    }

    public function download(string $ticket, string $attachment): StreamedResponse
    {
        $ticket = $this->ticket($ticket);
        $attachment = TicketAttachment::where('uuid', $attachment)->where('ticket_id', $ticket->id)->firstOrFail();
        abort_unless($attachment->message?->kind === 'reply', 404);

        return $this->attachments->download($attachment);
    }

    private function ticket(string $uuid): Ticket
    {
        return Ticket::where('client_id', $this->client()->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<int, UploadedFile> */
    private function files(Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments', [])));
    }

    /** @return array{count: int, mb: int|float, extensions: array<int, string>} */
    private function limits(): array
    {
        return ['count' => $this->attachments->maxFiles(), 'mb' => $this->attachments->maxBytes() / 1024 / 1024, 'extensions' => $this->attachments->allowedExtensions()];
    }
}
