<?php

return [
    'name' => env('BILLING_NAME', 'Tidemark Billing'),
    'version' => trim(@file_get_contents(base_path('VERSION')) ?: 'dev'),

    // The default store currency (ISO 4217). Can be changed in Settings → Billing.
    'currency' => env('BILLING_CURRENCY', 'USD'),

    // Let visitors create customer accounts themselves. Can be changed in Settings → General.
    'registration' => (bool) env('BILLING_REGISTRATION', true),

    // Where plugins live. Each plugin is a folder with a plugin.json manifest.
    'plugins_path' => env('BILLING_PLUGINS_PATH', base_path('plugins')),

    // A second folder that is also scanned (Docker puts user-installed plugins in the /data volume).
    'plugins_extra_path' => env('BILLING_EXTRA_PLUGINS_PATH'),

    // Install/upgrade/delete plugins from Admin → Plugins by uploading a .zip. Plugins run arbitrary PHP with full
    // access to the app and database: set to false on hosts where only you should be able to add code.
    'allow_plugin_upload' => (bool) env('BILLING_ALLOW_PLUGIN_UPLOAD', true),

    // Zip-bomb guards for plugin archives.
    'plugin_upload' => [
        'max_mb' => (int) env('BILLING_PLUGIN_UPLOAD_MAX_MB', 16),
        'max_files' => 5000,
        'max_unpacked_mb' => 100,
    ],

    // Secret used by the optional web cron URL (/cron/{token}) for hosts without a real cron.
    'cron_token' => env('BILLING_CRON_TOKEN'),

    'legal' => array_filter([
        'terms' => env('BILLING_TERMS_URL'),
        'privacy' => env('BILLING_PRIVACY_URL'),
    ]),
];
