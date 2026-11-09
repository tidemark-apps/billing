<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\ProvisioningModule;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\FieldSchema;
use App\Http\Resources\ServerResource;
use App\Models\Server;
use App\Plugins\ModuleRegistry;
use App\Support\Fields;
use App\Support\ProvisionResult;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Provisioning servers (cPanel/WHM, Enhance, ...). The connection secret is write-only. */
class ServerController extends ApiController
{
    private const LIVE = ['active', 'suspended', 'pending'];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function index(Request $request)
    {
        $query = Server::withCount(['services as live_count' => fn ($q) => $q->whereIn('status', self::LIVE)])->orderBy('name');
        if ($request->filled('module')) {
            $query->where('module', $request->query('module'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $this->paginate($query, ServerResource::class, $request);
    }

    public function show(Server $server): ServerResource
    {
        return new ServerResource($server->loadCount(['services as live_count' => fn ($q) => $q->whereIn('status', self::LIVE)]));
    }

    public function store(Request $request)
    {
        $server = Server::create($this->payload($request));
        $this->audit('server.created', $server);

        return (new ServerResource($server->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Server $server): ServerResource
    {
        $server->update($this->payload($request, $server));
        $this->audit('server.updated', $server);

        return new ServerResource($server->refresh());
    }

    public function destroy(Request $request, Server $server)
    {
        $this->requireAdmin($request, 'Only administrators can delete servers.');
        if ($server->services()->whereIn('status', self::LIVE)->exists()) {
            throw new \DomainException('This server still hosts services. Move or terminate them first.');
        }
        $this->audit('server.deleted', $server);
        $server->delete();

        return response()->noContent();
    }

    /** Ask the module to connect to the server. A failed connection is a result (success=false), not an HTTP error. */
    public function test(Server $server)
    {
        $module = $this->modules->get($server->module) ?? throw new \DomainException('The plugin for this server is not enabled.');
        try {
            $result = $module->testServer($server);
        } catch (\Throwable $e) {
            report($e);
            $result = ProvisionResult::fail($e->getMessage());
        }

        return response()->json(['data' => ['success' => $result->success, 'message' => $result->message]]);
    }

    /**
     * Validated columns for a create (full) or update (partial). Booleans default on create, are left alone on update.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request, ?Server $existing = null): array
    {
        $s = $existing ? 'sometimes' : 'required';
        $usable = collect($this->modules->all())->filter(fn (ProvisioningModule $m) => $m->usesServers())->keys()->all();
        $base = $request->validate([
            'module' => [$s, Rule::in($usable)],
            'name' => [$s, 'string', 'max:255'],
            'hostname' => [$s, 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:5000'],
            'max_services' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'use_ssl' => ['nullable', 'boolean'],
            'verify_ssl' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'config' => ['nullable', 'array'],
        ]);
        $out = collect($base)->only(['module', 'name', 'hostname', 'port', 'username', 'max_services', 'notes'])->all();
        foreach (['use_ssl', 'verify_ssl', 'is_active'] as $flag) {
            if (array_key_exists($flag, $base)) {
                $out[$flag] = (bool) $base[$flag];
            } elseif (! $existing) {
                $out[$flag] = true;
            }
        }
        if (! empty($base['secret'])) {
            $out['secret'] = $base['secret']; // an empty or missing secret keeps the stored one
        }

        $module = $this->modules->get($base['module'] ?? $existing?->module);
        if ($module && (array_key_exists('config', $base) || array_key_exists('module', $base))) {
            $fields = $module->serverFields();
            $input = $base['config'] ?? [];
            FieldSchema::rejectUnknown($fields, $input, 'config');
            $kept = $existing && $existing->module === $module->key() ? ($existing->config ?? []) : [];
            $rules = Fields::rules(array_values(array_filter($fields, fn ($f) => array_key_exists($f['key'], $input) || (! empty($f['required']) && ! isset($kept[$f['key']])))), 'config.');
            validator(['config' => $input], $rules)->validate();
            $out['config'] = array_merge($kept, FieldSchema::collectPresent($fields, $input)) ?: null;
        }

        return $out;
    }
}
