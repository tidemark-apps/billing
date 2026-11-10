<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);   // listed on the customer's new-ticket form; unlisted ones are reachable by direct link and email
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            // The one email address mail for this department arrives at (also the Reply-To on replies).
            $table->string('email')->nullable()->unique();
            $table->boolean('auto_reply')->default(true);  // acknowledge new tickets by email
            // Optional IMAP mailbox to pull that address from. (Piping needs no settings: see `tickets:pipe`.)
            $table->boolean('imap_enabled')->default(false);
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('imap_encryption', 10)->default('ssl'); // ssl | starttls | none
            $table->boolean('imap_validate_cert')->default(true);
            $table->string('imap_username')->nullable();
            $table->text('imap_password')->nullable();      // encrypted
            $table->string('imap_folder')->default('INBOX');
            $table->string('imap_after', 10)->default('seen'); // seen | delete
            $table->timestamp('imap_checked_at')->nullable();
            $table->text('imap_error')->nullable();
            $table->timestamps();
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('ticket_departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['department_id', 'user_id']);
        });

        Schema::create('ticket_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->cascadeOnDelete(); // null = every department
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 20)->default('text');   // text | textarea | select | checkbox | number | date
            $table->json('options')->nullable();            // select choices
            $table->boolean('required')->default(false);
            $table->boolean('staff_only')->default(false);  // filled in and seen by staff only, never shown to the customer
            $table->string('help')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number')->unique();             // TKT-00001, quoted in email subjects for threading
            $table->foreignId('department_id')->nullable()->constrained('ticket_departments')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // null: mail from someone with no account
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // the customer user who raised it
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name');
            $table->string('requester_email')->index();
            $table->string('subject');
            $table->string('status', 20)->default('open')->index(); // open | answered | customer_reply | on_hold | closed
            $table->string('priority', 10)->default('normal');       // low | normal | high | urgent
            $table->boolean('is_locked')->default(false);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10)->default('portal'); // portal | email | admin | api
            $table->json('custom_fields')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->string('last_reply_by', 10)->nullable(); // customer | staff
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['department_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10)->default('reply');   // reply | note (staff only) | event (staff only, system line)
            $table->string('author_type', 10)->default('customer'); // customer | staff | system
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->longText('body');
            $table->string('message_id')->nullable()->index(); // email Message-ID, for threading and de-duplication
            $table->string('source', 10)->default('portal');
            $table->timestamps();
            $table->index(['ticket_id', 'id']);
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_message_id')->constrained('ticket_messages')->cascadeOnDelete();
            $table->string('disk', 32);
            $table->string('path');                         // random, never derived from the file name
            $table->string('original_name');
            $table->string('mime', 127);                    // detected from the content, not trusted from the client
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_fields');
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('ticket_departments');
    }
};
