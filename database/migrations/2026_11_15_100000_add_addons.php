<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('kind', 10)->default('plan')->after('name');   // plan | addon
            $table->json('addon_for')->nullable()->after('kind');          // product ids an add-on can attach to (null = any plan)
            $table->json('grants')->nullable()->after('addon_for');        // what an active add-on switches on for its parent, e.g. ["whitelabel"]
        });

        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('parent_service_id')->nullable()->after('product_id')->constrained('services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_service_id');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['kind', 'addon_for', 'grants']);
        });
    }
};
