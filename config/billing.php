<?php

return [
    'name' => env('BILLING_NAME', 'Tidemark Billing'),
    'version' => trim(@file_get_contents(base_path('VERSION')) ?: 'dev'),

    // Where the "Powered by" links in the footer point: the product's landing page.
    // What an add-on product can switch on for the service it is attached to (Products → add-on → "Grants").
    'grants' => [
        'whitelabel' => 'White label: remove Tidemark branding and allow a custom domain',
    ],

    'product_url' => 'https://hellotidemark.com/billing/',

    // The default store currency (ISO 4217). Can be changed in Settings → Billing.
    'currency' => env('BILLING_CURRENCY', 'USD'),

    // Let visitors create customer accounts themselves. Can be changed in Settings → General.
    'registration' => (bool) env('BILLING_REGISTRATION', true),

    // Hosted mode: this install is run for you by a hosting provider. The web installer and plugin uploads are closed and
    // the mail transport (SMTP/sendmail) comes from the environment, not from Settings → Email. Off for self-hosting.
    'hosted' => (bool) env('BILLING_HOSTED', false),

    // Plan limits of a hosted install: a JSON file such as {"name": "starter", "limits": {"clients": 100, "staff": 2}}.
    // No file means no limits.
    'plan_file' => env('BILLING_PLAN_FILE'),

    // Base64 Ed25519 public key of the host. Lets the host mint short-lived sign-in links (/sso) for the owner and for support;
    // the private key never reaches this install. No key means /sso is off.
    'sso_public_key' => env('BILLING_SSO_PUBLIC_KEY'),

    // Where plugins live. Each plugin is a folder with a plugin.json manifest.
    'plugins_path' => env('BILLING_PLUGINS_PATH', base_path('plugins')),

    // A second folder that is also scanned (Docker puts user-installed plugins in the /data volume).
    'plugins_extra_path' => env('BILLING_EXTRA_PLUGINS_PATH'),

    // Install/upgrade/delete plugins from Admin → Plugins by uploading a .zip. Plugins run arbitrary PHP with full
    // access to the app and database: set to false on hosts where only you should be able to add code.
    'allow_plugin_upload' => (bool) env('BILLING_ALLOW_PLUGIN_UPLOAD', true) && ! (bool) env('BILLING_HOSTED', false),

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
