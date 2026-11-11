<?php

namespace App\Services;

use App\Contracts\ProvisioningModule;
use App\Enums\ServiceStatus;
use App\Events\ProvisioningFailed;
use App\Events\ServiceActivated;
use App\Events\ServiceCancelled;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\ServiceUnsuspended;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceLog;
use App\Plugins\ModuleRegistry;
use App\Support\ProvisionResult;

/**
 * Runs a service through its lifecycle, calling the product's provisioning module (if any) at each step.
 * A service whose product has no module is simply tracked: staff and the billing cycle still manage it.
 */
class ProvisioningService
{
    /** Above zero while an action on a parent is being repeated on its add-ons, so each add-on does not re-sync the parent. */
    private int $cascading = 0;

    public function __construct(private readonly ModuleRegistry $modules, private readonly Notifier $notifier) {}

    public function moduleFor(Service $service): ?ProvisioningModule
    {
        $service->loadMissing('product');

        return $this->modules->get($service->product?->module);
    }

    public function activate(Service $service): Service
    {
        if ($service->status === ServiceStatus::Active) {
            return $service;
        }
        $module = $this->moduleFor($service);

        if ($module) {
            if ($module->usesServers() && ! $service->server) {
                $server = $this->pickServer($service, $module);
                if (! $server) {
                    return $this->failed($service, 'create', ProvisionResult::fail('No active server with free capacity is available for the '.$module->label().' module.'));
                }
                $service->update(['server_id' => $server->id]);
                $service->setRelation('server', $server);
            }
            $result = $this->call($service, 'create', fn () => $module->create($service));
            if (! $result->success) {
                return $this->failed($service, 'create', $result);
            }
            $service->remember($result->data)->rememberSecret($result->secrets);
        } else {
            $this->log($service, 'activate', true, 'Activated without a provisioning module.');
        }

        $service->fill([
            'status' => ServiceStatus::Active,
            'activated_at' => now(),
            'provision_error' => null,
            'next_due_date' => $service->next_due_date ?? ($service->billing_cycle->isRecurring() ? $service->billing_cycle->advance(now()) : null),
        ])->save();

        event(new ServiceActivated($service));
        $this->notifier->serviceActivated($service);
        $this->syncParent($service);

        return $service;
    }

    public function suspend(Service $service, ?string $reason = null): Service
    {
        if ($service->status !== ServiceStatus::Active) {
            return $service;
        }
        $module = $this->moduleFor($service);
        if ($module) {
            $result = $this->call($service, 'suspend', fn () => $module->suspend($service, $reason));
            if (! $result->success) {
                return $this->failed($service, 'suspend', $result, keepStatus: true);
            }
        }
        $service->update(['status' => ServiceStatus::Suspended, 'suspended_at' => now(), 'provision_error' => null]);
        event(new ServiceSuspended($service, ['reason' => $reason]));
        $this->cascade($service, fn (Service $a) => $a->data('suspended_with_parent') ? null : $this->suspendWithParent($a, $reason), [ServiceStatus::Active]);
        $this->syncParent($service);

        return $service;
    }

    public function unsuspend(Service $service): Service
    {
        if ($service->status !== ServiceStatus::Suspended) {
            return $service;
        }
        $module = $this->moduleFor($service);
        if ($module) {
            $result = $this->call($service, 'unsuspend', fn () => $module->unsuspend($service));
            if (! $result->success) {
                return $this->failed($service, 'unsuspend', $result, keepStatus: true);
            }
        }
        $service->update(['status' => ServiceStatus::Active, 'suspended_at' => null, 'provision_error' => null]);
        event(new ServiceUnsuspended($service));
        $this->cascade($service, fn (Service $a) => $a->data('suspended_with_parent') ? $this->unsuspendWithParent($a) : null, [ServiceStatus::Suspended]);
        $this->syncParent($service);

        return $service;
    }

    /** Delete the remote account and close the service. */
    public function terminate(Service $service): Service
    {
        if ($service->status === ServiceStatus::Terminated) {
            return $service;
        }
        $module = $this->moduleFor($service);
        if ($module && $service->status->isLive()) {
            $result = $this->call($service, 'terminate', fn () => $module->terminate($service));
            if (! $result->success) {
                return $this->failed($service, 'terminate', $result, keepStatus: true);
            }
        }
        $service->update(['status' => ServiceStatus::Terminated, 'terminated_at' => now(), 'provision_error' => null, 'cancel_at_period_end' => false]);
        event(new ServiceTerminated($service));
        $this->cascade($service, fn (Service $a) => $this->terminate($a), [ServiceStatus::Active, ServiceStatus::Suspended]);
        $this->syncParent($service);

        return $service;
    }

