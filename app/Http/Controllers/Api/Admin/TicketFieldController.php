<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\TicketFieldResource;
use App\Models\TicketField;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Custom ticket form fields (per department or global). Readable by staff with `support.read`; changes are administrators only. */
class TicketFieldController extends ApiController
{
    public function index(Request $request)
    {
        $query = TicketField::query()->orderBy('sort')->orderBy('id');
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }
        if ($request->has('staff_only')) {
            $query->where('staff_only', $request->boolean('staff_only'));
        }

        return $this->paginate($query, TicketFieldResource::class, $request);
    }

    public function show(TicketField $field): TicketFieldResource
    {
        return new TicketFieldResource($field);
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can manage ticket fields.');
        $field = TicketField::create($this->payload($request));
        $this->audit('ticket_field.created', $field, 'Created ticket field '.$field->key);

        return (new TicketFieldResource($field->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, TicketField $field): TicketFieldResource
    {
        $this->requireAdmin($request, 'Only administrators can manage ticket fields.');
        $field->update($this->payload($request, $field));
        $this->audit('ticket_field.updated', $field, 'Updated ticket field '.$field->key);

        return new TicketFieldResource($field->refresh());
    }

    /** Answers already stored on tickets are kept. */
    public function destroy(Request $request, TicketField $field)
    {
        $this->requireAdmin($request, 'Only administrators can manage ticket fields.');
        $this->audit('ticket_field.deleted', $field, 'Deleted ticket field '.$field->key);
        $field->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?TicketField $existing = null): array
    {
        $s = $existing ? 'sometimes' : 'required';
        $data = $request->validate([
            // the key names the answer stored on tickets, so it cannot change later
            'key' => $existing ? ['prohibited'] : ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('ticket_fields', 'key')],
            'label' => [$s, 'string', 'max:255'],
            'type' => [$s, Rule::in(array_keys(TicketField::TYPES))],
            'department_id' => ['nullable', 'integer', 'exists:ticket_departments,id'],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255', 'not_regex:/,/'],
            'required' => ['sometimes', 'boolean'],
            'staff_only' => ['sometimes', 'boolean'],
            'help' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $type = $data['type'] ?? $existing?->type;
        $options = array_key_exists('options', $data) ? array_values(array_unique($data['options'] ?? [])) : ($existing?->options ?? []);
        if ($type === 'select' && ! $options) {
            throw ValidationException::withMessages(['options' => ['A choice field needs at least one option.']]);
        }
        $out = array_intersect_key($data, array_flip(['key', 'label', 'type', 'department_id', 'required', 'staff_only', 'help', 'is_active']));
        if (isset($data['sort'])) {
            $out['sort'] = $data['sort'];
        }
        if (array_key_exists('options', $data) || ! $existing) {
            $out['options'] = $type === 'select' ? $options : null;
        }

        return $out;
    }
}
