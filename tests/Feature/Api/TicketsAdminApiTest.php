<?php

namespace Tests\Feature\Api;

use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketReplied;
use App\Events\TicketStatusChanged;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Abilities;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The staff side of the ticket API: /admin/tickets. */
class TicketsAdminApiTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private Department $sales;

    private Department $tech;

    private User $admin;

    private User $salesStaff;

    private User $techStaff;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Settings::set('support.enabled', true);
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->tech = Department::create(['name' => 'Tech', 'slug' => 'tech', 'is_public' => false]);
        $this->admin = $this->admin();
        $this->salesStaff = User::factory()->staff()->create();
        $this->salesStaff->departments()->sync([$this->sales->id]);
        $this->techStaff = User::factory()->staff()->create();
        $this->techStaff->departments()->sync([$this->tech->id]);
        $this->customer = $this->customer();
    }

    private function ticket(?Department $dept = null, array $over = []): Ticket
    {
        return app(TicketService::class)->open($over + ['department' => $dept ?? $this->sales, 'subject' => 'Help', 'body' => 'It broke', 'client' => $this->customer->client, 'user' => $this->customer, 'notify' => false]);
    }

    private function api(string $path = ''): string
    {
        return '/api/v1/admin/tickets'.$path;
    }

    public function test_listing_is_limited_to_the_users_departments_and_assigned_tickets(): void
    {
        $inSales = $this->ticket($this->sales, ['subject' => 'Sales one']);
        $inTech = $this->ticket($this->tech, ['subject' => 'Tech one']);
        $assigned = $this->ticket($this->tech, ['subject' => 'Tech but mine']);
        app(TicketService::class)->assign($assigned, $this->admin, $this->admin);
        $loner = User::factory()->staff()->create(); // in no department
        $loner->departments()->detach();

        $ids = fn (User $u, string $qs = '') => array_column($this->as($u)->getJson($this->api().$qs)->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$inSales->id, $inTech->id, $assigned->id], $ids($this->admin));
        $this->assertSame([$inSales->id], $ids($this->salesStaff));
        $this->assertEqualsCanonicalizing([$inTech->id, $assigned->id], $ids($this->techStaff));
        $this->assertSame([], $ids($loner));

        // being assigned a ticket grants access to it
        $assignedToLoner = $this->ticket($this->tech);
        $assignedToLoner->update(['assigned_to' => $loner->id]);
        $this->assertSame([$assignedToLoner->id], $ids($loner));
        $this->as($loner)->getJson($this->api("/{$assignedToLoner->id}"))->assertOk();
        $this->as($loner)->getJson($this->api("/{$inSales->id}"))->assertNotFound();
    }

    public function test_staff_cannot_touch_tickets_outside_their_departments(): void
    {
        $techTicket = $this->ticket($this->tech);
        $att = $this->as($this->admin)->postJson($this->api("/{$techTicket->id}/notes"), ['message' => 'n', 'attachments' => [['filename' => 'a.txt', 'content_base64' => base64_encode('x')]]])->json('data.attachments.0.uuid');

        $calls = [
            ['getJson', ''], ['patchJson', ''], ['postJson', '/replies'], ['postJson', '/notes'], ['postJson', '/assign'], ['postJson', '/claim'], ['postJson', '/lock'],
            ['postJson', '/unlock'], ['postJson', '/close'], ['postJson', '/reopen'], ['postJson', '/transfer'], ['getJson', "/attachments/$att"],
        ];
        foreach ($calls as [$method, $suffix]) {
            $this->as($this->salesStaff)->$method($this->api("/{$techTicket->id}$suffix"), ['message' => 'x', 'user_id' => $this->salesStaff->id, 'department_id' => $this->sales->id, 'status' => 'closed'])->assertNotFound();
        }
        $this->as($this->salesStaff)->deleteJson($this->api("/{$techTicket->id}"))->assertStatus(403);
        $fresh = $techTicket->refresh();
        $this->assertSame('open', $fresh->status->value);
        $this->assertSame($this->tech->id, $fresh->department_id);
        $this->assertNull($fresh->assigned_to);
        $this->assertSame(2, $fresh->messages()->count()); // the opening message and the admin's note only
    }

    public function test_filters(): void
    {
        $a = $this->ticket($this->sales, ['subject' => 'Billing question']);
        $b = $this->ticket($this->tech, ['subject' => 'Server crash', 'priority' => 'high']);
        $c = $this->ticket($this->sales, ['subject' => 'Other', 'client' => ($other = $this->customer())->client, 'user' => $other]);
        app(TicketService::class)->assign($a, $this->admin, $this->admin);
        app(TicketService::class)->close($c);

        $ids = fn (string $qs) => array_column($this->as($this->admin)->getJson($this->api('?'.$qs))->assertOk()->json('data'), 'id');
        $this->assertSame([$c->id], $ids('status=closed'));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids('status=open,answered'));
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $ids('department_id='.$this->sales->id));
        $this->assertSame([$a->id], $ids('assigned_to='.$this->admin->id));
        $this->assertSame([$a->id], $ids('mine=1'));
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $ids('unassigned=1'));
        $this->assertSame([$c->id], $ids('client_id='.$other->client_id));
        $this->assertSame([$b->id], $ids('q=crash'));
        $this->assertSame([$a->id], $ids('q='.$a->number));
        $this->assertSame([$b->id], $ids('priority=high'));
        $this->assertSame([$b->id], array_column($this->as($this->admin)->getJson($this->api('?per_page=1&department_id='.$this->tech->id))->assertJsonPath('meta.per_page', 1)->json('data'), 'id'));
    }

    public function test_show_includes_notes_events_attachments_and_custom_fields(): void
    {
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text']);
        TicketField::create(['key' => 'cost_centre', 'label' => 'CC', 'type' => 'text', 'staff_only' => true]);
        $ticket = $this->ticket($this->sales, ['custom' => ['domain' => 'x.com']]);
        $ticket->update(['custom_fields' => ['domain' => 'x.com', 'cost_centre' => 'CC-1']]);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/notes"), ['message' => 'Internal thing', 'attachments' => [['filename' => 'n.txt', 'content_base64' => base64_encode('NOTE')]]])->assertCreated()
            ->assertJsonPath('data.kind', 'note')->assertJsonPath('data.attachments.0.filename', 'n.txt');
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/lock"))->assertOk();

        $res = $this->as($this->salesStaff)->getJson($this->api("/{$ticket->id}"))->assertOk();
        $this->assertSame(['reply', 'note', 'event'], array_column($res->json('data.messages'), 'kind'));
        $this->assertSame('CC-1', $res->json('data.custom_fields.cost_centre'));
        $this->assertSame($this->customer->client_id, $res->json('data.client_id'));
        $this->assertSame($this->customer->email, $res->json('data.requester_email'));
        $this->assertTrue($res->json('data.is_locked'));
        $this->assertStringContainsString("/admin/tickets/{$ticket->id}/attachments/", $res->json('data.messages.1.attachments.0.download_url'));
        $this->assertSame('NOTE', $this->as($this->salesStaff)->get($res->json('data.messages.1.attachments.0.download_url'))->assertOk()->streamedContent());
    }

    public function test_opening_on_behalf_of_a_client(): void
    {
        Event::fake([TicketCreated::class]);
        TicketField::create(['key' => 'cost_centre', 'label' => 'CC', 'type' => 'text', 'staff_only' => true]);
        $service = $this->makeService($this->customer->client);

        $res = $this->as($this->salesStaff)->postJson($this->api(), [
            'client_id' => $this->customer->client_id, 'user_id' => $this->customer->id, 'department_id' => $this->sales->id, 'subject' => 'We called', 'message' => 'Following up',
            'service_id' => $service->id, 'priority' => 'high', 'custom' => ['cost_centre' => 'CC-7'], 'notify' => false,
        ])->assertCreated()->assertJsonPath('data.status', 'answered')->assertJsonPath('data.priority', 'high')->assertJsonPath('data.custom_fields.cost_centre', 'CC-7')
            ->assertJsonPath('data.messages.0.author_type', 'staff')->assertJsonPath('data.requester_email', $this->customer->email)->assertJsonPath('data.source', 'api');
        Event::assertDispatched(TicketCreated::class);
        $this->assertSame($service->id, Ticket::find($res->json('data.id'))->service_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'ticket.created', 'subject_id' => $res->json('data.id')]);

        $base = ['client_id' => $this->customer->client_id, 'department_id' => $this->sales->id, 'subject' => 's', 'message' => 'm'];
        $this->as($this->salesStaff)->postJson($this->api(), ['department_id' => $this->tech->id] + $base)->assertStatus(403); // not their department
        $this->as($this->admin)->postJson($this->api(), ['user_id' => $this->salesStaff->id] + $base)->assertStatus(422); // not a login of that client
        $this->as($this->admin)->postJson($this->api(), ['service_id' => $this->makeService()->id] + $base)->assertStatus(422); // someone else's service
        $this->as($this->admin)->postJson($this->api(), ['client_id' => 9999] + $base)->assertStatus(422);
        $this->as($this->admin)->postJson($this->api(), ['priority' => 'sideways'] + $base)->assertStatus(422);
        $this->as($this->admin)->postJson($this->api(), ['custom' => ['nope' => 1]] + $base)->assertStatus(422);
        $inactive = Department::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
        $this->as($this->admin)->postJson($this->api(), ['department_id' => $inactive->id] + $base)->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        // staff can open in an unlisted department; customers cannot (see the client tests)
        $this->as($this->admin)->postJson($this->api(), ['department_id' => $this->tech->id] + $base)->assertCreated();
    }

    public function test_reply_note_and_status_workflow(): void
    {
        Event::fake([TicketReplied::class, TicketStatusChanged::class]);
        $ticket = $this->ticket();

        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/replies"), ['message' => 'On it'])->assertCreated()->assertJsonPath('data.author_type', 'staff')->assertJsonPath('data.kind', 'reply');
        $this->assertSame('answered', $ticket->refresh()->status->value);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/replies"), ['message' => 'Hold on', 'status' => 'on_hold'])->assertCreated();
        $this->assertSame('on_hold', $ticket->refresh()->status->value);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/replies"), [])->assertStatus(422);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/replies"), ['message' => 'x', 'status' => 'bogus'])->assertStatus(422);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/notes"), [])->assertStatus(422);
        Event::assertDispatched(TicketReplied::class);

        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/close"))->assertOk()->assertJsonPath('data.status', 'closed');
        Event::assertDispatched(TicketStatusChanged::class);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/reopen"))->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.closed_at', null);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/reopen"))->assertStatus(422)->assertJsonPath('error.code', 'domain_error');

        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['status' => 'closed', 'priority' => 'urgent'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.priority', 'urgent');
        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['status' => 'wat'])->assertStatus(422);
        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['priority' => 'wat'])->assertStatus(422);
    }

    public function test_staff_reply_with_multipart_attachment_and_dangerous_files_refused(): void
    {
        $ticket = $this->ticket();
        $this->as($this->salesStaff)->post($this->api("/{$ticket->id}/replies"), ['message' => 'File', 'files' => [UploadedFile::fake()->createWithContent('guide.pdf', "%PDF-1.4\n%%EOF")]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.attachments.0.mime', 'application/pdf');
        $before = Storage::disk('tickets')->allFiles();
        foreach (['evil.php', 'evil.html', 'evil.svg', 'evil.sh', 'evil.exe'] as $name) {
            $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/notes"), ['message' => 'x', 'attachments' => [['filename' => $name, 'content_base64' => base64_encode('<?php x')]]])->assertStatus(422);
            $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/replies"), ['message' => 'x', 'attachments' => [['filename' => $name, 'content_base64' => base64_encode('<?php x')]]])->assertStatus(422);
        }
        $this->assertSame($before, Storage::disk('tickets')->allFiles());
        $this->assertSame(2, $ticket->publicMessages()->count()); // opening message + the one good reply
    }

    public function test_custom_fields_update_clears_and_validates(): void
    {
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true]);
        TicketField::create(['key' => 'cost_centre', 'label' => 'CC', 'type' => 'text', 'staff_only' => true]);
        TicketField::create(['key' => 'urgent', 'label' => 'Urgent', 'type' => 'checkbox']);
        $ticket = $this->ticket($this->sales, ['custom' => ['domain' => 'a.com']]);

        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['custom_fields' => ['cost_centre' => 'CC-2', 'urgent' => true]])->assertOk()
            ->assertJsonPath('data.custom_fields.cost_centre', 'CC-2')->assertJsonPath('data.custom_fields.urgent', true)->assertJsonPath('data.custom_fields.domain', 'a.com');
        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['custom_fields' => ['cost_centre' => null]])->assertOk();
        $this->assertSame(['domain' => 'a.com', 'urgent' => true], $ticket->refresh()->custom_fields);
        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['custom_fields' => ['domain' => '']])->assertStatus(422); // required
        $this->as($this->salesStaff)->patchJson($this->api("/{$ticket->id}"), ['custom_fields' => ['ghost' => 'x']])->assertStatus(422);
        $this->assertSame('a.com', $ticket->refresh()->custom_fields['domain']);
    }

    public function test_assign_claim_lock_transfer(): void
    {
        Event::fake([TicketAssigned::class]);
        $ticket = $this->ticket($this->sales);
        $other = User::factory()->staff()->create();
        $other->departments()->sync([$this->sales->id]);

        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/claim"))->assertOk()->assertJsonPath('data.assigned_to', $this->salesStaff->id)->assertJsonPath('data.assignee.name', $this->salesStaff->name);
        Event::assertDispatched(TicketAssigned::class);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => $other->id])->assertOk()->assertJsonPath('data.assigned_to', $other->id);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => $this->techStaff->id])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => $this->customer->id])->assertStatus(422);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), [])->assertStatus(422); // user_id must be present (null unassigns)
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => 99999])->assertStatus(422);
        $this->as($this->admin)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => $this->admin->id])->assertOk();
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => null])->assertOk()->assertJsonPath('data.assigned_to', null);

        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/lock"))->assertOk()->assertJsonPath('data.is_locked', true);
        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/unlock"))->assertOk()->assertJsonPath('data.is_locked', false);

        $this->as($this->salesStaff)->postJson($this->api("/{$ticket->id}/assign"), ['user_id' => $other->id]);
        $this->as($this->admin)->postJson($this->api("/{$ticket->id}/transfer"), ['department_id' => $this->tech->id])->assertOk()->assertJsonPath('data.department.slug', 'tech')->assertJsonPath('data.assigned_to', null);
        $this->as($this->admin)->postJson($this->api("/{$ticket->id}/transfer"), ['department_id' => 9999])->assertStatus(422);
        $inactive = Department::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
        $this->as($this->admin)->postJson($this->api("/{$ticket->id}/transfer"), ['department_id' => $inactive->id])->assertStatus(422);
        // the sales-only member lost access along with the ticket
        $this->as($this->salesStaff)->getJson($this->api("/{$ticket->id}"))->assertNotFound();
    }

    public function test_delete_is_admin_only_and_removes_files(): void
    {
        $ticket = $this->ticket();
        $this->as($this->admin)->postJson($this->api("/{$ticket->id}/notes"), ['message' => 'n', 'attachments' => [['filename' => 'a.txt', 'content_base64' => base64_encode('x')]]])->assertCreated();
        $this->assertNotEmpty(Storage::disk('tickets')->allFiles());

        $this->as($this->salesStaff)->deleteJson($this->api("/{$ticket->id}"))->assertStatus(403);
        $this->assertNotNull(Ticket::find($ticket->id));
        $this->as($this->admin)->deleteJson($this->api("/{$ticket->id}"))->assertNoContent();
        $this->assertNull(Ticket::find($ticket->id));
        $this->assertSame([], Storage::disk('tickets')->allFiles());
        $this->as($this->admin)->getJson($this->api("/{$ticket->id}"))->assertNotFound();
        $this->assertDatabaseHas('activity_logs', ['action' => 'ticket.deleted']);
    }

    public function test_abilities_and_audience(): void
    {
        $ticket = $this->ticket();
        $this->flushHeaders()->getJson($this->api())->assertStatus(401);
        $this->as($this->customer)->getJson($this->api())->assertStatus(403);

        $read = ['tickets.read'];
        $write = ['tickets.write'];
        $this->as($this->admin, $write)->getJson($this->api())->assertStatus(403);
        $this->as($this->admin, $write)->getJson($this->api("/{$ticket->id}"))->assertStatus(403);
        $this->as($this->admin, $read)->postJson($this->api("/{$ticket->id}/replies"), ['message' => 'x'])->assertStatus(403);
        $this->as($this->admin, $read)->postJson($this->api("/{$ticket->id}/notes"), ['message' => 'x'])->assertStatus(403);
        $this->as($this->admin, $read)->patchJson($this->api("/{$ticket->id}"), ['priority' => 'low'])->assertStatus(403);
        $this->as($this->admin, $read)->deleteJson($this->api("/{$ticket->id}"))->assertStatus(403);
        $this->as($this->admin, ['invoices.read'])->getJson($this->api())->assertStatus(403);
        $this->as($this->admin, $read)->getJson($this->api())->assertOk();
        $this->as($this->admin, $write)->postJson($this->api("/{$ticket->id}/close"))->assertOk();
        $this->assertContains('tickets.read', Abilities::staff());
    }

    public function test_the_admin_api_is_a_404_while_support_is_off_but_abilities_still_answer_first(): void
    {
        $ticket = $this->ticket();
        Settings::set('support.enabled', false);
        $this->as($this->admin)->getJson($this->api())->assertNotFound();
        $this->as($this->admin)->getJson($this->api("/{$ticket->id}"))->assertNotFound();
        $this->as($this->admin)->postJson($this->api(), [])->assertNotFound();
        $this->as($this->admin)->getJson('/api/v1/admin/ticket-departments')->assertNotFound();
        $this->as($this->admin)->getJson('/api/v1/admin/ticket-fields')->assertNotFound();
        $this->as($this->admin, ['invoices.read'])->getJson($this->api())->assertStatus(403);
        $this->flushHeaders()->getJson($this->api())->assertStatus(401);
    }
}
