<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\QuoteResource;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;

class QuoteController extends ApiController
{
    public function index(Request $request)
    {
        $query = $this->client($request)->quotes()->where('status', '!=', 'draft')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginate($query, QuoteResource::class, $request);
    }

    public function show(Request $request, string $quote): QuoteResource
    {
        return new QuoteResource($this->find($request, $quote)->load('items'));
    }

    public function accept(Request $request, string $quote, QuoteService $quotes): QuoteResource
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:255']]);
        $accepted = $quotes->accept($this->find($request, $quote), $data['name'] ?? $request->user()->name);

        return new QuoteResource($accepted->load('items', 'invoice'));
    }

    public function decline(Request $request, string $quote, QuoteService $quotes): QuoteResource
    {
        return new QuoteResource($quotes->decline($this->find($request, $quote))->load('items'));
    }

    private function find(Request $request, string $uuid): Quote
    {
        return $this->client($request)->quotes()->where('status', '!=', 'draft')->where('uuid', $uuid)->firstOrFail();
    }
}
