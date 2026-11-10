<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Support departments. Any staff token with `support.read` can list them (mail connection details and staff lists are left
 * out unless the token's user is an administrator); changes are administrators only. The IMAP password is write-only.
 */
class TicketDepartmentController extends ApiController
{
    public function index(Request $request)
    {
        return $this->paginate(Department::query()->orderBy('sort')->orderBy('name'), DepartmentResource::class, $request);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department);
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request, 'Only administrators can manage departments.');
        [$attributes, $staff] = $this->payload($request);
        $attributes['slug'] ??= $this->uniqueSlug((string) $attributes['name']);
        $department = Department::create($attributes);
        if ($staff !== null) {
            $department->staff()->sync($staff);
        }
        $this->audit('department.created', $department, 'Created department '.$department->name);

        return (new DepartmentResource($department->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        $this->requireAdmin($request, 'Only administrators can manage departments.');
        [$attributes, $staff] = $this->payload($request, $department);
        $department->update($attributes);
        if ($staff !== null) {
            $department->staff()->sync($staff);
        }
        $this->audit('department.updated', $department, 'Updated department '.$department->name);

        return new DepartmentResource($department->refresh());
    }

    public function destroy(Request $request, Department $department)
    {
        $this->requireAdmin($request, 'Only administrators can manage departments.');
        if ($department->tickets()->exists()) {
            throw new \DomainException('This department still has tickets. Move or delete them first, or deactivate the department instead.');
        }
        $this->audit('department.deleted', $department, 'Deleted department '.$department->name);
        $department->delete();

        return response()->noContent();
    }

    /** @return array{0: array<string, mixed>, 1: ?array<int, int>} columns and (when sent) the staff ids */
    private function payload(Request $request, ?Department $existing = null): array
    {
        $s = $existing ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$s, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('ticket_departments', 'slug')->ignore($existing?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'auto_reply' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('ticket_departments', 'email')->ignore($existing?->id)],
            'imap_enabled' => ['sometimes', 'boolean'],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_encryption' => ['nullable', Rule::in(['ssl', 'starttls', 'none'])],
            'imap_validate_cert' => ['sometimes', 'boolean'],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:1000'],
            'clear_imap_password' => ['sometimes', 'boolean'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
            'imap_after' => ['nullable', Rule::in(['seen', 'delete'])],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'staff'])],
        ]);

        $out = array_intersect_key($data, array_flip(['name', 'slug', 'description', 'sort', 'is_public', 'is_active', 'auto_reply', 'email', 'imap_enabled', 'imap_host', 'imap_validate_cert', 'imap_username']));
        foreach (['imap_port', 'imap_encryption', 'imap_folder', 'imap_after'] as $column) { // NOT NULL columns: null keeps the current value / default
            if (isset($data[$column])) {
                $out[$column] = $data[$column];
            }
        }
        if (isset($out['email'])) {
            $out['email'] = strtolower($out['email']);
        }
        if (! empty($data['imap_password'])) {
            $out['imap_password'] = $data['imap_password']; // blank or missing keeps the stored one
        } elseif (! empty($data['clear_imap_password'])) {
            $out['imap_password'] = null;
        }
        foreach (['slug', 'sort'] as $column) { // NOT NULL (or generated) columns: null keeps the current value
            if (array_key_exists($column, $out) && $out[$column] === null) {
                unset($out[$column]);
            }
        }

        $enabled = array_key_exists('imap_enabled', $out) ? $out['imap_enabled'] : (bool) $existing?->imap_enabled;
        $host = array_key_exists('imap_host', $out) ? $out['imap_host'] : $existing?->imap_host;
        $user = array_key_exists('imap_username', $out) ? $out['imap_username'] : $existing?->imap_username;
        if ($enabled && (! $host || ! $user)) {
            throw ValidationException::withMessages(['imap_host' => ['A host and username are needed to turn the mailbox on.']]);
        }

        return [$out, array_key_exists('staff_ids', $data) ? array_map('intval', $data['staff_ids'] ?? []) : null];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'department';
        $slug = $base;
        for ($i = 2; Department::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