    /** Cancel now (terminating the remote account) or flag it to end at the next renewal date. */
    public function cancel(Service $service, bool $immediately = false, ?string $reason = null): Service
    {
        if ($immediately || $service->status === ServiceStatus::Pending || ! $service->next_due_date || $service->onTrial()) {
            $this->cascade($service, fn (Service $a) => $this->cancel($a, true, 'Cancelled with '.$service->title()), [ServiceStatus::Pending, ServiceStatus::Active, ServiceStatus::Suspended]);
            $service = $this->terminate($service);
            if ($service->status === ServiceStatus::Terminated) {
                $service->update(['status' => ServiceStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
                event(new ServiceCancelled($service));
            }

            return $service;
        }
        $service->update(['cancel_at_period_end' => true, 'cancel_reason' => $reason]);
        $service->addons()->whereIn('status', ['active', 'suspended'])->update(['cancel_at_period_end' => true, 'cancel_reason' => 'Cancelled with '.$service->title()]);
        $this->log($service, 'cancel_requested', true, 'Will be cancelled on '.$service->next_due_date->toDateString().'.');

        return $service;
    }

    public function changePackage(Service $service): Service
    {
        $module = $this->moduleFor($service);
        if ($module && $service->status->isLive()) {
            $result = $this->call($service, 'change_package', fn () => $module->changePackage($service));
            if (! $result->success) {
                return $this->failed($service, 'change_package', $result, keepStatus: true);
            }
            $service->remember($result->data)->save();
        }

        return $service;
    }

    /** A custom module button (e.g. "Log in to cPanel", "Reset password"). */
    public function runAction(Service $service, string $action, string $area): ProvisionResult
    {
        $module = $this->moduleFor($service);
        if (! $module) {
            return ProvisionResult::fail('This service has no provisioning module.');
        }
        $allowed = $area === 'admin' ? $module->adminActions() : $module->clientActions();
        if (! array_key_exists($action, $allowed)) {
            return ProvisionResult::fail('That action is not available.');
        }
        $result = $this->call($service, 'action:'.$action, fn () => $module->runAction($service, $action, $area));
        if ($result->success && ($result->data || $result->secrets)) {
            $service->remember($result->data)->rememberSecret($result->secrets)->save();
        }

        return $result;
    }

    /** Retry services that were paid for but failed to provision. */
    public function retryPending(): int
    {
        $count = 0;
        Service::where('status', ServiceStatus::Pending->value)->whereNotNull('provision_error')
            ->whereHas('invoiceItems.invoice', fn ($q) => $q->where('status', 'paid'))->each(function (Service $s) use (&$count) {
                $this->activate($s);
                $count++;
            });

        return $count;
    }

    /** Repeat an action on a service's add-ons that are in one of the given states. */
    private function cascade(Service $parent, \Closure $act, array $states): void
    {
        if ($parent->isAddon()) {
            return;
        }
        $this->cascading++;
        try {
            $parent->addons()->whereIn('status', array_map(fn (ServiceStatus $s) => $s->value, $states))->get()->each($act);
        } finally {
            $this->cascading--;
        }
    }

    private function suspendWithParent(Service $addon, ?string $reason): void
    {
        $addon = $this->suspend($addon, $reason);
        if ($addon->status === ServiceStatus::Suspended) {
            $addon->remember(['suspended_with_parent' => true])->save();
        }
    }

    private function unsuspendWithParent(Service $addon): void
    {
        $addon = $this->unsuspend($addon);
        if ($addon->status === ServiceStatus::Active) {
            $addon->remember(['suspended_with_parent' => false])->save();
        }
    }

    /** An add-on that grants something changed state: let the parent's module re-read what it is entitled to. */
    private function syncParent(Service $service): void
    {
        if (! $service->isAddon() || $this->cascading > 0 || empty($service->product?->grantList())) {
            return;
        }
        $parent = $service->parent;
        if ($parent && $parent->status->isLive()) {
            $this->changePackage($parent);
        }
    }

    private function pickServer(Service $service, ProvisioningModule $module): ?Server
    {
        $preferred = $service->product?->moduleOption('server_id');
        $query = Server::where('module', $module->key())->where('is_active', true);
        if ($preferred) {
            $query->whereKey($preferred);
        }

        return $query->orderBy('id')->get()->first(fn (Server $s) => $s->hasCapacity());
    }

    /** Run a module call, turning exceptions into failed results so a broken panel never breaks billing. */
    private function call(Service $service, string $action, \Closure $fn): ProvisionResult
    {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            report($e);
            $result = ProvisionResult::fail($e->getMessage());
        }
        $this->log($service, $action, $result->success, $result->message);

        return $result;
    }

    private function failed(Service $service, string $action, ProvisionResult $result, bool $keepStatus = false): Service
    {
        $service->update(['provision_error' => $action.': '.$result->message]);
        if (! $keepStatus) {
            $this->log($service, $action, false, $result->message);
        }
        event(new ProvisioningFailed($service, ['action' => $action, 'message' => $result->message]));
        $this->notifier->provisioningFailed($service, $action, $result->message);

        return $service;
    }

    private function log(Service $service, string $action, bool $success, ?string $message): void
    {
        ServiceLog::create([
            'service_id' => $service->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'success' => $success,
            'message' => $message,
        ]);
    }
}
