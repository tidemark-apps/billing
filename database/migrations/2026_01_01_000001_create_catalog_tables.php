<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('module', 64)->nullable()->index();
            $table->json('module_config')->nullable();
            $table->string('label_prompt')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->boolean('tax_exempt')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('cycle', 20);
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('setup_fee')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'cycle', 'currency']);
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('module', 64)->index();
            $table->string('name');
            $table->string('hostname');
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->text('secret')->nullable();
            $table->json('config')->nullable();
            $table->boolean('use_ssl')->default(true);
            $table->boolean('verify_ssl')->default(true);
            $table->unsignedInteger('max_services')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
    }
};
