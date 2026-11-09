<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('reference');                       // provider token (pm_…, vault id)
            $table->string('customer_reference')->nullable();  // provider-side customer id
            $table->string('brand', 40)->nullable();
            $table->string('last4', 4)->nullable();
            $table->unsignedSmallInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('autopay')->default(false);
            $table->text('data')->nullable();                  // encrypted gateway extras
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'reference']);
            $table->index(['client_id', 'removed_at']);
        });

        // The provider-side customer record for a client (Stripe cus_…, PayPal customer id).
        Schema::create('gateway_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 40);
            $table->string('reference');
            $table->timestamps();
            $table->unique(['client_id', 'gateway']);
        });

        // One row per automatic charge attempt: drives retries and gives an audit trail.
        Schema::create('autopay_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('status', 20);                      // success | failed | requires_action | pending | final
            $table->string('gateway', 40)->nullable();
            $table->string('reference')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopay_attempts');
        Schema::dropIfExists('gateway_customers');
        Schema::dropIfExists('payment_methods');
    }
};
