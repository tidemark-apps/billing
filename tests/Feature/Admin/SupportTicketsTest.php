<?php

namespace Tests\Feature\Admin;

use App\Enums\TicketStatus;
use App\Mail\BillingMail;
use App\Models\Client;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Settings::set('support.enabled', true);
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->tech = Department::create(['name' => 'Technical', 'slug' => 'technical', 'is_public' => false]);
    }

    private function staff(array $departments = []): User
    {
        $user = User::factory()->staff()->create();
        $user->departments()->sync(array_map(fn ($d) => $d->id, $departments));

        return $user;
    }

    private function ticket(?Department $department = null, array $extra = []): Ticket
    {
        $client = Client::factory()->create();

        return app(TicketService::class)->open($extra + [
            'department' => $department ?? $this->sales, 'subject' => 'Help me', 'body' => 'It is broken', 'client' => $client,
            'user' => User::factory()->create(['client_id' => $client->id, 'role' => 'customer']),
        ]);
    }

    public function test_every_route_is_a_404_while_support_is_off(): void
    {
        $ticket = $this->ticket();
        $att = $this->attach($ticket);
        Settings::set('support.enabled', false);
        $admin = $this->actingAs($this->admin());

        $admin->get(route('admin.tickets.index'))->assertNotFound();
        $admin->get(route('admin.tickets.create'))->assertNotFound();
        $admin->post(route('admin.tickets.store'))->assertNotFound();
        $admin->get(route('admin.tickets.show', $ticket))->assertNotFound();
        foreach (['reply', 'note', 'status', 'claim', 'assign', 'lock', 'unlock', 'priority', 'transfer'] as $action) {
            $admin->post(route("admin.tickets.$action", $ticket))->assertNotFound();
        }
        $admin->put(route('admin.tickets.fields', $ticket))->assertNotFound();
        $admin->get(route('admin.tickets.attachments.download', [$ticket, $att]))->assertNotFound();
        $admin->delete(route('admin.tickets.destroy', $ticket))->assertNotFound();
        $admin->get(route('admin.departments.index'))->assertNotFound();
        $admin->get(route('admin.departments.create'))->assertNotFound();
        $admin->get(route('admin.ticket-fields.index'))->assertNotFound();
        $admin->get(route('admin.ticket-fields.create'))->assertNotFound();
        $admin->get(route('admin.dashboard'))->assertOk()->assertDontSee('Tickets awaiting reply')->assertDontSee(route('admin.tickets.index'), false);
    }

    private function attach(Ticket $ticket, string $name = 'log.txt'): TicketAttachment
    {
        $message = $ticket->messages()->first();
        $path = $ticket->uuid.'/'.uniqid();
        Storage::disk('tickets')->put($path, 'hello');

        return TicketAttachment::create(['ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'disk' => 'tickets', 'path' => $path, 'original_name' => $name, 'mime' => 'text/plain', 'size' => 5, 'sha256' => hash('sha256', 'hello')]);
    }

    public function test_staff_see_only_their_departments_and_assigned_tickets(): void
    {
        $mine = $this->ticket($this->sales, ['subject' => 'In my department']);
        $other = $this->ticket($this->tech, ['subject' => 'Elsewhere']);
        $assigned = $this->ticket($this->tech, ['subject' => 'Assigned to me']);
        $staff = $this->staff([$this->sales]);
        $assigned->update(['assigned_to' => $staff->id]);
        $loner = $this->staff();

        $this->actingAs($staff)->get(route('admin.tickets.index'))->assertOk()
            ->assertSee('In my department')->assertSee('Assigned to me')->assertDontSee('Elsewhere');
        $this->actingAs($loner)->get(route('admin.tickets.index'))->assertOk()->assertDontSee('In my department');
        $this->actingAs($this->admin())->get(route('admin.tickets.index'))->assertSee('Elsewhere')->assertSee('In my department');

        $this->actingAs($staff)->get(route('admin.tickets.show', $mine))->assertOk()->assertSee($mine->number);
        $this->actingAs($staff)->get(route('admin.tickets.show', $assigned))->assertOk();
        $this->actingAs($staff)->get(route('admin.tickets.show', $other))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.tickets.reply', $other), ['body' => 'hi'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.tickets.note', $other), ['note' => 'hi'])->assertForbidden();
        $this->actingAs($staff)->post(route('admin.tickets.claim', $other))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.tickets.lock', $other))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.tickets.transfer', $other), ['department_id' => $this->sales->id])->assertForbidden();
        $this->actingAs($staff)->get(route('admin.tickets.attachments.download', [$other, $this->attach($other)]))->assertForbidden();
        $this->assertNull($other->fresh()->assigned_to);
        $this->actingAs($staff)->delete(route('admin.tickets.destroy', $mine))->assertForbidden();
        $this->actingAs($this->customer())->get(route('admin.tickets.index'))->assertForbidden();
    }

    public function test_tabs_and_filters(): void
    {
        $staff = $this->staff([$this->sales]);
        $a = $this->ticket($this->sales, ['subject' => 'Alpha question']);
        $b = $this->ticket($this->sales, ['subject' => 'Beta question', 'priority' => 'urgent']);
        $b->update(['assigned_to' => $staff->id, 'status' => TicketStatus::Answered]);
        $c = $this->ticket($this->sales, ['subject' => 'Gamma question']);
        $c->update(['status' => TicketStatus::Closed]);
        $me = $this->actingAs($staff);

        $me->get(route('admin.tickets.index', ['tab' => 'mine']))->assertSee('Beta question')->assertDontSee('Alpha question');
        $me->get(route('admin.tickets.index', ['tab' => 'unassigned']))->assertSee('Alpha question')->assertDontSee('Beta question');
        $me->get(route('admin.tickets.index', ['tab' => 'awaiting']))->assertSee('Alpha question')->assertDontSee('Beta question');
        $me->get(route('admin.tickets.index', ['tab' => 'closed']))->assertSee('Gamma question')->assertDontSee('Alpha question');
        $me->get(route('admin.tickets.index', ['q' => 'Beta']))->assertSee('Beta question')->assertDontSee('Alpha question');
        $me->get(route('admin.tickets.index', ['q' => $a->number]))->assertSee('Alpha question')->assertDontSee('Beta question');
        $me->get(route('admin.tickets.index', ['priority' => 'urgent']))->assertSee('Beta question')->assertDontSee('Alpha question');
    }

    public function test_reply_sends_mail_and_sets_status_and_notes_do_not(): void
    {
        Mail::fake();
        $staff = $this->staff([$this->sales]);
        $ticket = $this->ticket();
        Mail::fake(); // forget the acknowledgement sent on opening
        $this->actingAs($staff)->get(route('admin.tickets.show', $ticket))->assertOk();

        $this->actingAs($staff)->post(route('admin.tickets.reply', $ticket), ['body' => 'We are on it', 'status' => 'on_hold'])->assertRedirect(route('admin.tickets.show', $ticket));
        $ticket->refresh();
        $this->assertSame(TicketStatus::OnHold, $ticket->status);
        $this->assertTrue($ticket->messages()->where('kind', 'reply')->where('body', 'We are on it')->exists());
        Mail::assertSent(BillingMail::class, 1);

        Mail::fake();
        $this->actingAs($staff)->post(route('admin.tickets.note', $ticket), ['note' => 'Secret thought'])->assertRedirect();
        $this->assertTrue($ticket->messages()->where('kind', 'note')->where('body', 'Secret thought')->exists());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->actingAs($staff)->get(route('admin.tickets.show', $ticket))->assertSee('INTERNAL NOTE')->assertSee('Secret thought');
        $this->assertFalse($ticket->publicMessages()->where('body', 'Secret thought')->exists());
    }

    public function test_empty_reply_and_bad_files_are_flashed_not_fatal(): void
    {
        $staff = $this->staff([$this->sales]);
        $ticket = $this->ticket();

        $this->actingAs($staff)->post(route('admin.tickets.reply', $ticket), ['body' => ''])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($staff)->post(route('admin.tickets.reply', $ticket), ['body' => 'x', 'attachments' => [UploadedFile::fake()->create('evil.php', 1)]])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, $ticket->messages()->where('kind', 'reply')->count());
    }

    public function test_reply_and_note_attachments_download_safely(): void
    {
        $staff = $this->staff([$this->sales]);
        $ticket = $this->ticket();
        $this->actingAs($staff)->post(route('admin.tickets.reply', $ticket), ['body' => 'See file', 'attachments' => [UploadedFile::fake()->createWithContent('notes.txt', 'plain text')]])->assertSessionHasNoErrors();
        $att = TicketAttachment::firstOrFail();

        $res = $this->actingAs($staff)->get(route('admin.tickets.attachments.download', [$ticket, $att]));
        $res->assertOk();
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($staff)->get(route('admin.tickets.show', $ticket))->assertSee('notes.txt')->assertSee(route('admin.tickets.attachments.download', [$ticket, $att]), false);

        $other = $this->ticket();
        $this->actingAs($staff)->get(route('admin.tickets.attachments.download', [$other, $att]))->assertNotFound();
    }

    public function test_claim_assign_status_lock_priority_and_transfer(): void
    {
        $staff = $this->staff([$this->sales]);
        $colleague = $this->staff([$this->sales]);
        $outsider = $this->staff([$this->tech]);
        $ticket = $this->ticket();
        $act = $this->actingAs($staff);

        $act->post(route('admin.tickets.claim', $ticket))->assertSessionHas('status');
        $this->assertSame($staff->id, $ticket->fresh()->assigned_to);
        $act->post(route('admin.tickets.assign', $ticket), ['user_id' => $colleague->id])->assertSessionHas('status');
        $this->assertSame($colleague->id, $ticket->fresh()->assigned_to);
        $act->post(route('admin.tickets.assign', $ticket), ['user_id' => $outsider->id])->assertSessionHas('error');
        $this->assertSame($colleague->id, $ticket->fresh()->assigned_to);
        $act->post(route('admin.tickets.assign', $ticket), ['user_id' => ''])->assertSessionHas('status');
        $this->assertNull($ticket->fresh()->assigned_to);

        $act->post(route('admin.tickets.priority', $ticket), ['priority' => 'urgent']);
        $this->assertSame('urgent', $ticket->fresh()->priority->value);
        $act->post(route('admin.tickets.status', $ticket), ['status' => 'closed']);
        $this->assertTrue($ticket->fresh()->isClosed());
        $act->post(route('admin.tickets.status', $ticket), ['status' => 'open']);
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
        $act->post(route('admin.tickets.status', $ticket), ['status' => 'nonsense'])->assertSessionHasErrors('status');

        $act->post(route('admin.tickets.lock', $ticket));
        $this->assertTrue($ticket->fresh()->is_locked);
        $act->post(route('admin.tickets.unlock', $ticket));
        $this->assertFalse($ticket->fresh()->is_locked);

        $act->post(route('admin.tickets.transfer', $ticket), ['department_id' => $this->tech->id])->assertSessionHas('error'); // not in Technical
        $admin = $this->actingAs($this->admin());
        $admin->post(route('admin.tickets.transfer', $ticket), ['department_id' => $this->tech->id]);
        $this->assertSame($this->tech->id, $ticket->fresh()->department_id);
        $this->actingAs($staff)->get(route('admin.tickets.show', $ticket))->assertForbidden(); // moved out of their reach
    }

    public function test_only_admins_delete_tickets(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->staff([$this->sales]))->delete(route('admin.tickets.destroy', $ticket))->assertForbidden();
        $this->actingAs($this->admin())->delete(route('admin.tickets.destroy', $ticket))->assertRedirect(route('admin.tickets.index'));
        $this->assertNull(Ticket::find($ticket->id));
    }

    public function test_staff_open_a_ticket_for_a_client_with_custom_fields(): void
    {
        Mail::fake();
        $staff = $this->staff([$this->sales]);
        $client = Client::factory()->create();
        TicketField::create(['key' => 'order_ref', 'label' => 'Order', 'type' => 'text', 'required' => true]);
        TicketField::create(['key' => 'triage', 'label' => 'Triage', 'type' => 'text', 'staff_only' => true]);

        $this->actingAs($staff)->get(route('admin.tickets.create'))->assertOk()->assertSee('custom[order_ref]', false)->assertSee('Sales');
        $this->actingAs($staff)->post(route('admin.tickets.store'), ['client_id' => $client->id, 'department_id' => $this->sales->id, 'subject' => 'Call back', 'body' => 'Please call', 'priority' => 'high'])
            ->assertSessionHasErrors('custom.order_ref');
        $this->actingAs($staff)->post(route('admin.tickets.store'), ['client_id' => $client->id, 'department_id' => $this->tech->id, 'subject' => 'x', 'body' => 'x', 'priority' => 'low'])
            ->assertSessionHasErrors('department_id'); // not in Technical

        $this->actingAs($staff)->post(route('admin.tickets.store'), ['client_id' => $client->id, 'department_id' => $this->sales->id, 'subject' => 'Call back', 'body' => 'Please call', 'priority' => 'high', 'custom' => ['order_ref' => 'A-1', 'triage' => 'vip']])->assertSessionHasNoErrors();
        $ticket = Ticket::firstOrFail();
        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame('high', $ticket->priority->value);
        $this->assertSame('admin', $ticket->source);
        $this->assertSame(['order_ref' => 'A-1', 'triage' => 'vip'], $ticket->custom_fields);

        $other = Client::factory()->create();
        $service = Service::create(['client_id' => $other->id, 'product_id' => Product::factory()->priced(100)->create()->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'currency' => 'USD', 'amount' => 100]);
        $this->actingAs($staff)->post(route('admin.tickets.store'), ['client_id' => $client->id, 'service_id' => $service->id, 'department_id' => $this->sales->id, 'subject' => 'Mismatch', 'body' => 'x', 'priority' => 'low', 'custom' => ['order_ref' => 'A-2']])
            ->assertSessionHas('error');
        $this->assertSame(1, Ticket::count());
    }

    public function test_staff_only_fields_are_editable_on_the_ticket(): void
    {
        $staff = $this->staff([$this->sales]);
        TicketField::create(['key' => 'triage', 'label' => 'Triage level', 'type' => 'select', 'options' => ['gold', 'silver'], 'staff_only' => true]);
        $ticket = $this->ticket();

        $this->actingAs($staff)->get(route('admin.tickets.show', $ticket))->assertSee('Triage level');
        $this->actingAs($staff)->put(route('admin.tickets.fields', $ticket), ['custom' => ['triage' => 'gold']])->assertSessionHasNoErrors();
        $this->assertSame(['triage' => 'gold'], $ticket->fresh()->custom_fields);
        $this->actingAs($staff)->put(route('admin.tickets.fields', $ticket), ['custom' => ['triage' => 'bronze']])->assertSessionHasErrors('custom.triage');
    }

    public function test_dashboard_card_and_sidebar_show_for_staff_with_access(): void
    {
        $this->ticket();
        $staff = $this->staff([$this->sales]);
        $this->actingAs($staff)->get(route('admin.dashboard'))->assertOk()->assertSee('Tickets awaiting reply')->assertSee(route('admin.tickets.index'), false)
            ->assertDontSee(route('admin.departments.index'), false);
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertSee(route('admin.departments.index'), false)->assertSee(route('admin.ticket-fields.index'), false);
    }
}
