<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->index()->after('status');
        });
        Schema::table('services', function (Blueprint $table) {
            // The price the service had (or would have had) before its client was marked internal, restored if they stop being internal.
            $table->unsignedBigInteger('internal_original_amount')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('internal_original_amount'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('is_internal'));
    }
};
