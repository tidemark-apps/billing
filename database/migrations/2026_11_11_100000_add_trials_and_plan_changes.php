<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days')->default(0)->after('tax_exempt');
            $table->boolean('trial_requires_payment_method')->default(false)->after('trial_days');
            $table->boolean('allow_plan_changes')->default(true)->after('trial_requires_payment_method');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->date('trial_ends_at')->nullable()->after('next_due_date');
            $table->timestamp('trial_converted_at')->nullable()->after('trial_ends_at');
            $table->timestamp('trial_reminded_at')->nullable()->after('trial_converted_at');
        });

        Schema::create('plan_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('from_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('to_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_cycle', 20);
            $table->string('to_cycle', 20);
            $table->string('currency', 3);
            $table->unsignedBigInteger('from_amount')->default(0);
            $table->unsignedBigInteger('to_amount')->default(0);
            $table->string('direction', 12);   // upgrade | downgrade | change
            $table->string('timing', 12);      // immediate | renewal
            $table->string('status', 12)->default('applied')->index(); // scheduled | applied | cancelled
            $table->bigInteger('credit')->default(0);   // unused time on the old plan
            $table->bigInteger('charge')->default(0);   // remainder of the period on the new plan
            $table->date('effective_date');
            $table->timestamp('applied_at')->nullable();
            $table->string('source', 12)->default('admin'); // admin | customer | api
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_changes');
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn(['trial_ends_at', 'trial_converted_at', 'trial_reminded_at']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['trial_days', 'trial_requires_payment_method', 'allow_plan_changes']));
    }
};
