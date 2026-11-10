<?php

namespace Tests\Feature\Portal;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketField;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $hidden;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Settings::set('support.enabled', '1');
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->hidden = Department::create(['name' => 'Secret Desk', 'slug' => 'secret', 'is_public' => false]);
        $this->user = $this->customer();
    }

    private function ticketFor(User $user, array $over = []): Ticket
    {
        return app(TicketService::class)->open($over + ['department' => $this->sales, 'subject' => 'Help me', 'body' => 'It broke', 'client' => $user->client, 'user' => $user, 'notify' => false]);
    }

    private function staffUser(): User
    {
        return User::factory()->staff()->create(['name' => 'Sam Staffer', 'email' => 'sam.staff@example.test']);
    }

    private function service(User $user): Service
    {
        return Service::create(['client_id' => $user->client_id, 'product_id' => Product::factory()->priced(100)->create()->id, 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'currency' => 'USD', 'amount' => 100]);
    }

    public function test_everything_is_a_404_when_support_is_off(): void
    {
        $ticket = $this->ticketFor($this->user);
        Settings::set('support.enabled', '0');
        $this->actingAs($this->user);
        $this->get(route('portal.tickets.index'))->assertNotFound();
        $this->get(route('portal.tickets.create'))->assertNotFound();
        $this->post(route('portal.tickets.store'), [])->assertNotFound();
        $this->get(route('portal.tickets.show', $ticket->uuid))->assertNotFound();
        $this->post(route('portal.tickets.reply', $ticket->uuid), ['body' => 'x'])->assertNotFound();
        $this->post(route('portal.tickets.close', $ticket->uuid))->assertNotFound();
    }

    public function test_dashboard_card_and_nav_link_only_when_enabled(): void
    {
        $this->actingAs($this->user)->get(route('portal.dashboard'))->assertOk()->assertSee('Open support tickets')->assertSee(route('portal.tickets.index'), false);
        Settings::set('support.enabled', '0');
        $this->actingAs($this->user)->get(route('portal.dashboard'))->assertOk()->assertDontSee('Open support tickets')->assertDontSee(route('portal.tickets.index'), false);
    }

    public function test_service_page_offers_get_help_link(): void
    {
        $service = $this->service($this->user);
        $this->actingAs($this->user)->get(route('portal.services.show', $service))->assertSee('Get help')->assertSee('service='.$service->uuid, false);
        Settings::set('support.enabled', '0');
        $this->actingAs($this->user)->get(route('portal.services.show', $service))->assertDontSee('Get help');
    }

    public function test_create_ticket_with_custom_fields_and_attachment(): void
    {
        TicketField::create(['department_id' => $this->sales->id, 'key' => 'urgency', 'label' => 'Urgency', 'type' => 'select', 'options' => ['Low', 'High'], 'required' => true]);
        $service = $this->service($this->user);

        $this->actingAs($this->user)->post(route('portal.tickets.store'), [
            'department' => 'sales', 'subject' => 'Cannot log in', 'body' => 'Please help', 'service' => $service->uuid,
            'custom' => ['urgency' => 'High'], 'attachments' => [UploadedFile::fake()->createWithContent('log.txt', 'hello world')],
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::firstOrFail();
        $this->assertSame($this->user->client_id, $ticket->client_id);
        $this->assertSame($service->id, $ticket->service_id);
        $this->assertSame(['urgency' => 'High'], $ticket->custom_fields);
        $this->assertSame(1, TicketAttachment::count());
        $this->get(route('portal.tickets.show', $ticket->uuid))->assertOk()->assertSee('Cannot log in')->assertSee('log.txt')->assertSee('Urgency');
    }

    public function test_missing_required_field_of_selected_department_is_rejected_but_other_departments_fields_are_ignored(): void
    {
        TicketField::create(['department_id' => $this->sales->id, 'key' => 'urgency', 'label' => 'Urgency', 'type' => 'text', 'required' => true]);
        $other = Department::create(['name' => 'Tech', 'slug' => 'tech']);
        TicketField::create(['department_id' => $other->id, 'key' => 'os', 'label' => 'OS', 'type' => 'text', 'required' => true]);

        $this->actingAs($this->user)->post(route('portal.tickets.store'), ['department' => 'sales', 'subject' => 'S', 'body' => 'B'])->assertSessionHasErrors('custom.urgency');
        $this->post(route('portal.tickets.store'), ['department' => 'sales', 'subject' => 'S', 'body' => 'B', 'custom' => ['urgency' => 'now', 'os' => 'x']])->assertSessionHasNoErrors();
        $this->assertSame(['urgency' => 'now'], Ticket::firstOrFail()->custom_fields);
    }

    public function test_dangerous_attachment_is_rejected_and_no_ticket_is_created(): void
    {
        $this->actingAs($this->user)->post(route('portal.tickets.store'), [
            'department' => 'sales', 'subject' => 'S', 'body' => 'B', 'attachments' => [UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')],
        ])->assertSessionHasErrors('attachments');
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_priority_cannot_be_set_by_the_customer(): void
    {
        $this->actingAs($this->user)->post(route('portal.tickets.store'), ['department' => 'sales', 'subject' => 'S', 'body' => 'B', 'priority' => 'urgent']);
        $this->assertSame('normal', Ticket::firstOrFail()->priority->value);
    }

    public function test_service_from_another_client_is_rejected(): void
    {
        $foreign = $this->service($this->customer());
        $this->actingAs($this->user)->post(route('portal.tickets.store'), ['department' => 'sales', 'subject' => 'S', 'body' => 'B', 'service' => $foreign->uuid])->assertSessionHasErrors('service');
        $this->assertSame(0, Ticket::count());
        $this->get(route('portal.tickets.create', ['service' => $foreign->uuid]))->assertOk()->assertDontSee($foreign->uuid);
    }

    public function test_unlisted_department_is_hidden_but_usable_by_direct_link(): void
    {
        $this->actingAs($this->user)->get(route('portal.tickets.create'))->assertOk()->assertSee('Sales')->assertDontSee('Secret Desk');
        $this->get(route('portal.tickets.create', ['department' => 'secret']))->assertOk()->assertSee('Secret Desk')->assertSee('value="secret"', false);
        $this->post(route('portal.tickets.store'), ['department' => 'secret', 'subject' => 'S', 'body' => 'B'])->assertSessionHasNoErrors();
        $this->assertSame($this->hidden->id, Ticket::firstOrFail()->department_id);
    }

    public function test_inactive_department_is_never_usable(): void
    {
        $this->sales->update(['is_active' => false]);
        $this->actingAs($this->user)->get(route('portal.tickets.create', ['department' => 'sales']))->assertOk()->assertDontSee('value="sales"', false);
        $this->post(route('portal.tickets.store'), ['department' => 'sales', 'subject' => 'S', 'body' => 'B'])->assertSessionHasErrors('department');
        $this->assertSame(0, Ticket::count());
    }

    public function test_other_customers_tickets_are_404_for_every_action(): void
    {
        $mine = $this->ticketFor($this->user);
        $theirs = $this->ticketFor($this->customer(), ['subject' => 'Their private subject']);
        $message = $theirs->messages()->first();
        $file = $this->attachment($theirs, $message);

        $this->actingAs($this->user);
        $this->get(route('portal.tickets.index'))->assertOk()->assertDontSee('Their private subject');
        $this->get(route('portal.tickets.show', $theirs->uuid))->assertNotFound();
        $this->post(route('portal.tickets.reply', $theirs->uuid), ['body' => 'hi'])->assertNotFound();
        $this->post(route('portal.tickets.close', $theirs->uuid))->assertNotFound();
        $this->get(route('portal.tickets.attachments.download', [$theirs->uuid, $file->uuid]))->assertNotFound();
        // An attachment of someone else's ticket cannot be fetched through my own ticket either.
        $this->get(route('portal.tickets.attachments.download', [$mine->uuid, $file->uuid]))->assertNotFound();
        $this->assertSame(TicketStatus::Open, $theirs->refresh()->status);
    }

    public function test_locked_ticket_cannot_be_replied_to_or_closed(): void
    {
        $ticket = $this->ticketFor($this->user);
        $ticket->update(['is_locked' => true]);
        $this->actingAs($this->user)->get(route('portal.tickets.show', $ticket->uuid))->assertOk()->assertSee('locked')->assertDontSee('Send reply')->assertDontSee('Close ticket');
        $this->post(route('portal.tickets.reply', $ticket->uuid), ['body' => 'hello'])->assertSessionHas('error');
        $this->post(route('portal.tickets.close', $ticket->uuid))->assertSessionHas('error');
        $this->assertSame(1, $ticket->messages()->where('kind', 'reply')->count());
        $this->assertSame(TicketStatus::Open, $ticket->refresh()->status);
    }

    public function test_reply_reopens_a_closed_ticket_and_customer_can_close(): void
    {
        $ticket = $this->ticketFor($this->user);
        $this->actingAs($this->user)->post(route('portal.tickets.close', $ticket->uuid))->assertRedirect();
        $this->assertSame(TicketStatus::Closed, $ticket->refresh()->status);

        $this->post(route('portal.tickets.reply', $ticket->uuid), ['body' => 'Still broken'])->assertSessionHasNoErrors();
        $this->assertSame(TicketStatus::CustomerReply, $ticket->refresh()->status);
        $this->assertSame(2, $ticket->messages()->where('kind', 'reply')->count());
    }

    public function test_staff_replies_show_first_name_only_and_never_the_email(): void
    {
        $ticket = $this->ticketFor($this->user);
        app(TicketService::class)->reply($ticket, 'We are on it', $this->staffUser(), true);
        $this->actingAs($this->user)->get(route('portal.tickets.show', $ticket->uuid))->assertOk()
            ->assertSee('We are on it')->assertSee('Sam')->assertDontSee('Staffer')->assertDontSee('sam.staff@example.test');
    }

    public function test_internal_notes_staff_fields_and_note_attachments_stay_hidden(): void
    {
        TicketField::create(['key' => 'internal_code', 'label' => 'Secret label', 'type' => 'text', 'staff_only' => true]);
        $ticket = $this->ticketFor($this->user, ['custom' => ['internal_code' => 'ZX-SECRET']]);
        $staff = $this->staffUser();
        $note = app(TicketService::class)->note($ticket, $staff, 'Customer is a bit difficult');
        $noteFile = $this->attachment($ticket, $note);
        $ticket->update(['custom_fields' => ['internal_code' => 'ZX-SECRET']]);

        $this->actingAs($this->user)->get(route('portal.tickets.show', $ticket->uuid))->assertOk()
            ->assertDontSee('Customer is a bit difficult')->assertDontSee('Secret label')->assertDontSee('ZX-SECRET')->assertDontSee($noteFile->uuid)->assertDontSee('internal-note.txt');
        $this->get(route('portal.tickets.attachments.download', [$ticket->uuid, $noteFile->uuid]))->assertNotFound();
        $this->get(route('portal.tickets.create'))->assertDontSee('Secret label');
    }

    public function test_reply_attachment_downloads_for_the_owner(): void
    {
        $ticket = $this->ticketFor($this->user);
        $file = $this->attachment($ticket, $ticket->messages()->first(), 'reply-file.txt');
        $this->actingAs($this->user)->get(route('portal.tickets.attachments.download', [$ticket->uuid, $file->uuid]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function attachment(Ticket $ticket, $message, string $name = 'internal-note.txt'): TicketAttachment
    {
        Storage::disk('tickets')->put('x/'.$name, 'content');

        return TicketAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'disk' => 'tickets', 'path' => 'x/'.$name, 'original_name' => $name, 'mime' => 'text/plain', 'size' => 7, 'sha256' => str_repeat('a', 64)]);
    }
}
