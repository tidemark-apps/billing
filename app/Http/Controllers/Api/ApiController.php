<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

abstract class ApiController extends Controller
{
    /** Paginate a query into the {data, links, meta} envelope. ?page and ?per_page (1–100, default 25). */
    protected function paginate(Builder|Relation $query, string $resource, Request $request): AnonymousResourceCollection
    {
        $per = min(100, max(1, (int) $request->query('per_page', 25)));

        return $resource::collection($query->paginate($per)->appends($request->query()));
    }

    protected function client(Request $request): Client
    {
        return $request->user()->client;
    }

    /** Some actions need an administrator even when the token has the ability (the same rule as the admin web UI). */
    protected function requireAdmin(Request $request, string $message = 'Only administrators can do this.'): void
    {
        abort_unless($request->user()->isAdmin(), 403, $message);
    }

    /** Write to the activity log, marked as coming from the API. */
    protected function audit(string $action, ?Model $subject = null, ?string $description = null, array $properties = []): void
    {
        Activity::log($action, $subject, $description, $properties + ['via' => 'api']);
    }
}
