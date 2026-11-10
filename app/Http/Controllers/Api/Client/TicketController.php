<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\HandlesTicketInput;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\TicketResource;
use App\Models\Department;
use App\Models\Ticket;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The customer's support tickets. Always scoped to the token owner's client (404 for anything else), replies only:
 * internal notes, system lines, their attachments and staff-only custom fields never leave the server.
 */
class TicketController extends ApiController
{
    use HandlesTicketInput;

    public function __construct(private readonly TicketService $tickets, private readonly TicketAttachments $attachments) {}

    public function departments(Request $request)
    {
        return DepartmentResource::collection(Department::listed()->get());
    }

    public function index(Request $request)
    {
        $query = Ticket::where('client_id', $this->client($request)->id)->with('department', 'service')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginate($query, TicketResource::class, $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('ticket_departments', 'id')->where('is_active', true)->where('is_public', true)],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:50000'],
            'service' => ['nullable', 'uuid'],
        ] + $this->fileRules());
        $client = $this->client($request);
        $department = Department::findOrFail($data['department_id']);
        $service = null;
        if (! empty($data['service'])) {
            $service = $client->services()->where('uuid', $data['service'])->first()
                ?? throw ValidationException::withMessages(['service' => ['That service was not found.']]);
        }
        $custom = $this->validatedCustom($request, $department, forCustomer: true);
        $files = $this->incomingFiles($request);

        $ticket = $this->tickets->open([
            'department' => $department, 'subject' => $data['subject'], 'body' => $data['message'], 'client' => $client, 'user' => $request->user(),
            'service' => $service, 'custom' => $custom, 'source' => 'api', 'files' => $files,
        ]);

        return (new TicketResource($this->loadThread($ticket, false)))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $ticket): TicketResource
    {
        return new TicketResource($this->loadThread($this->find($request, $ticket), false));
    }

    public function reply(Request $request, string $ticket)
    {
        $model = $this->find($request, $ticket);
        $data = $request->validate(['message' => ['nullable', 'string', 'max:50000']] + $this->fileRules());
        $message = $this->tickets->reply($model, (string) ($data['message'] ?? ''), $request->user(), false, $this->incomingFiles($request), ['source' => 'api']);

        return $this->messageResource($model, $message)->response()->setStatusCode(201);
    }

    public function close(Request $request, string $ticket): TicketResource
    {
        $model = $this->tickets->closeByCustomer($this->find($request, $ticket), $request->user());

        return new TicketResource($this->loadThread($model, false));
    }

    public function download(Request $request, string $ticket, string $attachment)
    {
        $model = $this->find($request, $ticket);
        $file = $model->attachments()->where('uuid', $attachment)
            ->whereHas('message', fn ($q) => $q->where('kind', 'reply'))->firstOrFail();

        return $this->attachments->download($file);
    }

    private function find(Request $request, string $uuid): Ticket
    {
        return Ticket::where('client_id', $this->client($request)->id)->where('uuid', $uuid)->firstOrFail();
    }
}
