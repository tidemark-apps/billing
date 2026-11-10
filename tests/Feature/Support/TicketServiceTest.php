<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Models\Client;
use App\Models\Department;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\TicketAttachments;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales', 'email' => 'sales@example.test']);
        $this->tech = Department::create(['name' => 'Technical', 'slug' => 'technical', 'is_public' => false]);
    }

    private function open(array $over = []): Ticket
    {
        $client = Client::factory()->create();

        return app(TicketService::class)->open($over + [
            'department' => $this->sales, 'subject' => 'Help', 'body' => 'It broke', 'client' => $client,
            'user' => User::factory()->create(['client_id' => $client->id, 'role' => 'customer']),
        ]);
    }

    private function staff(array $departments = [], string $role = 'staff'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->departments()->sync(array_map(fn ($d) => $d->id, $departments));

        return $user;
    }

    public function test_opening_creates_a_numbered_ticket_with_its_first_message(): void
    {
        Event::fake([TicketCreated::class]);

        $ticket = $this->open();

        $this->assertSame('TKT-00001', $ticket->number);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame('customer', $ticket->messages()->first()->author_type);
        Event::assertDispatched(TicketCreated::class);
        $this->assertSame('TKT-00002', $this->open()->number);
    }

    public function test_the_service_must_belong_to_the_customer(): void
    {
        $other = Service::create(['client_id' => Client::factory()->create()->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'USD', 'amount' => 100]);
        $this->expectException(\DomainException::class);

        $this->open(['service' => $other]);
    }

    public function test_replies_move_the_status_and_reopen_closed_tickets(): void
    {
        $tickets = app(TicketService::class);
        $staff = $this->staff([$this->sales]);
        $ticket = $this->open();

        $tickets->reply($ticket, 'Try this', $staff, true);
        $this->assertSame(TicketStatus::Answered, $ticket->refresh()->status);

        $tickets->close($ticket, $staff);
        $this->assertNotNull($ticket->refresh()->closed_at);

        $tickets->reply($ticket, 'Still broken', $ticket->user, false);
        $this->assertSame(TicketStatus::CustomerReply, $ticket->refresh()->status);
        $this->assertNull($ticket->closed_at);
    }

    public function test_a_locked_ticket_rejects_customer_replies_but_not_staff(): void
    {
        $tickets = app(TicketService::class);
        $staff = $this->staff([$this->sales]);
        $ticket = $this->open();
        $tickets->lock($ticket, $staff);

        try {
            $tickets->reply($ticket->refresh(), 'hello?', $ticket->user, false);
            $this->fail('expected a DomainException');
        } catch (\DomainException) {
            $this->assertTrue(true);
        }
        $tickets->reply($ticket, 'Staff can still answer', $staff, true);
        $this->assertSame(2, $ticket->messages()->where('kind', 'reply')->count());

        $tickets->unlock($ticket->refresh(), $staff);
        $tickets->reply($ticket->refresh(), 'thanks', $ticket->user, false);
        $this->assertSame(3, $ticket->messages()->where('kind', 'reply')->count());
    }

    public function test_internal_notes_are_staff_only_and_do_not_change_the_ticket(): void
    {
        $tickets = app(TicketService::class);
        $staff = $this->staff([$this->sales]);
        $ticket = $this->open();

        $tickets->note($ticket, $staff, 'customer is a pain');

        $this->assertSame(TicketStatus::Open, $ticket->refresh()->status);
        $this->assertSame(['customer is a pain'], $ticket->messages()->where('kind', 'note')->pluck('body')->all());
        $this->assertSame(1, $ticket->publicMessages()->count());
    }

    public function test_assignment_is_limited_to_staff_who_work_in_the_department(): void
    {
        Event::fake([TicketAssigned::class]);
        $tickets = app(TicketService::class);
        $inSales = $this->staff([$this->sales]);
        $inTech = $this->staff([$this->tech]);
        $nowhere = $this->staff([]);
        $admin = $this->staff([], 'admin');
        $ticket = $this->open();

        $tickets->claim($ticket, $inSales);
        $this->assertSame($inSales->id, $ticket->refresh()->assigned_to);
        $tickets->assign($ticket, $admin, $admin);
        foreach ([$inTech, $nowhere] as $wrong) {
            try {
                $tickets->assign($ticket->refresh(), $wrong);
                $this->fail('expected a DomainException');
            } catch (\DomainException) {
                $this->assertTrue(true);
            }
        }

        $tickets->transfer($ticket->refresh(), $this->tech);
        $this->assertSame($this->tech->id, $ticket->refresh()->department_id);
        $this->assertSame($admin->id, $ticket->assigned_to, 'an admin keeps access, so they stay assigned');
    }

    public function test_staff_visibility_follows_departments_and_assignment(): void
    {
        $sales = $this->staff([$this->sales]);
        $none = $this->staff([]);
        $admin = $this->staff([], 'admin');
        $ticket = $this->open();

        $this->assertTrue($ticket->canBeSeenBy($sales));
        $this->assertTrue($ticket->canBeSeenBy($admin));
        $this->assertFalse($ticket->canBeSeenBy($none));
        $this->assertSame([], Ticket::visibleTo($none)->pluck('id')->all());
        $this->assertSame([$ticket->id], Ticket::visibleTo($sales)->pluck('id')->all());

        $ticket->update(['assigned_to' => $none->id]);
        $this->assertTrue($ticket->refresh()->canBeSeenBy($none));
    }

    public function test_custom_fields_are_stored_for_applicable_fields_only_and_staff_only_fields_are_hidden_from_customers(): void
    {
        TicketField::create(['department_id' => null, 'key' => 'order_ref', 'label' => 'Order reference', 'type' => 'text']);
        TicketField::create(['department_id' => $this->tech->id, 'key' => 'server', 'label' => 'Server', 'type' => 'text']);
        TicketField::create(['department_id' => null, 'key' => 'triage', 'label' => 'Triage', 'type' => 'text', 'staff_only' => true]);

        $ticket = $this->open(['custom' => ['order_ref' => 'A-1', 'server' => 's1', 'triage' => 'hax', 'bogus' => 'x']]);

        $this->assertSame(['order_ref' => 'A-1'], $ticket->custom_fields);
    }

    public function test_attachments_are_stored_privately_with_random_names(): void
    {
        $ticket = $this->open(['files' => [UploadedFile::fake()->createWithContent('../../evil name.png', "\x89PNG\r\n\x1a\n".str_repeat('a', 50))]]);

        $attachment = $ticket->attachments()->first();
        $this->assertSame('evil name.png', $attachment->original_name);
        $this->assertStringNotContainsString('evil', $attachment->path);
        $this->assertStringStartsWith($ticket->uuid.'/', $attachment->path);
        Storage::disk('tickets')->assertExists($attachment->path);
        $this->assertSame(64, strlen($attachment->sha256));
    }

    public function test_dangerous_attachments_are_refused_and_nothing_is_created(): void
    {
        $bad = [
            UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET[1]);'),
            UploadedFile::fake()->createWithContent('shell.php.jpg', 'x'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('setup.exe', 'MZ'),
            UploadedFile::fake()->createWithContent('innocent.txt', '<!DOCTYPE html><html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('empty.txt', ''),
        ];
        foreach ($bad as $file) {
            try {
                $this->open(['files' => [$file]]);
                $this->fail('accepted '.$file->getClientOriginalName());
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, Ticket::count());
        $this->assertSame([], Storage::disk('tickets')->allFiles());
    }

    public function test_size_count_and_allow_list_limits_apply(): void
    {
        $files = app(TicketAttachments::class);
        Settings::set('support.max_attachment_mb', 1);
        Settings::set('support.max_attachments', 2);
        Settings::set('support.allowed_extensions', 'png, pdf');

        $this->assertNotEmpty($files->problems([UploadedFile::fake()->create('big.pdf', 2048)]));
        $this->assertNotEmpty($files->problems([UploadedFile::fake()->create('notes.txt', 1)]));
        $this->assertNotEmpty($files->problems([UploadedFile::fake()->create('a.pdf', 1), UploadedFile::fake()->create('b.pdf', 1), UploadedFile::fake()->create('c.pdf', 1)]));
        $this->assertSame([], $files->problems([UploadedFile::fake()->createWithContent('ok.pdf', '%PDF-1.4 x')]));
    }

    public function test_downloads_are_forced_attachments_with_hardening_headers(): void
    {
        $ticket = $this->open(['files' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')]]);

        $response = app(TicketAttachments::class)->download($ticket->attachments()->first());

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_dotfiles_that_configure_servers_are_refused(): void
    {
        $files = app(TicketAttachments::class);

        $this->assertNotEmpty($files->problems([UploadedFile::fake()->createWithContent('.htaccess', 'x')]));
        $this->assertNotEmpty($files->problems([UploadedFile::fake()->createWithContent('.HTPASSWD', 'x')]));
        $this->assertSame([], $files->problems([UploadedFile::fake()->createWithContent('com.example.notes.txt', 'fine')]));
    }

    public function test_ticket_webhooks_carry_the_staff_view_regardless_of_who_triggered_them(): void
    {
        WebhookEndpoint::create(['url' => 'https://hooks.test/t', 'secret' => 's', 'events' => ['ticket.created']]);

        $ticket = $this->open();

        $payload = WebhookDelivery::firstOrFail()->payload;
        $this->assertSame('ticket.created', $payload['event']);
        $this->assertSame($ticket->id, $payload['data']['id']);
        $this->assertSame($ticket->requester_email, $payload['data']['requester_email']);
        $this->assertArrayNotHasKey('messages', $payload['data']);
    }
}
