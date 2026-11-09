<?php

namespace Plugins\LicenseServer;

use App\Plugins\Plugin;
use App\Support\Activity;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Plugins\LicenseServer\Events\LicenseRevoked;
use Plugins\LicenseServer\Models\License;

/**
 * Shows the plugin system end to end: a provisioning module, its own tables, public API, admin pages,
 * customer-facing service panels, settings, an event listener and a filter hook.
 */
class LicenseServerPlugin extends Plugin
{
    public function register(): void
    {
        $this->registerModule(LicenseModule::class);
    }

    public function boot(): void
    {
        $this->loadMigrations();
        $this->loadViews();

        RateLimiter::for('license-server', fn (Request $r) => [
            Limit::perMinute(30)->by('lk|'.$r->ip().'|'.sha1(strtolower((string) $r->input('key')))),
            Limit::perMinute(120)->by('li|'.$r->ip()),
        ]);

        $this->loadRoutes('routes/api.php', 'api');
        $this->loadRoutes('routes/admin.php', 'admin');
        $this->loadRoutes('routes/portal.php', 'portal');

        $this->adminMenu('Licenses', 'admin.license-server.index', 'key');

        $isLicense = fn (array $ctx) => isset($ctx['service']) && $ctx['service']->module() === 'license';
        $license = fn (array $ctx) => ['license' => License::with('activations')->where('service_id', $ctx['service']->id)->first()];
        $this->extend('admin.service', ['view' => 'license-server::admin.panel', 'when' => $isLicense, 'data' => $license]);
        $this->extend('portal.service', ['view' => 'license-server::portal.panel', 'when' => $isLicense, 'data' => $license]);

        // Events: record revocations in the audit log.
        $this->listen(LicenseRevoked::class, function (LicenseRevoked $e): void {
            Activity::log('license.revoked', $e->license, 'Licence '.$e->license->key.' revoked ('.$e->reason.')');
        });
    }

    public function install(): void
    {
        Signer::ensureKeys();
    }

    public function fields(): array
    {
        return [
            ['key' => 'default_grace_days', 'label' => 'Default grace days', 'type' => 'number', 'default' => 0, 'help' => 'Days a suspended licence keeps validating (products can override).'],
            ['key' => 'verify_marks_seen', 'label' => 'Verify updates "last seen"', 'type' => 'checkbox', 'default' => true, 'help' => 'Record an activation as seen each time its software calls verify.'],
        ];
    }
}
