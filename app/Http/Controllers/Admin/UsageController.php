<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\UsageRecord;
use App\Services\UsageService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UsageController extends Controller
{
    public function __construct(private readonly UsageService $usage) {}

    public function store(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'meter' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'recorded_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);
        try {
            $record = $this->usage->record(
                $service,
                $data['meter'],
                (string) $data['quantity'],
                filled($data['recorded_at'] ?? null) ? Carbon::parse($data['recorded_at']) : null,
                $data['idempotency_key'] ?? null,
                'admin',
                ['user_id' => $request->user()->id],
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return back()->withErrors(['usage' => $e->getMessage()])->withInput();
        }
        Activity::log('usage.recorded', $service, 'Recorded '.$data['quantity'].' of '.$data['meter']);

        return back()->with('status', $record->wasRecentlyCreated ? 'Usage recorded.' : 'That idempotency key was already used; nothing new was recorded.');
    }

    /** Remove a record that has not been invoiced yet (e.g. a typo). */
    public function destroy(Service $service, int $record): RedirectResponse
    {
        $row = UsageRecord::where('service_id', $service->id)->findOrFail($record);
        if ($row->isInvoiced()) {
            return back()->withErrors(['usage' => 'That usage has already been invoiced.']);
        }
        $row->delete();
        Activity::log('usage.deleted', $service, 'Deleted a usage record');

        return back()->with('status', 'Usage record deleted.');
    }
}
