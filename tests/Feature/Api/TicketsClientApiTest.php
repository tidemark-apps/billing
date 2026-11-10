<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Abilities;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The customer side of the ticket API: /tickets and /ticket-departments. */
class TicketsClientApiTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private Department $sales;

    private Department $hidden;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Settings::set('support.enabled', true);
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->hidden = Department::create(['name' => 'Internal', 'slug' => 'internal', 'is_public' => false]);
        $this->user = $this->customer();
    }

    private function openFor(User $customer, array $over = []): Ticket
    {
        return app(TicketService::class)->open($over + ['department' => $this->sales, 'subject' => 'Help', 'body' => 'It broke', 'client' => $customer->client, 'user' => $customer, 'notify' => false]);
    }

    private function staffInSales(): User
    {
        $staff = User::factory()->staff()->create();
        $staff->departments()->sync([$this->sales->id]);

        return $staff;
    }

    public function test_departments_list_only_listed_ones_with_customer_fields(): void
    {
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'department_id' => $this->sales->id]);
        TicketField::create(['key' => 'internal_ref', 'label' => 'Ref', 'type' => 'text', 'staff_only' => true]);
        Department::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);

        $res = $this->as($this->user)->getJson('/api/v1/ticket-departments')->assertOk();
        $this->assertSame(['sales'], array_column($res->json('data'), 'slug'));
        $this->assertSame(['domain'], array_column($res->json('data.0.fields'), 'key'));
        $this->assertArrayNotHasKey('email', $res->json('data.0'));
        $this->assertStringNotContainsString('imap', $res->getContent());
        $this->assertStringNotContainsString('internal_ref', $res->getContent());
    }

    public function test_opening_a_ticket(): void
    {
        $service = $this->makeService($this->user->client);
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true]);

        $res = $this->as($this->user)->postJson('/api/v1/tickets', [
            'department_id' => $this->sales->id, 'subject' => 'Site down', 'message' => 'Since this morning', 'service' => $service->uuid, 'custom' => ['domain' => 'example.com'],
        ])->assertCreated()
            ->assertJsonPath('data.subject', 'Site down')->assertJsonPath('data.status', 'open')->assertJsonPath('data.service_uuid', $service->uuid)
            ->assertJsonPath('data.custom_fields.domain', 'example.com')->assertJsonPath('data.messages.0.kind', 'reply')->assertJsonPath('data.can_reply', true);
        $this->assertArrayNotHasKey('id', $res->json('data'));
        $this->assertArrayNotHasKey('requester_email', $res->json('data'));
        $ticket = Ticket::where('uuid', $res->json('data.uuid'))->first();
        $this->assertSame($this->user->client_id, $ticket->client_id);
        $this->assertSame($this->user->id, $ticket->user_id);
        $this->assertSame('api', $ticket->source);
    }

    public function test_opening_validates_department_service_and_custom_fields(): void
    {
        $other = $this->makeService(Client::factory()->create());
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text', 'required' => true]);
        TicketField::create(['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'options' => ['a', 'b']]);
        TicketField::create(['key' => 'secret_note', 'label' => 'Secret', 'type' => 'text', 'staff_only' => true]);
        $base = ['department_id' => $this->sales->id, 'subject' => 's', 'message' => 'm', 'custom' => ['domain' => 'x.com']];

        $this->as($this->user)->postJson('/api/v1/tickets', ['department_id' => $this->hidden->id] + $base)->assertStatus(422)->assertJsonPath('error.details', fn ($d) => isset($d['department_id']));
        $this->as($this->user)->postJson('/api/v1/tickets', ['department_id' => 9999] + $base)->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/tickets', ['service' => $other->uuid] + $base)->assertStatus(422)->assertJsonPath('error.details.service.0', 'That service was not found.');
        $this->as($this->user)->postJson('/api/v1/tickets', ['custom' => []] + $base)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->as($this->user)->postJson('/api/v1/tickets', ['custom' => ['domain' => 'x', 'plan' => 'zzz']] + $base)->assertStatus(422);
        // staff-only fields are unsettable and unknown keys are refused
        $this->as($this->user)->postJson('/api/v1/tickets', ['custom' => ['domain' => 'x', 'secret_note' => 'hack']] + $base)->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/tickets', ['custom' => ['domain' => 'x', 'nope' => '1']] + $base)->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/tickets', ['subject' => ''] + $base)->assertStatus(422);
        $this->assertSame(0, Ticket::count());
    }

    public function test_listing_and_showing_are_limited_to_the_clients_tickets(): void
    {
        $mine = $this->openFor($this->user);
        $closed = $this->openFor($this->user, ['subject' => 'Old']);
        app(TicketService::class)->close($closed);
        $colleague = User::factory()->create(['client_id' => $this->user->client_id, 'role' => 'customer']);
        $theirs = $this->openFor($this->customer());

        $this->assertEqualsCanonicalizing([$mine->uuid, $closed->uuid], array_column($this->as($this->user)->getJson('/api/v1/tickets')->assertOk()->json('data'), 'uuid'));
        $this->assertSame([$closed->uuid], array_column($this->as($this->user)->getJson('/api/v1/tickets?status=closed')->json('data'), 'uuid'));
        // a colleague on the same client sees the client's tickets
        $this->assertCount(2, $this->as($colleague)->getJson('/api/v1/tickets')->json('data'));

        $this->as($this->user)->getJson("/api/v1/tickets/{$mine->uuid}")->assertOk()->assertJsonPath('data.number', $mine->number);
        $this->as($this->user)->getJson("/api/v1/tickets/{$theirs->uuid}")->assertNotFound();
        $this->as($this->user)->postJson("/api/v1/tickets/{$theirs->uuid}/replies", ['message' => 'hi'])->assertNotFound();
        $this->as($this->user)->postJson("/api/v1/tickets/{$theirs->uuid}/close")->assertNotFound();
        $this->as($this->user)->getJson('/api/v1/tickets/'.$theirs->id)->assertNotFound();
        $this->assertSame('open', $theirs->refresh()->status->value);
    }

    public function test_reply_and_close(): void
    {
        $ticket = $this->openFor($this->user);
        app(TicketService::class)->reply($ticket, 'Looking', $this->staffInSales(), true, [], ['source' => 'api']);

        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/replies", ['message' => 'Thanks, still broken'])
            ->assertCreated()->assertJsonPath('data.body', 'Thanks, still broken')->assertJsonPath('data.author_type', 'customer');
        $this->assertSame('customer_reply', $ticket->refresh()->status->value);
        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/replies", [])->assertStatus(422);

        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertNotNull($ticket->refresh()->closed_at);
        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/replies", ['message' => 'Reopen please'])->assertCreated();
        $this->assertSame('customer_reply', $ticket->refresh()->status->value);
    }

    public function test_a_locked_ticket_cannot_be_replied_to_or_closed(): void
    {
        $ticket = $this->openFor($this->user);
        app(TicketService::class)->lock($ticket, User::factory()->admin()->create());

        $this->as($this->user)->getJson("/api/v1/tickets/{$ticket->uuid}")->assertJsonPath('data.can_reply', false);
        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/replies", ['message' => 'hello'])->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/close")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertSame('open', $ticket->refresh()->status->value);
    }

    public function test_customers_never_see_notes_events_or_staff_only_fields(): void
    {
        TicketField::create(['key' => 'domain', 'label' => 'Domain', 'type' => 'text']);
        TicketField::create(['key' => 'cost_centre', 'label' => 'Cost centre', 'type' => 'text', 'staff_only' => true]);
        $ticket = $this->openFor($this->user, ['custom' => ['domain' => 'x.com']]);
        $staff = $this->staffInSales();
        $svc = app(TicketService::class);
        $svc->reply($ticket, 'Public answer', $staff, true);
        $svc->note($ticket, $staff, 'SECRET internal note', [['name' => 'note.txt', 'content' => 'NOTE FILE']]);
        $svc->assign($ticket, $staff, $staff);
        $ticket->update(['custom_fields' => ['domain' => 'x.com', 'cost_centre' => 'CC-99']]);

        $res = $this->as($this->user)->getJson("/api/v1/tickets/{$ticket->uuid}")->assertOk();
        $this->assertSame(['reply', 'reply'], array_column($res->json('data.messages'), 'kind'));
        $this->assertSame(['x.com'], array_values((array) $res->json('data.custom_fields')));
        foreach (['SECRET internal note', 'NOTE FILE', 'note.txt', 'CC-99', 'cost_centre', 'Assigned to', $staff->email, 'assigned_to'] as $leak) {
            $this->assertStringNotContainsString($leak, $res->getContent(), $leak);
        }
        $this->assertStringNotContainsString('SECRET', $this->as($this->user)->getJson('/api/v1/tickets')->getContent());
        // the note's file cannot be fetched with a guessed uuid either
        $noteFile = Ticket::find($ticket->id)->attachments()->first();
        $this->as($this->user)->get("/api/v1/tickets/{$ticket->uuid}/attachments/{$noteFile->uuid}")->assertNotFound();
    }

    public function test_attachments_via_multipart_and_json(): void
    {
        $ticket = $this->openFor($this->user);

        $res = $this->as($this->user)->post("/api/v1/tickets/{$ticket->uuid}/replies", ['message' => 'See attached', 'files' => [UploadedFile::fake()->createWithContent('log.txt', 'hello log')]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.attachments.0.filename', 'log.txt')->assertJsonPath('data.attachments.0.size', 9);
        $url = $res->json('data.attachments.0.download_url');
        $this->assertStringContainsString("/tickets/{$ticket->uuid}/attachments/", $url);
        $this->assertArrayNotHasKey('path', $res->json('data.attachments.0'));

        $json = $this->as($this->user)->postJson('/api/v1/tickets', [
            'department_id' => $this->sales->id, 'subject' => 'With file', 'message' => 'm', 'attachments' => [['filename' => 'a.txt', 'content_base64' => base64_encode('from json')]],
        ])->assertCreated()->assertJsonPath('data.messages.0.attachments.0.filename', 'a.txt');
        $uuid = $json->json('data.messages.0.attachments.0.uuid');

        $download = $this->as($this->user)->get("/api/v1/tickets/{$json->json('data.uuid')}/attachments/$uuid")->assertOk();
        $this->assertSame('from json', $download->streamedContent());
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('a.txt', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', $download->headers->get('Content-Security-Policy'));
        $download->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        // someone else's attachment uuid under my ticket is a 404
        $this->as($this->user)->get("/api/v1/tickets/{$ticket->uuid}/attachments/$uuid")->assertNotFound();
        $this->as($this->customer())->get("/api/v1/tickets/{$json->json('data.uuid')}/attachments/$uuid")->assertNotFound();
    }

    public function test_dangerous_or_broken_attachments_are_refused(): void
    {
        $base = ['department_id' => $this->sales->id, 'subject' => 's', 'message' => 'm'];
        foreach ([['x.php', '<?php echo 1;'], ['x.php.jpg', 'abc'], ['page.html', '<html>'], ['pic.svg', '<svg onload=alert(1)>'], ['run.exe', 'MZ'], ['.PHP5', '<?php']] as [$name, $body]) {
            $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => [['filename' => $name, 'content_base64' => base64_encode($body)]]])
                ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }
        // dangerous content under an innocent name
        $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => [['filename' => 'notes.txt', 'content_base64' => base64_encode('<!DOCTYPE html><html><script>alert(1)</script></html>')]]])->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => [['filename' => 'empty.txt', 'content_base64' => '']]])->assertStatus(422);
        $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => [['filename' => 'a.txt', 'content_base64' => '***not base64***']]])->assertStatus(422)->assertJsonPath('error.details', fn ($d) => isset($d['attachments.0.content_base64']));
        $this->as($this->user)->post('/api/v1/tickets', $base + ['files' => [UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET[0]);')]], ['Accept' => 'application/json'])->assertStatus(422);

        Settings::set('support.max_attachments', 1);
        $two = [['filename' => 'a.txt', 'content_base64' => base64_encode('a')], ['filename' => 'b.txt', 'content_base64' => base64_encode('b')]];
        $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => $two])->assertStatus(422);
        Settings::set('support.max_attachments', 5);
        Settings::set('support.max_attachment_mb', 1);
        $this->as($this->user)->postJson('/api/v1/tickets', $base + ['attachments' => [['filename' => 'big.txt', 'content_base64' => base64_encode(str_repeat('a', 1024 * 1024 + 1))]]])->assertStatus(422);

        $this->assertSame(0, Ticket::count()); // nothing was created or stored
        $this->assertSame([], Storage::disk('tickets')->allFiles());
    }

    public function test_abilities_and_audience(): void
    {
        $ticket = $this->openFor($this->user);
        $this->flushHeaders()->getJson('/api/v1/tickets')->assertStatus(401);
        $this->as($this->staffUser())->getJson('/api/v1/tickets')->assertStatus(403); // staff use /admin/tickets

        $this->as($this->user, ['tickets.write'])->getJson('/api/v1/tickets')->assertStatus(403);
        $this->as($this->user, ['tickets.write'])->getJson("/api/v1/tickets/{$ticket->uuid}")->assertStatus(403);
        $this->as($this->user, ['tickets.write'])->getJson('/api/v1/ticket-departments')->assertStatus(403);
        $this->as($this->user, ['tickets.read'])->postJson('/api/v1/tickets', ['department_id' => $this->sales->id, 'subject' => 's', 'message' => 'm'])->assertStatus(403);
        $this->as($this->user, ['tickets.read'])->postJson("/api/v1/tickets/{$ticket->uuid}/replies", ['message' => 'm'])->assertStatus(403);
        $this->as($this->user, ['tickets.read'])->postJson("/api/v1/tickets/{$ticket->uuid}/close")->assertStatus(403);
        $this->as($this->user, ['tickets.read'])->getJson("/api/v1/tickets/{$ticket->uuid}")->assertOk();
        $this->as($this->user, ['invoices.read'])->getJson('/api/v1/tickets')->assertStatus(403);
        $this->assertTrue(in_array('tickets.read', Abilities::customer(), true) && in_array('tickets.write', Abilities::customer(), true));
    }

    public function test_the_api_is_a_404_while_support_is_off(): void
    {
        $ticket = $this->openFor($this->user);
        Settings::set('support.enabled', false);

        $this->as($this->user)->getJson('/api/v1/tickets')->assertNotFound();
        $this->as($this->user)->getJson("/api/v1/tickets/{$ticket->uuid}")->assertNotFound();
        $this->as($this->user)->getJson('/api/v1/ticket-departments')->assertNotFound();
        $this->as($this->user)->postJson('/api/v1/tickets', ['department_id' => $this->sales->id, 'subject' => 's', 'message' => 'm'])->assertNotFound();
        $this->as($this->user)->postJson("/api/v1/tickets/{$ticket->uuid}/close")->assertNotFound();
        $this->flushHeaders()->getJson('/api/v1/tickets')->assertStatus(401); // authentication still answers first
    }
}
