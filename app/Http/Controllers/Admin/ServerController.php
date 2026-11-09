<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\ProvisioningModule;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Plugins\ModuleRegistry;
use App\Support\Activity;
use App\Support\Fields;
use App\Support\ProvisionResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function index()
    {
        return view('admin.servers.index', [
            'servers' => Server::withCount(['services as live_count' => fn ($q) => $q->whereIn('status', ['active', 'suspended', 'pending'])])->orderBy('name')->get(),
            'modules' => $this->modules->options(),
        ]);
    }

    public function create()
    {
        return view('admin.servers.form', $this->formData(new Server(['use_ssl' => true, 'verify_ssl' => true, 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $server = Server::create($this->payload($request));
        Activity::log('server.created', $server);

        return redirect()->route('admin.servers.index')->with('status', 'Server added. Use “Test connection” to check it.');
    }

    public function edit(Server $server)
    {
        return view('admin.servers.form', $this->formData($server));
    }

    public function update(Request $request, Server $server): RedirectResponse
    {
        $server->update($this->payload($request, $server));
        Activity::log('server.updated', $server);

        return redirect()->route('admin.servers.index')->with('status', 'Server saved.');
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($server->services()->whereIn('status', ['active', 'suspended', 'pending'])->exists()) {
            return back()->with('error', 'This server still hosts services. Move or terminate them first.');
        }
        Activity::log('server.deleted', $server);
        $server->delete();

        return redirect()->route('admin.servers.index')->with('status', 'Server deleted.');
    }

    public function test(Server $server): RedirectResponse
    {
        $module = $this->modules->get($server->module);
        if (! $module) {
            return back()->with('error', 'The plugin for this server is not enabled.');
        }
        try {
            $result = $module->testServer($server);
        } catch (\Throwable $e) {
            report($e);
            $result = ProvisionResult::fail($e->getMessage());
        }

        return back()->with($result->success ? 'status' : 'error', ($result->success ? 'Connection OK. ' : 'Connection failed: ').$result->message);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?Server $existing = null): array
    {
        $usable = collect($this->modules->all())->filter(fn (ProvisioningModule $m) => $m->usesServers())->keys()->all();
        $base = $request->validate([
            'module' => ['required', Rule::in($usable)],
            'name' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:5000'],
            'max_services' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $module = $this->modules->get($base['module']);
        $fields = $module->serverFields();
        $input = $request->validate(Fields::rules($fields, 'config.'))['config'] ?? [];
        $config = array_merge($existing && $existing->module === $module->key() ? ($existing->config ?? []) : [], Fields::collect($fields, $input));

        return [
            'module' => $base['module'],
            'name' => $base['name'],
            'hostname' => $base['hostname'],
            'port' => $base['port'] ?? null,
            'username' => $base['username'] ?? null,
            'secret' => ($base['secret'] ?? '') !== '' ? $base['secret'] : $existing?->secret,
            'max_services' => $base['max_services'] ?? null,
            'notes' => $base['notes'] ?? null,
            'config' => $config ?: null,
            'use_ssl' => $request->boolean('use_ssl'),
            'verify_ssl' => $request->boolean('verify_ssl'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** @return array<string, mixed> */
    private function formData(Server $server): array
    {
        return [
            'server' => $server,
            'modules' => collect($this->modules->all())->filter(fn (ProvisioningModule $m) => $m->usesServers())->all(),
        ];
    }
}
