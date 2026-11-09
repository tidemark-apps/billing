<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('unit', 40)->default('unit');
            $table->string('aggregation', 10)->default('sum');       // sum | max | last
            $table->decimal('included_quantity', 20, 6)->default(0);  // free allowance per billing period
            $table->string('pricing_model', 12)->default('per_unit'); // per_unit | tiered | package
            $table->decimal('package_size', 20, 6)->nullable();       // package model: units per package
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'key']);
        });

        // Prices are integers in MICRO-MINOR units: 1 = one millionth of the currency's minor unit (see App\Support\UsageMath).
        Schema::create('usage_meter_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usage_meter_id')->constrained('usage_meters')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('unit_price')->default(0); // per unit (per_unit) or per package (package)
            $table->json('tiers')->nullable();                    // tiered: [{"up_to": "1000"|null, "unit_price": 2000, "flat_fee": 0}, ...]
            $table->timestamps();
            $table->unique(['usage_meter_id', 'currency']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meter_id')->constrained('usage_meters')->cascadeOnDelete();
            $table->decimal('quantity', 20, 6);
            $table->timestamp('recorded_at')->index();
            $table->string('idempotency_key', 191)->nullable();
            $table->string('source', 40)->default('api');
            $table->unsignedBigInteger('invoice_item_id')->nullable()->index();
            $table->timestamp('invoiced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['service_id', 'meter_id', 'idempotency_key']);
            $table->index(['service_id', 'meter_id', 'invoiced_at', 'recorded_at'], 'usage_records_billing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('usage_meter_prices');
        Schema::dropIfExists('usage_meters');
    }
};
