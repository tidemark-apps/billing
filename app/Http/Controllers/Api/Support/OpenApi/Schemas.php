<?php

namespace App\Http\Controllers\Api\Support\OpenApi;

/** components.schemas of the OpenAPI document. Edit here, then run `php artisan api:openapi`. */
class Schemas
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::base() + self::added() + self::billingExtensions() + self::support();
    }

    /** Schemas of the first API release (customer API and the original admin resources). @return array<string, array<string, mixed>> */
    private static function base(): array
    {
        return [
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'error' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                        'required' => ['code', 'message'],
                    ],
                ],
                'required' => ['error'],
            ],
            'Money' => [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'integer', 'description' => 'Minor units (cents)'],
                    'currency' => ['type' => 'string'],
                    'formatted' => ['type' => 'string'],
                ],
                'required' => ['amount', 'currency', 'formatted'],
            ],
            'Client' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'name' => ['type' => 'string'],
                    'company_name' => ['type' => ['string', 'null']],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'phone' => ['type' => ['string', 'null']],
                    'address' => [
                        'type' => 'object',
                        'properties' => [
                            'line1' => ['type' => ['string', 'null']],
                            'line2' => ['type' => ['string', 'null']],
                            'city' => ['type' => ['string', 'null']],
                            'state' => ['type' => ['string', 'null']],
                            'postcode' => ['type' => ['string', 'null']],
                            'country' => ['type' => ['string', 'null']],
                        ],
                    ],
                    'tax_id' => ['type' => ['string', 'null']],
                    'currency' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'is_internal' => ['type' => 'boolean', 'description' => 'Internal accounts (your own team, test customers) cost nothing and are left out of revenue and other report figures'],
                    'notes' => ['type' => ['string', 'null'], 'description' => 'Staff tokens only'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'User' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'staff', 'customer']],
                    'client_id' => ['type' => ['integer', 'null']],
                    'is_active' => ['type' => 'boolean'],
                    'last_login_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'client' => ['$ref' => '#/components/schemas/Client'],
                ],
            ],
            'ProductPrice' => [
                'type' => 'object',
                'properties' => [
                    'cycle' => [
                        'type' => 'string',
                        'enum' => ['one_time', 'monthly', 'quarterly', 'semi_annually', 'annually', 'biennially', 'triennially'],
                    ],
                    'cycle_label' => ['type' => 'string'],
                    'currency' => ['type' => 'string'],
                    'amount' => ['$ref' => '#/components/schemas/Money'],
                    'setup_fee' => ['$ref' => '#/components/schemas/Money'],
                ],
            ],
            'Product' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'description' => ['type' => ['string', 'null']],
                    'group' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']]],
                    'label_prompt' => ['type' => ['string', 'null']],
                    'tax_exempt' => ['type' => 'boolean'],
                    'is_active' => ['type' => 'boolean'],
                    'is_visible' => ['type' => 'boolean'],
                    'trial_days' => ['type' => 'integer', 'description' => 'Length of the free trial in days; 0 = no trial'],
                    'trial_requires_payment_method' => ['type' => 'boolean', 'description' => 'Staff tokens only: a saved payment method is needed to start a trial'],
                    'allow_plan_changes' => ['type' => 'boolean', 'description' => 'Staff tokens only: customers may switch from this plan themselves'],
                    'trial_available' => ['type' => ['boolean', 'null'], 'description' => 'Customer/public requests: whether the authenticated client can still start a trial (null without a customer token). Trials are once per client per product'],
                    'module' => ['type' => ['string', 'null'], 'description' => 'Staff tokens only'],
                    'module_config' => ['type' => ['object', 'null'], 'description' => 'Staff tokens only'],
                    'prices' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ProductPrice']],
                    'meters' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/UsageMeter'], 'description' => 'Usage meters (metered billing); empty for products without any'],
                ],
            ],
            'Service' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'client_id' => ['type' => 'integer'],
                    'product_id' => ['type' => ['integer', 'null']],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['pending', 'active', 'suspended', 'cancelled', 'terminated'],
                    ],
                    'label' => ['type' => ['string', 'null']],
                    'billing_cycle' => ['type' => 'string'],
                    'price' => ['$ref' => '#/components/schemas/Money'],
                    'next_due_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'cancel_at_period_end' => ['type' => 'boolean'],
                    'on_trial' => ['type' => 'boolean', 'description' => 'True from the start of a trial until its first invoice is raised'],
                    'trial_ends_at' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'trial_converted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'scheduled_change' => ['oneOf' => [['$ref' => '#/components/schemas/ScheduledPlanChange'], ['type' => 'null']], 'description' => 'A plan change waiting for the next renewal'],
                    'can_change_plan' => ['type' => 'boolean', 'description' => 'Customer tokens only: whether the customer may change plan themselves'],
                    'activated_at' => ['type' => ['string', 'null']],
                    'suspended_at' => ['type' => ['string', 'null']],
                    'cancelled_at' => ['type' => ['string', 'null']],
                    'terminated_at' => ['type' => ['string', 'null']],
                    'provision' => [
                        'type' => 'object',
                        'description' => 'Data stored by the provisioning module (username, IP, license key...). Never contains secrets.',
                    ],
                    'provision_error' => ['type' => ['string', 'null'], 'description' => 'Staff tokens only'],
                    'notes' => ['type' => ['string', 'null'], 'description' => 'Staff tokens only'],
                    'product' => ['$ref' => '#/components/schemas/Product'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'InvoiceItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'kind' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'number'],
                    'unit_price' => ['$ref' => '#/components/schemas/Money'],
                    'total' => ['$ref' => '#/components/schemas/Money'],
                    'taxable' => ['type' => 'boolean'],
                    'service_id' => ['type' => ['integer', 'null']],
                    'product_id' => ['type' => ['integer', 'null']],
                    'period_start' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'period_end' => ['type' => ['string', 'null'], 'format' => 'date'],
                ],
            ],
            'Payment' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'invoice_id' => ['type' => ['integer', 'null']],
                    'client_id' => ['type' => 'integer'],
                    'type' => ['type' => 'string', 'enum' => ['payment', 'refund']],
                    'status' => ['type' => 'string', 'enum' => ['completed', 'pending', 'failed']],
                    'gateway' => ['type' => 'string'],
                    'reference' => ['type' => ['string', 'null']],
                    'amount' => ['$ref' => '#/components/schemas/Money'],
                    'fee' => ['$ref' => '#/components/schemas/Money'],
                    'refund_of_id' => ['type' => ['integer', 'null']],
                    'notes' => ['type' => ['string', 'null'], 'description' => 'Staff tokens only'],
                    'paid_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                ],
            ],
            'Invoice' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'number' => ['type' => ['string', 'null']],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'open', 'paid', 'void', 'uncollectible']],
                    'client_id' => ['type' => 'integer'],
                    'quote_id' => ['type' => ['integer', 'null']],
                    'currency' => ['type' => 'string'],
                    'subtotal' => ['$ref' => '#/components/schemas/Money'],
                    'tax_total' => ['$ref' => '#/components/schemas/Money'],
                    'total' => ['$ref' => '#/components/schemas/Money'],
                    'amount_paid' => ['$ref' => '#/components/schemas/Money'],
                    'balance' => ['$ref' => '#/components/schemas/Money'],
                    'tax_name' => ['type' => ['string', 'null']],
                    'tax_rate' => ['type' => 'number'],
                    'issue_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'due_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'sent_at' => ['type' => ['string', 'null']],
                    'paid_at' => ['type' => ['string', 'null']],
                    'is_overdue' => ['type' => 'boolean'],
                    'reference' => ['type' => ['string', 'null']],
                    'notes' => ['type' => ['string', 'null']],
                    'terms' => ['type' => ['string', 'null']],
                    'pay_url' => ['type' => ['string', 'null'], 'description' => 'Public page where the customer can view and pay'],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/InvoiceItem']],
                    'payments' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Payment']],
                    'client' => ['$ref' => '#/components/schemas/Client'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'QuoteItem' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'description' => ['type' => 'string'],
                    'quantity' => ['type' => 'number'],
                    'unit_price' => ['$ref' => '#/components/schemas/Money'],
                    'total' => ['$ref' => '#/components/schemas/Money'],
                    'taxable' => ['type' => 'boolean'],
                    'product_id' => ['type' => ['integer', 'null']],
                    'billing_cycle' => ['type' => ['string', 'null']],
                    'label' => ['type' => ['string', 'null']],
                ],
            ],
            'Quote' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid'],
                    'number' => ['type' => ['string', 'null']],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['draft', 'sent', 'accepted', 'declined', 'expired', 'converted'],
                    ],
                    'client_id' => ['type' => 'integer'],
                    'invoice_id' => ['type' => ['integer', 'null']],
                    'title' => ['type' => ['string', 'null']],
                    'currency' => ['type' => 'string'],
                    'subtotal' => ['$ref' => '#/components/schemas/Money'],
                    'tax_total' => ['$ref' => '#/components/schemas/Money'],
                    'total' => ['$ref' => '#/components/schemas/Money'],
                    'tax_name' => ['type' => ['string', 'null']],
                    'tax_rate' => ['type' => 'number'],
                    'issue_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'valid_until' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'notes' => ['type' => ['string', 'null']],
                    'terms' => ['type' => ['string', 'null']],
                    'sent_at' => ['type' => ['string', 'null']],
                    'accepted_at' => ['type' => ['string', 'null']],
                    'declined_at' => ['type' => ['string', 'null']],
                    'accepted_by' => ['type' => ['string', 'null']],
                    'view_url' => ['type' => ['string', 'null']],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/QuoteItem']],
                    'invoice' => ['$ref' => '#/components/schemas/Invoice'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'TaxRate' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'rate' => ['type' => 'number'],
                    'country' => ['type' => ['string', 'null']],
                    'region' => ['type' => ['string', 'null']],
                    'is_active' => ['type' => 'boolean'],
                ],
            ],
            'Webhook' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'description' => ['type' => ['string', 'null']],
                    'events' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'is_active' => ['type' => 'boolean'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'WebhookCreated' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/Webhook'],
                    [
                        'type' => 'object',
                        'properties' => [
                            'secret' => ['type' => 'string', 'description' => 'Signing secret. Shown only in this response.'],
                        ],
                    ],
                ],
            ],
            'WebhookDelivery' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'webhook_id' => ['type' => 'integer'],
                    'event' => ['type' => 'string'],
                    'payload' => ['type' => 'object'],
                    'status_code' => ['type' => ['integer', 'null']],
                    'success' => ['type' => 'boolean'],
                    'attempts' => ['type' => 'integer'],
                    'response' => ['type' => ['string', 'null']],
                    'delivered_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'EventInfo' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'description' => ['type' => 'string']],
            ],
            'TokenResponse' => [
                'type' => 'object',
                'properties' => [
                    'token' => ['type' => 'string', 'description' => 'Bearer token. Shown once.'],
                    'token_type' => ['type' => 'string'],
                    'abilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'user' => ['$ref' => '#/components/schemas/User'],
                ],
            ],
            'ActionResult' => [
                'type' => 'object',
                'properties' => ['success' => ['type' => 'boolean'], 'message' => ['type' => 'string'], 'redirect' => ['type' => ['string', 'null']]],
            ],
            'Pagination' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['type' => 'array', 'items' => []],
                    'links' => [
                        'type' => 'object',
                        'properties' => [
                            'first' => ['type' => ['string', 'null']],
                            'last' => ['type' => ['string', 'null']],
                            'prev' => ['type' => ['string', 'null']],
                            'next' => ['type' => ['string', 'null']],
                        ],
                    ],
                    'meta' => [
                        'type' => 'object',
                        'properties' => [
                            'current_page' => ['type' => 'integer'],
                            'from' => ['type' => ['integer', 'null']],
                            'last_page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'to' => ['type' => ['integer', 'null']],
                            'total' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'InvoiceItemInput' => [
                'type' => 'object',
                'properties' => [
                    'description' => ['type' => 'string'],
                    'unit_price' => ['type' => 'integer', 'description' => 'Minor units'],
                    'quantity' => ['type' => 'number'],
                    'taxable' => ['type' => 'boolean'],
                    'product_id' => ['type' => ['integer', 'null']],
                ],
                'required' => ['description', 'unit_price'],
            ],
            'QuoteItemInput' => [
                'allOf' => [
                    ['$ref' => '#/components/schemas/InvoiceItemInput'],
                    [
                        'type' => 'object',
                        'properties' => ['billing_cycle' => ['type' => 'string'], 'label' => ['type' => 'string']],
                    ],
                ],
            ],
            'ClientInput' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'company_name' => ['type' => 'string'],
                    'phone' => ['type' => 'string'],
                    'address_1' => ['type' => 'string'],
                    'address_2' => ['type' => 'string'],
                    'city' => ['type' => 'string'],
                    'state' => ['type' => 'string'],
                    'postcode' => ['type' => 'string'],
                    'country' => ['type' => 'string', 'description' => 'ISO 3166-1 alpha-2'],
                    'tax_id' => ['type' => 'string'],
                    'currency' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'inactive', 'closed']],
                    'is_internal' => ['type' => 'boolean', 'description' => 'Mark as an internal account: every service becomes free and the client is excluded from reports. Turning it off restores the normal prices.'],
                    'notes' => ['type' => 'string'],
                    'password' => ['type' => 'string', 'description' => 'If set, a portal login is created (create only)'],
                ],
            ],
            'ProductInput' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'product_group_id' => ['type' => 'integer'],
                    'module' => ['type' => 'string'],
                    'module_config' => ['type' => 'object'],
                    'label_prompt' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                    'is_visible' => ['type' => 'boolean'],
                    'tax_exempt' => ['type' => 'boolean'],
                    'sort' => ['type' => 'integer'],
                    'trial_days' => ['type' => 'integer', 'description' => '0-365; 0 turns the trial off'],
                    'trial_requires_payment_method' => ['type' => 'boolean'],
                    'allow_plan_changes' => ['type' => 'boolean', 'description' => 'Let customers switch from this plan themselves (default true)'],
                    'prices' => [
                        'type' => 'array',
                        'description' => 'Replaces all prices',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'cycle' => ['type' => 'string'],
                                'currency' => ['type' => 'string'],
                                'amount' => ['type' => 'integer'],
                                'setup_fee' => ['type' => 'integer'],
                            ],
                            'required' => ['cycle', 'currency', 'amount'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Admin API schemas. @return array<string, array<string, mixed>> */
    private static function added(): array
    {
        $field = Dsl::obj([
            'key' => 'string', 'label' => 'string', 'type' => 'text|password|textarea|select|checkbox|number|url|email', 'required' => 'boolean',
            'options' => ['?object', 'Allowed values for select fields (value => label)'], 'default' => ['type' => ['string', 'number', 'boolean', 'null']], 'help' => '?string',
            'secret' => ['boolean', 'Secret fields are write-only'], 'value' => ['type' => ['string', 'number', 'boolean', 'null'], 'description' => 'Current value (absent for secret fields)'],
            'has_value' => ['boolean', 'Secret fields only: whether a value is stored'],
        ]);
        $moneyList = fn () => Dsl::obj(['currency' => 'string', 'invoices' => 'integer', 'amount' => 'Money']);

        return [
            'Server' => Dsl::obj([
                'id' => 'integer', 'module' => 'string', 'name' => 'string', 'hostname' => 'string', 'port' => '?integer', 'username' => '?string',
                'use_ssl' => 'boolean', 'verify_ssl' => 'boolean', 'is_active' => 'boolean', 'max_services' => '?integer', 'notes' => '?string',
                'has_secret' => ['boolean', 'The connection secret is write-only; this says whether one is stored'],
                'config' => ['object', 'Module settings without password-type fields'],
                'config_secrets_set' => ['object', 'Password-type module settings: key => whether stored'],
                'live_services_count' => ['integer', 'Active, suspended and pending services on this server'], 'created_at' => 'datetime',
            ], 'A provisioning server. Secrets are never returned.'),
            'ServerInput' => Dsl::obj([
                'module' => ['string', 'Key of a module that uses servers (see GET /admin/modules)'], 'name' => 'string', 'hostname' => 'string', 'port' => 'integer',
                'username' => 'string', 'secret' => ['string', 'Write-only. Omit or send blank to keep the stored secret'], 'use_ssl' => 'boolean', 'verify_ssl' => 'boolean',
                'is_active' => 'boolean', 'max_services' => 'integer', 'notes' => 'string',
                'config' => ['object', 'Module fields (GET /admin/modules → server_fields); partial updates merge into the stored config'],
            ]),
            'ProductGroup' => Dsl::obj(['id' => 'integer', 'name' => 'string', 'slug' => 'string', 'description' => '?string', 'sort' => 'integer', 'is_visible' => 'boolean', 'products_count' => 'integer', 'created_at' => 'datetime']),
            'ProductGroupInput' => Dsl::obj(['name' => 'string', 'description' => 'string', 'sort' => 'integer', 'is_visible' => 'boolean']),
            'ReorderInput' => Dsl::obj(['ids!' => ['integer[]', 'Ids in the order you want them']]),
            'ServiceLog' => Dsl::obj(['id' => 'integer', 'service_id' => 'integer', 'action' => 'string', 'success' => 'boolean', 'message' => '?string', 'user' => ['?object', 'Who triggered it (id, name); null for the system'], 'created_at' => 'datetime']),
            'ActivityLog' => Dsl::obj([
                'id' => 'integer', 'action' => ['string', 'e.g. invoice.created, service.suspend, settings.updated'], 'description' => '?string',
                'subject_type' => ['?string', 'e.g. invoice, client, service'], 'subject_id' => '?integer', 'properties' => 'object', 'ip' => '?string',
                'user' => ['?object', 'id, name, email of the staff user'], 'created_at' => 'datetime',
            ]),
            'ApiToken' => Dsl::obj([
                'id' => 'integer', 'name' => 'string', 'prefix' => 'string', 'user_id' => 'integer', 'abilities' => ['?string[]', 'null means full access'], 'full_access' => 'boolean',
                'last_used_at' => '?datetime', 'expires_at' => '?datetime', 'is_expired' => 'boolean', 'is_current' => ['boolean', 'The token used for this request'],
                'user' => ['object', 'id, name, email, role'], 'created_at' => 'datetime',
            ]),
            'ApiTokenCreated' => ['allOf' => [Dsl::ref('ApiToken'), Dsl::obj(['token' => ['string', 'The bearer token. Shown only in this response.'], 'token_type' => 'string'])]],
            'ApiTokenInput' => Dsl::obj(['name!' => 'string', 'abilities' => ['string[]', 'Staff abilities (GET /admin/abilities). Omit for full access (only if the creating token has full access too).'], 'expires_in_days' => 'integer']),
            'ImpersonationInput' => Dsl::obj(['minutes' => ['integer', '1–60, default 15'], 'abilities' => ['string[]', 'Customer abilities to restrict the token to']]),
            'ImpersonationToken' => Dsl::obj(['token' => ['string', 'Bearer token for the customer API. Shown once.'], 'token_type' => 'string', 'abilities' => 'string[]', 'expires_at' => 'datetime', 'user' => 'User']),
            'AbilityInfo' => Dsl::obj(['name' => 'string', 'description' => 'string']),
            'Abilities' => Dsl::obj(['customer' => 'AbilityInfo[]', 'staff' => 'AbilityInfo[]']),
            'StaffMe' => ['allOf' => [Dsl::ref('User'), Dsl::obj(['token' => Dsl::obj(['id' => 'integer', 'name' => 'string', 'abilities' => '?string[]', 'full_access' => 'boolean', 'expires_at' => '?datetime'])])]],
            'StaffUser' => Dsl::obj([
                'id' => 'integer', 'name' => 'string', 'email' => 'string', 'role' => 'admin|staff', 'is_active' => 'boolean',
                'department_ids' => ['integer[]', 'Support departments the member works in'], 'two_factor_enabled' => 'boolean', 'last_login_at' => '?datetime', 'created_at' => '?datetime',
            ]),
            'StaffUserCreateInput' => Dsl::obj(['name!' => 'string', 'email!' => 'email', 'role!' => 'admin|staff', 'password' => ['string', 'At least 8 characters. Required unless send_link is true'], 'send_link' => ['boolean', 'Email a link to choose a password instead'], 'department_ids' => ['integer[]', 'Support departments the member works in']]),
            'StaffUserInput' => Dsl::obj(['name' => 'string', 'email' => 'email', 'role' => 'admin|staff', 'department_ids' => ['integer[]', 'Replaces the support departments the member works in']]),
            'UserInput' => Dsl::obj(['name' => 'string', 'email' => 'email']),
            'UserCreateInput' => Dsl::obj(['name!' => 'string', 'email!' => 'email', 'password!' => ['string', 'At least 8 characters']]),
            'PasswordInput' => Dsl::obj(['password!' => ['string', 'At least 8 characters. The login\'s API tokens are revoked.']]),
            'Field' => $field,
            'Plugin' => Dsl::obj(['slug' => 'string', 'name' => 'string', 'version' => '?string', 'description' => '?string', 'author' => '?string', 'origin' => ['?string', 'bundled or uploaded'], 'enabled' => 'boolean', 'configurable' => 'boolean']),
            'PluginSettings' => Dsl::obj(['slug' => 'string', 'fields' => 'Field[]']),
            'PluginSettingsInput' => Dsl::obj(['settings!' => ['object', 'field key => value. Only the keys you send change; secrets are write-only.']]),
            'Module' => Dsl::obj(['key' => 'string', 'label' => 'string', 'description' => 'string', 'uses_servers' => 'boolean', 'product_fields' => 'Field[]', 'server_fields' => 'Field[]', 'admin_actions' => 'object']),
            'SettingsSectionInfo' => Dsl::obj(['section' => 'general|billing|email|autopay|security|payments|system', 'editable' => 'boolean']),
            'SettingsGroup' => Dsl::obj(['title' => 'string', 'description' => 'string', 'fields' => ['Field[]', 'key is the dotted setting name, e.g. company.name']]),
            'Gateway' => Dsl::obj(['key' => 'string', 'label' => 'string', 'description' => 'string', 'enabled' => 'boolean', 'configured' => 'boolean', 'title' => '?string', 'fields' => 'Field[]']),
            'SettingsSection' => Dsl::obj([
                'section' => 'string', 'groups' => ['SettingsGroup[]', 'general, billing, email, autopay, security'], 'gateways' => ['Gateway[]', 'payments'],
                'info' => ['object', 'system: version, php, database, queue, cache, plugins_path, cron_url_configured, cli_cron'],
            ]),
            'SettingsInput' => Dsl::obj(['settings!' => ['object', 'Dotted setting key => value, e.g. {"company.name": "Acme", "billing.due_days": 14}. Only the keys you send change.']]),
            'GatewaySettingsInput' => Dsl::obj(['gateways!' => ['object', 'gateway key => {enabled, title, <gateway fields>}, e.g. {"stripe": {"enabled": true, "secret_key": "sk_live_..."}}']]),
            'CronToken' => Dsl::obj(['cron_url' => ['string', 'Shown once; the previous URL stops working']]),
            'BillingRunResult' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'Counts per task, e.g. {"invoices_created": 2}'],
            'Dashboard' => Dsl::obj([
                'currency' => 'string', 'revenue_this_month' => 'Money', 'outstanding' => 'Money', 'overdue_invoices' => 'integer', 'active_services' => 'integer',
                'new_clients_30d' => 'integer', 'monthly_revenue' => Dsl::type(['type' => 'array', 'items' => Dsl::obj(['month' => 'string', 'revenue' => 'Money'])]),
                'recent_invoices' => 'Invoice[]', 'recent_payments' => 'Payment[]', 'attention_services' => ['Service[]', 'Pending services whose provisioning failed'], 'failed_webhooks' => 'WebhookDelivery[]',
            ]),
            'InvoiceSummary' => Dsl::obj(['outstanding' => Dsl::type(['type' => 'array', 'items' => $moneyList()]), 'overdue' => Dsl::type(['type' => 'array', 'items' => $moneyList()])]),
            'PaymentSummary' => Dsl::obj(['currency' => 'string', 'received' => 'Money', 'refunded' => 'Money', 'net' => 'Money']),
            'TaxLookup' => Dsl::obj(['rate' => 'number', 'name' => '?string', 'currency' => 'string']),
            'ServiceSecrets' => Dsl::obj(['secrets' => ['object', 'Decrypted values stored by the provisioning module']]),
            'InvoiceInput' => Dsl::obj([
                'client_id' => ['integer', 'Required on create; on a draft, changing it re-applies the new client\'s tax rate'], 'items' => ['InvoiceItemInput[]', 'Required on create; replaces all lines on update'],
                'currency' => 'string', 'issue_date' => 'date', 'due_date' => 'date', 'reference' => 'string', 'notes' => 'string', 'terms' => 'string',
                'issue' => ['boolean', 'Create only: open the invoice straight away'], 'send' => ['boolean', 'Create only, with issue: email it'],
            ]),
            'QuoteInput' => Dsl::obj([
                'client_id' => ['integer', 'Required on create; on a draft, changing it re-applies the new client\'s tax rate'], 'items' => ['QuoteItemInput[]', 'Required on create; replaces all lines on update'],
                'currency' => 'string', 'title' => 'string', 'valid_until' => 'date', 'notes' => 'string', 'terms' => 'string', 'send' => ['boolean', 'Create only: send it'],
            ]),
            'ManualPaymentInput' => Dsl::obj(['amount!' => ['integer', 'Minor units, at most the balance due'], 'method' => ['string', 'bank, cash, cheque, ...'], 'notes' => 'string']),
            'RefundInput' => Dsl::obj(['amount' => ['integer', 'Minor units. Omit to refund everything still refundable'], 'reason' => 'string']),
            'ServiceCreateInput' => Dsl::obj([
                'client_id!' => 'integer', 'product_id!' => 'integer', 'billing_cycle!' => 'one_time|monthly|quarterly|semi_annually|annually|biennially|triennially', 'label' => 'string',
                'send' => ['boolean', 'Email the invoice (default true)'],
                'create_invoice' => ['boolean', 'Default true: place an order (pending service + invoice). false: create only the service, like "New service" in the admin UI'],
                'trial' => ['boolean', 'create_invoice=true only: false bills now even when the product offers a trial'],
                'amount' => ['integer', 'create_invoice=false only: price in minor units (default: the product price)'], 'next_due_date' => ['date', 'create_invoice=false only'], 'activate' => ['boolean', 'create_invoice=false only: provision straight away'],
            ]),
            'ServiceCreated' => Dsl::obj(['service' => 'Service', 'invoice' => ['?Invoice', 'null when create_invoice=false, or when a free trial started instead of an invoice']]),
            'ScheduledPlanChange' => Dsl::obj(['id' => 'integer', 'to_product' => ['?object', 'id, name'], 'to_cycle' => 'string', 'to_amount' => 'Money', 'effective_date' => 'date']),
            'PlanOption' => Dsl::obj(['product_id' => 'integer', 'product_name' => 'string', 'billing_cycle' => 'string', 'price' => 'Money']),
            'PlanOptions' => Dsl::obj(['can_change_plan' => ['boolean', 'False when the service cannot change plan (or, for customers, may not); options is then empty'], 'options' => 'PlanOption[]']),
            'PlanChangeInput' => Dsl::obj(['product_id!' => 'integer', 'billing_cycle!' => 'one_time|monthly|quarterly|semi_annually|annually|biennially|triennially', 'timing' => ['immediate|renewal', 'Default: upgrades now, downgrades at renewal. Staff only: customers cannot make a downgrade immediate']]),
            'PlanChangePreview' => Dsl::obj([
                'direction' => 'upgrade|downgrade|change', 'timing' => 'immediate|renewal', 'trial' => ['boolean', 'On a trial nothing is prorated'],
                'from_product' => 'object', 'to_product' => 'object', 'from_cycle' => 'string', 'to_cycle' => 'string', 'from_amount' => 'Money', 'to_amount' => 'Money',
                'credit' => ['Money', 'Unused part of the current period'], 'charge' => ['Money', 'Rest of the period (or one full period when the cycle changes) at the new price'],
                'amount_due' => ['Money', 'charge minus credit, never below 0: the invoice total before tax'], 'forfeited' => ['Money', 'Credit larger than the charge; it is not refunded or carried forward'],
                'effective_date' => 'date', 'next_due_date' => 'date', 'period_start' => 'date', 'period_end' => 'date',
            ]),
            'PlanChange' => Dsl::obj([
                'id' => 'integer', 'service_id' => 'integer', 'direction' => 'upgrade|downgrade|change', 'timing' => 'immediate|renewal', 'status' => 'scheduled|applied|cancelled', 'source' => 'admin|customer|api',
                'from_product' => '?object', 'to_product' => '?object', 'from_cycle' => 'string', 'to_cycle' => 'string', 'from_amount' => 'Money', 'to_amount' => 'Money', 'credit' => 'Money', 'charge' => 'Money',
                'effective_date' => 'date', 'applied_at' => '?datetime',
                'invoice' => ['?object', 'Summary (id, uuid, number, status, total, balance, due_date, pay_url) of the proration invoice; null when nothing is due or the change is scheduled'], 'created_at' => 'datetime',
            ]),
            'TrialExtendInput' => Dsl::obj(['days!' => ['integer', '1-365']]),
            'ServiceUpdateInput' => Dsl::obj(['label' => 'string', 'notes' => 'string', 'amount' => ['integer', 'Minor units'], 'billing_cycle' => 'string', 'next_due_date' => 'date', 'server_id' => 'integer']),
        ];
    }

    /** Saved payment methods, automatic payment and usage billing. @return array<string, array<string, mixed>> */
    private static function billingExtensions(): array
    {
        return [
            'PaymentMethod' => Dsl::obj([
                'uuid' => 'uuid', 'client_id' => 'integer', 'gateway' => 'string', 'brand' => '?string', 'last4' => '?string', 'exp_month' => '?integer', 'exp_year' => '?integer',
                'expiry' => ['?string', 'MM/YYYY'], 'is_expired' => 'boolean', 'label' => ['?string', 'e.g. the PayPal account'], 'display_name' => ['string', '"Visa ending 4242"'],
                'is_default' => 'boolean', 'autopay' => ['boolean', 'Invoices are charged to this method automatically'], 'last_used_at' => '?datetime', 'created_at' => 'datetime',
            ], 'Display details only: the provider token is never returned.'),
            'SavedMethodGateway' => Dsl::obj(['key' => 'string', 'title' => 'string', 'description' => 'string']),
            'PaymentMethodRemoval' => Dsl::obj(['removed' => 'boolean', 'gateway_confirmed' => ['boolean', 'false when the provider could not confirm the deletion (the method is removed here either way)']]),
            'SavedPaymentOutcome' => Dsl::obj([
                'status' => ['succeeded|pending|requires_action', 'pending: processing, the payment appears when it clears. requires_action: pay with the provider checkout instead'],
                'message' => 'string', 'invoice' => 'Invoice',
            ]),
            'AutopayAttempt' => Dsl::obj([
                'id' => 'integer', 'invoice_id' => 'integer', 'invoice_uuid' => '?uuid', 'invoice_number' => '?string', 'client_id' => '?integer', 'payment_method_uuid' => ['?uuid', 'null once the method was removed'],
                'attempt' => ['integer', '1 is the first charge'], 'status' => 'success|failed|requires_action|pending|final', 'gateway' => '?string', 'reference' => '?string', 'message' => '?string', 'created_at' => 'datetime',
            ]),
            'UsageMeterTier' => Dsl::obj(['up_to' => ['?string', 'Cumulative upper bound; null is unbounded (last tier)'], 'unit_price' => ['integer', 'Micro-minor units per unit in this tier'], 'flat_fee' => ['integer', 'Micro-minor units, added once when the tier is reached']]),
            'UsageMeterPrice' => Dsl::obj(['currency' => 'string', 'unit_price' => ['integer', 'Micro-minor units (1 = one millionth of a minor unit): per unit, or per package'], 'unit_price_display' => ['string', 'unit_price in major units, e.g. "0.002"'], 'tiers' => 'UsageMeterTier[]']),
            'UsageMeter' => Dsl::obj([
                'id' => 'integer', 'product_id' => 'integer', 'key' => 'string', 'name' => 'string', 'unit' => 'string', 'aggregation' => ['sum|max|last', 'How records of a period combine'],
                'included_quantity' => ['string', 'Free allowance per period (decimal string)'], 'pricing_model' => 'per_unit|tiered|package', 'package_size' => ['?string', 'package model: units per package'], 'sort' => 'integer', 'prices' => 'UsageMeterPrice[]',
            ]),
            'UsageMeterPriceInput' => Dsl::obj([
                'currency!' => ['string', 'ISO 4217'], 'unit_price' => ['integer', 'Micro-minor units; required unless pricing_model is tiered'],
                'tiers' => ['object[]', 'tiered only: [{"up_to": 1000, "unit_price": 2000, "flat_fee": 0}, ..., {"up_to": null, "unit_price": 1000}]'],
            ]),
            'UsageMeterInput' => Dsl::obj([
                'key!' => ['string', 'Lowercase letters, numbers, dashes, underscores; unique per product; cannot change later'], 'name!' => 'string', 'unit' => ['string', 'Default "unit"'],
                'aggregation!' => 'sum|max|last', 'pricing_model!' => 'per_unit|tiered|package', 'included_quantity' => ['number', 'Free allowance per period'], 'package_size' => ['number', 'Required for the package model'],
                'prices' => ['UsageMeterPriceInput[]', 'One entry per currency'],
            ]),
            'UsageMeterUpdateInput' => Dsl::obj([
                'name' => 'string', 'unit' => 'string', 'aggregation' => 'sum|max|last', 'pricing_model' => 'per_unit|tiered|package', 'included_quantity' => 'number', 'package_size' => '?number',
                'prices' => ['UsageMeterPriceInput[]', 'Replaces all prices of the meter'],
            ]),
            'UsageRecord' => Dsl::obj([
                'id' => 'integer', 'service_id' => 'integer', 'meter' => ['string', 'Meter key'], 'meter_id' => 'integer', 'quantity' => 'string', 'recorded_at' => 'datetime', 'idempotency_key' => '?string',
                'source' => 'string', 'invoiced' => 'boolean', 'invoiced_at' => '?datetime', 'invoice_item_id' => '?integer', 'metadata' => 'object', 'created_at' => 'datetime',
            ]),
            'UsageSummaryMeter' => Dsl::obj([
                'meter' => 'string', 'name' => 'string', 'unit' => 'string', 'aggregation' => 'string', 'quantity' => 'string', 'included' => 'string', 'billable' => 'string', 'records' => 'integer',
                'priced' => ['boolean', 'false when the meter has no price for the currency'], 'estimated_charge' => 'Money',
            ]),
            'UsageSummary' => Dsl::obj([
                'period_start' => '?date', 'period_end' => '?date', 'currency' => 'string', 'estimated_charge' => ['Money', 'Total before tax'], 'meters' => ['UsageSummaryMeter[]', 'Only meters that have unbilled usage'],
            ], 'Unbilled usage of the current period. Billed in arrears on the renewal invoice.'),
        ];
    }

    /** Support tickets, departments and custom fields. @return array<string, array<string, mixed>> */
    private static function support(): array
    {
        $status = 'open|answered|customer_reply|on_hold|closed';
        $priority = 'low|normal|high|urgent';
        $files = [
            'files' => ['string[]', 'multipart/form-data only: the files, as files[]'],
            'attachments' => ['TicketAttachmentInput[]', 'JSON alternative to files[]: base64-encoded files'],
        ];

        return [
            'TicketAttachment' => Dsl::obj([
                'uuid' => 'uuid', 'filename' => 'string', 'mime' => ['string', 'Detected from the content, not the client-supplied type'], 'size' => 'integer', 'sha256' => 'string',
                'download_url' => ['uri', 'Authenticated download (bearer token). Always an attachment with nosniff and a sandboxing CSP'], 'created_at' => 'datetime',
            ]),
            'TicketAttachmentInput' => Dsl::obj(['filename!' => 'string', 'content_base64!' => ['string', 'Standard base64 of the file bytes']]),
            'TicketMessage' => Dsl::obj([
                'id' => 'integer', 'kind' => ['reply|note|event', 'Customers only ever receive reply'], 'author_type' => 'customer|staff|system', 'author_name' => '?string',
                'author_email' => ['?string', 'Staff tokens only'], 'user_id' => ['?integer', 'Staff tokens only'], 'source' => ['string', 'Staff tokens only: portal, email, admin, api, system'],
                'body' => 'string', 'attachments' => 'TicketAttachment[]', 'created_at' => 'datetime',
            ]),
            'Ticket' => Dsl::obj([
                'id' => ['integer', 'Staff tokens only. Customers address tickets by uuid'], 'uuid' => 'uuid', 'number' => 'string', 'subject' => 'string', 'status' => $status, 'status_label' => 'string',
                'priority' => $priority, 'is_locked' => ['boolean', 'A locked ticket cannot be replied to or closed by the customer'], 'can_reply' => ['boolean', 'Customer tokens only'],
                'department' => ['?object', 'id, name, slug'], 'service_uuid' => '?uuid',
                'client_id' => ['?integer', 'Staff tokens only'], 'user_id' => ['?integer', 'Staff tokens only'], 'service_id' => ['?integer', 'Staff tokens only'],
                'requester_name' => ['string', 'Staff tokens only'], 'requester_email' => ['string', 'Staff tokens only'], 'assigned_to' => ['?integer', 'Staff tokens only'],
                'assignee' => ['?object', 'Staff tokens only: id, name'], 'source' => ['string', 'Staff tokens only: portal, email, admin or api'],
                'custom_fields' => ['object', 'key => answer. Customer tokens never receive staff-only fields'],
                'last_reply_at' => '?datetime', 'last_reply_by' => ['?string', 'customer or staff'], 'closed_at' => '?datetime',
                'messages' => ['TicketMessage[]', 'Only on GET /tickets/{id}, and on create. Customers: replies only. Staff: replies, internal notes (kind note) and system lines (kind event)'],
                'created_at' => 'datetime',
            ]),
            'TicketInput' => Dsl::obj([
                'department_id!' => ['integer', 'A listed (public, active) department: GET /ticket-departments'], 'subject!' => 'string', 'message!' => 'string',
                'service' => ['uuid', 'One of your services'], 'custom' => ['object', 'key => answer for the department\'s fields (see GET /ticket-departments). Staff-only and unknown keys are a 422'],
            ] + $files, 'JSON, or multipart/form-data with files[] for attachments.'),
            'TicketReplyInput' => Dsl::obj(['message' => ['string', 'Required unless files are attached']] + $files, 'JSON, or multipart/form-data with files[].'),
            'AdminTicketInput' => Dsl::obj([
                'client_id!' => 'integer', 'user_id' => ['integer', 'A portal login of that client; the requester. Defaults to the client\'s own e-mail address'], 'department_id!' => 'integer',
                'subject!' => 'string', 'message!' => 'string', 'service_id' => ['integer', 'A service of that client'], 'priority' => $priority,
                'custom' => ['object', 'key => answer; staff-only fields included'], 'notify' => ['boolean', 'E-mail the customer (default true)'],
            ] + $files, 'The ticket starts as answered: the first message is the staff member\'s. JSON, or multipart/form-data with files[].'),
            'AdminTicketReplyInput' => Dsl::obj(['message' => ['string', 'Required unless files are attached'], 'status' => [$status, 'Status afterwards (default answered)']] + $files),
            'TicketNoteInput' => Dsl::obj(['message!' => 'string'] + $files),
            'TicketUpdateInput' => Dsl::obj(['status' => $status, 'priority' => $priority, 'custom_fields' => ['object', 'key => answer; only the keys sent change, null or "" clears one']]),
            'TicketAssignInput' => Dsl::obj(['user_id!' => ['?integer', 'A staff member who works in the ticket\'s department, or null to unassign']]),
            'TicketTransferInput' => Dsl::obj(['department_id!' => 'integer']),
            'TicketField' => Dsl::obj([
                'id' => 'integer', 'department_id' => ['?integer', 'Staff tokens only. null: applies to every department'], 'key' => 'string', 'label' => 'string',
                'type' => 'text|textarea|select|checkbox|number|date', 'options' => ['string[]', 'Choices of a select field'], 'required' => 'boolean',
                'staff_only' => ['boolean', 'Staff tokens only. Staff-only fields are never shown to customers'], 'help' => '?string', 'sort' => 'integer', 'is_active' => ['boolean', 'Staff tokens only'],
            ]),
            'TicketFieldInput' => Dsl::obj([
                'key' => ['string', 'Create only; lowercase letters, digits and underscores; cannot change later'], 'label' => 'string', 'type' => 'text|textarea|select|checkbox|number|date',
                'department_id' => ['?integer', 'null for every department'], 'options' => ['string[]', 'Required for select; no commas'], 'required' => 'boolean', 'staff_only' => 'boolean',
                'help' => 'string', 'sort' => 'integer', 'is_active' => 'boolean',
            ]),
            'Department' => Dsl::obj([
                'id' => 'integer', 'name' => 'string', 'slug' => 'string', 'description' => '?string', 'sort' => 'integer',
                'is_public' => ['boolean', 'Staff tokens only'], 'is_active' => ['boolean', 'Staff tokens only'], 'email' => ['?string', 'Staff tokens only'], 'auto_reply' => ['boolean', 'Staff tokens only'],
                'imap_enabled' => ['boolean', 'Administrators only (this and the other imap_* / staff_ids fields)'], 'imap_host' => '?string', 'imap_port' => 'integer', 'imap_encryption' => 'ssl|starttls|none',
                'imap_validate_cert' => 'boolean', 'imap_username' => '?string',
                'has_imap_password' => ['boolean', 'The mailbox password is write-only; this says whether one is stored'], 'imap_folder' => 'string', 'imap_after' => 'seen|delete',
                'imap_checked_at' => '?datetime', 'imap_error' => '?string', 'staff_ids' => ['integer[]', 'Staff who work in this department'],
                'fields' => ['TicketField[]', 'Customer tokens only: the fields the customer can fill in when opening a ticket'], 'created_at' => ['datetime', 'Staff tokens only'],
            ], 'A support department. The mailbox password is never returned.'),
            'DepartmentInput' => Dsl::obj([
                'name' => 'string', 'slug' => ['string', 'Generated from the name when omitted'], 'description' => 'string', 'sort' => 'integer',
                'is_public' => ['boolean', 'Listed to customers on the new-ticket form'], 'is_active' => 'boolean', 'email' => ['?string', 'The address mail for this department arrives at'], 'auto_reply' => 'boolean',
                'imap_enabled' => 'boolean', 'imap_host' => 'string', 'imap_port' => 'integer', 'imap_encryption' => 'ssl|starttls|none', 'imap_validate_cert' => 'boolean', 'imap_username' => 'string',
                'imap_password' => ['string', 'Write-only. Omit or send blank to keep the stored one'], 'clear_imap_password' => ['boolean', 'Remove the stored mailbox password'],
                'imap_folder' => 'string', 'imap_after' => 'seen|delete', 'staff_ids' => ['integer[]', 'Replaces the department\'s staff list'],
            ]),
        ];
    }
}
