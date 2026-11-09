<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The audit trail: what staff (UI and API) did, what the system did. Filter by subject to get one record's history. */
class ActivityController extends ApiController
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z_]+$/'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $query = ActivityLog::with('user')->latest('id');

        if (! empty($f['action'])) {
            // "invoice.*" matches every invoice action
            str_ends_with($f['action'], '*') ? $query->where('action', 'like', rtrim(substr($f['action'], 0, -1), '%_').'%') : $query->where('action', $f['action']);
        }
        if (! empty($f['subject_type'])) {
            $class = 'App\\Models\\'.Str::studly($f['subject_type']);
            $query->where('subject_type', class_exists($class) ? (new $class)->getMorphClass() : $f['subject_type']);
        }

        $query->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        return $this->paginate($query, ActivityLogResource::class, $request);
    }
}
