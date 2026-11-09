<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();            // encrypted (model cast)
            $table->timestamp('two_factor_confirmed_at')->nullable(); // null while enrolment is unconfirmed
            $table->text('two_factor_recovery_codes')->nullable();    // JSON list of HMAC hashes
            $table->unsignedBigInteger('two_factor_last_step')->nullable(); // replay protection
            $table->string('two_factor_device_salt', 64)->nullable(); // rotating it invalidates every remembered device
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes', 'two_factor_last_step', 'two_factor_device_salt']);
        });
    }
};
