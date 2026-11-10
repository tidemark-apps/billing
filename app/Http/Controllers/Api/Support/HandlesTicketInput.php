<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Resources\TicketMessageResource;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\TicketMessage;
use App\Services\TicketAttachments;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * What the customer and staff ticket endpoints share: attachments (multipart `files[]` or JSON `attachments`), custom
 * field validation against TicketField::rules(), and loading a ticket thread for a resource.
 *
 * Attachments are only ever *decoded* here; every content rule (size, count, dangerous types, allow-list) is enforced by
 * TicketAttachments::problems(), which the ticket service calls before anything is stored.
 */
trait HandlesTicketInput
{
    /** @return array<string, array<int, mixed>> */
    protected function fileRules(): array
    {
        return [
            'files' => ['nullable', 'array'],
            'files.*' => ['file'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['array'],
            'attachments.*.filename' => ['required', 'string', 'max:255'],
            'attachments.*.content_base64' => ['required', 'string'],
        ];
    }

    /** Uploaded files plus decoded JSON attachments, in the shape TicketService expects. @return array<int, mixed> */
    protected function incomingFiles(Request $request): array
    {
        $limits = app(TicketAttachments::class);
        $files = array_values(array_filter(Arr::wrap($request->file('files'))));
        $json = array_values((array) $request->input('attachments', []));
        if (count($files) + count($json) > $limits->maxFiles()) {
            throw ValidationException::withMessages(['attachments' => ['You can attach up to '.$limits->maxFiles().' files.']]);
        }
        $maxEncoded = (int) ceil($limits->maxBytes() / 3) * 4 + 4;
        foreach ($json as $i => $item) {
            $encoded = (string) preg_replace('/\s+/', '', (string) $item['content_base64']);
            if (strlen($encoded) > $maxEncoded) { // refuse before decoding something huge
                throw ValidationException::withMessages(["attachments.$i.content_base64" => [$limits->cleanName((string) $item['filename']).' is larger than '.($limits->maxBytes() / 1024 / 1024).' MB.']]);
            }
            $bytes = base64_decode($encoded, true);
            if ($bytes === false) {
                throw ValidationException::withMessages(["attachments.$i.content_base64" => ['The content must be valid base64.']]);
            }
            $files[] = ['name' => (string) $item['filename'], 'content' => $bytes];
        }

        return $files;
    }

    /**
     * Validate `custom` against the department's fields. Customers only get (and may only answer) the fields that are not
     * staff-only; an unknown key is an error rather than silently dropped. With $partial only the keys sent are checked.
     *
     * @return array<string, mixed>
     */
    protected function validatedCustom(Request $request, Department $department, bool $forCustomer, bool $partial = false): array
    {
        $request->validate(['custom' => ['nullable', 'array']]);
        $input = (array) $request->input('custom', []);
        $fields = $department->applicableFields(forCustomer: $forCustomer)->keyBy('key');
        foreach (array_keys($input) as $key) {
            if (! $fields->has($key)) {
                throw ValidationException::withMessages(["custom.$key" => ['Unknown field.']]);
            }
        }
        $rules = [];
        foreach ($fields as $key => $field) {
            if (! $partial || array_key_exists($key, $input)) {
                $rules["custom.$key"] = $field->rules();
            }
        }
        validator(['custom' => $input], $rules)->validate();

        return $input;
    }

    /** A stored answer for a field, as TicketService stores it. */
    protected function fieldValue(TicketField $field, mixed $value): mixed
    {
        return match ($field->type) {
            'checkbox' => (bool) $value,
            'number' => is_numeric($value) ? $value + 0 : null,
            default => is_scalar($value) ? mb_substr((string) $value, 0, 5000) : null,
        };
    }

    /** Load the thread for a resource: customers get replies only, staff everything. Attachments know their ticket (for download URLs). */
    protected function loadThread(Ticket $ticket, bool $staff): Ticket
    {
        $ticket->load(['department', 'service', 'assignee', 'messages' => fn ($q) => $staff ? $q : $q->where('kind', 'reply'), 'messages.attachments']);
        foreach ($ticket->messages as $message) {
            foreach ($message->attachments as $attachment) {
                $attachment->setRelation('ticket', $ticket);
            }
        }

        return $ticket;
    }

    protected function messageResource(Ticket $ticket, TicketMessage $message): TicketMessageResource
    {
        $message->load('attachments');
        foreach ($message->attachments as $attachment) {
            $attachment->setRelation('ticket', $ticket);
        }

        return new TicketMessageResource($message);
    }
}
