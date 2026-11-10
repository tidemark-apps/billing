<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\TicketField;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Extra questions on the ticket form (administrators only, enforced by the route group). */
class TicketFieldController extends Controller
{
    public function index()
    {
        return view('admin.ticket-fields.index', [
            'fields' => TicketField::with('department')->orderBy('sort')->orderBy('id')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.ticket-fields.form', $this->formData(new TicketField(['type' => 'text', 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $field = TicketField::create($this->validated($request));
        Activity::log('ticket_field.created', $field, "Added ticket field {$field->label}");

        return redirect()->route('admin.ticket-fields.index')->with('status', 'Field added.');
    }

    public function edit(TicketField $field)
    {
        return view('admin.ticket-fields.form', $this->formData($field));
    }

    public function update(Request $request, TicketField $field): RedirectResponse
    {
        $field->update($this->validated($request, $field));
        Activity::log('ticket_field.updated', $field, "Updated ticket field {$field->label}");

        return redirect()->route('admin.ticket-fields.index')->with('status', 'Field saved.');
    }

    public function destroy(TicketField $field): RedirectResponse
    {
        $field->delete(); // answers already stored on tickets stay in their data, just unlabelled
        Activity::log('ticket_field.deleted', null, "Deleted ticket field {$field->label}");

        return redirect()->route('admin.ticket-fields.index')->with('status', 'Field deleted. Answers already given stay on their tickets.');
    }

    /** @return array<string, mixed> */
    private function formData(TicketField $field): array
    {
        return ['field' => $field, 'departments' => Department::orderBy('sort')->orderBy('name')->get()];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?TicketField $field = null): array
    {
        $request->merge(['key' => Str::slug((string) ($request->input('key') ?: $request->input('label')), '_'), 'department_id' => $request->input('department_id') ?: null]);
        $d = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', Rule::unique('ticket_fields', 'key')
                ->where(fn ($q) => $request->input('department_id') ? $q->where('department_id', $request->input('department_id')) : $q->whereNull('department_id'))
                ->ignore($field?->id)],
            'type' => ['required', Rule::in(array_keys(TicketField::TYPES))],
            'choices' => ['nullable', 'string', 'max:5000', Rule::requiredIf($request->input('type') === 'select')],
            'required' => ['boolean'],
            'staff_only' => ['boolean'],
            'help' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', 'exists:ticket_departments,id'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
        ], ['key.regex' => 'Use lowercase letters, numbers and underscores only.', 'choices.required' => 'Add at least one choice, one per line.']);

        $options = null;
        if ($d['type'] === 'select') {
            $options = collect(preg_split('/\R/', (string) ($d['choices'] ?? '')))
                ->map(fn ($o) => trim(str_replace(',', '', $o))) // commas would break the `in:` rule
                ->filter()->unique()->values()->all();
            if (! $options) {
                throw ValidationException::withMessages(['choices' => 'Add at least one choice, one per line.']);
            }
        }
        unset($d['choices']);
        $d['options'] = $options;
        $d['sort'] = (int) ($d['sort'] ?? 0);

        return $d;
    }
}
