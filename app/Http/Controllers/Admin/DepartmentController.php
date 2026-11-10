<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\Support\ImapPuller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Support departments (administrators only, enforced by the route group). */
class DepartmentController extends Controller
{
    public function index()
    {
        return view('admin.departments.index', [
            'departments' => Department::withCount(['tickets', 'staff'])->orderBy('sort')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('admin.departments.form', $this->formData(new Department));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $department = Department::create($data['attributes']);
        $department->staff()->sync($data['staff']);
        Activity::log('department.created', $department, "Created department {$department->name}");

        return redirect()->route('admin.departments.edit', $department)->with('status', 'Department added.');
    }

    public function edit(Department $department)
    {
        return view('admin.departments.form', $this->formData($department));
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $this->validated($request, $department);
        $department->update($data['attributes']);
        $department->staff()->sync($data['staff']);
        Activity::log('department.updated', $department, "Updated department {$department->name}");

        return redirect()->route('admin.departments.edit', $department)->with('status', 'Department saved.');
    }

    /** Tests the connection with what is in the form (nothing is saved); a blank password falls back to the saved one. */
    public function test(Request $request, Department $department): RedirectResponse
    {
        $probe = $department->replicate();
        $probe->imap_password = $department->imap_password;
        foreach (['imap_host', 'imap_username', 'imap_folder', 'imap_encryption'] as $key) {
            if ($request->filled($key)) {
                $probe->{$key} = (string) $request->input($key);
            }
        }
        if ($request->filled('imap_port')) {
            $probe->imap_port = (int) $request->input('imap_port');
        }
        if ($request->has('imap_validate_cert')) {
            $probe->imap_validate_cert = $request->boolean('imap_validate_cert');
        }
        if (($request->input('imap_password') ?? '') !== '') {
            $probe->imap_password = (string) $request->input('imap_password');
        }
        try {
            $result = app(ImapPuller::class)->test($probe);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        return redirect()->route('admin.departments.edit', $department)->with($result['ok'] ? 'status' : 'error', ($result['ok'] ? 'Connection works: ' : 'Connection failed: ').$result['message'].($result['ok'] ? '' : ' (signed in as "'.$probe->imap_username.'" to '.$probe->imap_host.':'.$probe->imap_port.' using '.($probe->imap_encryption === 'none' ? 'no encryption' : $probe->imap_encryption).($probe->imap_password === null || $probe->imap_password === '' ? ', with NO password' : '').')'));
    }

    public function destroy(Department $department): RedirectResponse
    {
        $count = $department->tickets()->count();
        if ($count > 0) {
            return back()->with('error', "{$department->name} still has {$count} ticket".($count === 1 ? '' : 's').'. Move them to another department first, or mark the department inactive instead.');
        }
        $department->fields()->delete();
        $department->staff()->detach();
        $department->delete();
        Activity::log('department.deleted', null, "Deleted department {$department->name}");

        return redirect()->route('admin.departments.index')->with('status', 'Department deleted.');
    }

    /** @return array<string, mixed> */
    private function formData(Department $department): array
    {
        return [
            'department' => $department,
            'people' => User::whereIn('role', ['admin', 'staff'])->where('is_active', true)->orderBy('name')->get(),
            'selected' => $department->exists ? $department->staff()->pluck('users.id')->all() : [],
        ];
    }

    /** @return array{attributes: array<string, mixed>, staff: array<int, int>} */
    private function validated(Request $request, ?Department $department = null): array
    {
        $request->merge([
            'slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name'))),
            'email' => ($e = trim((string) $request->input('email'))) !== '' ? strtolower($e) : null, // addresses are matched case-insensitively
        ]);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('ticket_departments', 'slug')->ignore($department?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'email' => ['nullable', 'email:filter', 'max:255', Rule::unique('ticket_departments', 'email')->ignore($department?->id)],
            'auto_reply' => ['boolean'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'staff'])],
            'imap_enabled' => ['boolean'],
            'imap_host' => ['nullable', 'required_if:imap_enabled,1', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'starttls', 'none'])],
            'imap_validate_cert' => ['boolean'],
            'imap_username' => ['nullable', 'required_if:imap_enabled,1', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:500'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
            'imap_after' => ['required', Rule::in(['seen', 'delete'])],
        ]);
        $staff = array_map('intval', $d['staff'] ?? []);
        unset($d['staff']);
        $d['sort'] = (int) ($d['sort'] ?? 0);
        $d['imap_port'] = (int) ($d['imap_port'] ?? 0 ?: 993);
        $d['imap_folder'] = ($d['imap_folder'] ?? '') ?: 'INBOX';
        if (($d['imap_password'] ?? '') === '') {
            unset($d['imap_password']); // blank keeps the stored one
        }

        return ['attributes' => $d, 'staff' => $staff];
    }
}
