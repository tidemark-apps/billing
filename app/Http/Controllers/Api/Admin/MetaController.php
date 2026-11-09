<?php

namespace App\Http\Controllers\Api\Admin;

use App\Events\DomainEvent;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\FieldSchema;
use App\Http\Resources\UserResource;
use App\Plugins\ModuleRegistry;
use App\Support\Abilities;
use Illuminate\Http\Request;

/** Catalogues clients need to build forms and tokens: webhook events, provisioning modules, token abilities, the caller. */
class MetaController extends ApiController
{
    public function events()
    {
        return response()->json(['data' => collect(DomainEvent::catalog())->map(fn ($d, $name) => ['name' => $name, 'description' => $d])->values()]);
    }

    /** Provisioning modules from enabled plugins, with the fields their products and servers accept. */
    public function modules(ModuleRegistry $modules)
    {
        return response()->json(['data' => collect($modules->all())->map(fn ($m, $key) => [
            'key' => $key,
            'label' => $m->label(),
            'description' => $m->description(),
            'uses_servers' => $m->usesServers(),
            'product_fields' => FieldSchema::schema($m->productFields()),
            'server_fields' => FieldSchema::schema($m->serverFields()),
            'admin_actions' => $m->adminActions(),
        ])->values()]);
    }

    public function abilities()
    {
        return response()->json(['data' => collect(Abilities::catalog())->map(fn ($list) => collect($list)->map(fn ($d, $name) => ['name' => $name, 'description' => $d])->values())->all()]);
    }

    /** The staff user behind the token and what the token may do. */
    public function me(Request $request)
    {
        $token = $request->attributes->get('api_token');

        return response()->json(['data' => (new UserResource($request->user()))->resolve($request) + [
            'token' => ['id' => $token->id, 'name' => $token->name, 'abilities' => $token->abilities, 'full_access' => $token->abilities === null, 'expires_at' => $token->expires_at?->toIso8601String()],
        ]]);
    }
}
