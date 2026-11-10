<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /admin/ticket-departments, /admin/ticket-fields and department_ids on staff users. The IMAP password must never leak. */
class TicketDepartmentsApiTest extends TestCase
{
    use ApiTestHelpers, RefreshDatabase;

    private const SECRET = 'imap-S3CRET-pass';

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('support.enabled', true);
    }

    private function dept(array $over = []): Department
    {
        return Department::create($over + ['name' => 'Sales', 'slug' => 'sales']);
    }

    public function test_create_update_show_and_delete_a_department(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();

        $res = $this->as($admin)->postJson('/api/v1/admin/ticket-departments', [
            'name' => 'Technical Support', 'email' => 'Help@Example.com', 'is_public' => false, 'staff_ids' => [$staff->id, $admin->id],
            'imap_enabled' => true, 'imap_host' => 'imap.example.com', 'imap_username' => 'help@example.com', 'imap_password' => self::SECRET, 'imap_port' => 143, 'imap_encryption' => 'starttls',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'technical-support')->assertJsonPath('data.email', 'help@example.com')->assertJsonPath('data.is_public', false)
            ->assertJsonPath('data.has_imap_password', true)->assertJsonPath('data.imap_port', 143)->assertJsonPath('data.imap_encryption', 'starttls')->assertJsonPath('data.imap_folder', 'INBOX');
        $this->assertEqualsCanonicalizing([$staff->id, $admin->id], $res->json('data.staff_ids'));
        $id = $res->json('data.id');
        $this->assertSame(self::SECRET, Department::find($id)->imap_password); // stored (encrypted), just never returned
        $this->assertNotSame(self::SECRET, Department::find($id)->getRawOriginal('imap_password'));

        // partial update: the password is kept unless a new one is sent
        $this->as($admin)->patchJson("/api/v1/admin/ticket-departments/$id", ['name' => 'Tech', 'staff_ids' => [$staff->id]])->assertOk()
            ->assertJsonPath('data.name', 'Tech')->assertJsonPath('data.has_imap_password', true)->assertJsonPath('data.staff_ids', [$staff->id])->assertJsonPath('data.slug', 'technical-support');
        $this->assertSame(self::SECRET, Department::find($id)->imap_password);
        $this->as($admin)->patchJson("/api/v1/admin/ticket-departments/$id", ['imap_password' => ''])->assertOk()->assertJsonPath('data.has_imap_password', true);
        $this->as($admin)->patchJson("/api/v1/admin/ticket-departments/$id", ['imap_password' => 'new-one'])->assertOk();
        $this->assertSame('new-one', Department::find($id)->imap_password);
        $this->as($admin)->patchJson("/api/v1/admin/ticket-departments/$id", ['imap_enabled' => false, 'clear_imap_password' => true])->assertOk()->assertJsonPath('data.has_imap_password', false);
        $this->assertNull(Department::find($id)->imap_password);
        $this->assertDatabaseHas('activity_logs', ['action' => 'department.updated', 'subject_id' => $id]);

        $this->as($admin)->getJson("/api/v1/admin/ticket-departments/$id")->assertOk()->assertJsonPath('data.id', $id);
        $this->as($admin)->deleteJson("/api/v1/admin/ticket-departments/$id")->assertNoContent();
        $this->assertNull(Department::find($id));
    }

    public function test_validation_and_delete_guard(): void
    {
        $admin = $this->admin();
        $d = $this->dept();
        $this->dept(['name' => 'Other', 'slug' => 'other', 'email' => 'taken@example.com']);

        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', [])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'slug' => 'sales'])->assertStatus(422); // slug taken
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'slug' => 'Bad Slug'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'email' => 'taken@example.com'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'email' => 'nope'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'imap_encryption' => 'rot13'])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'imap_port' => 70000])->assertStatus(422);
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'imap_enabled' => true])->assertStatus(422); // needs host and username
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'X', 'staff_ids' => [$this->customer()->id]])->assertStatus(422); // customers cannot staff a department
        $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'Sales'])->assertCreated()->assertJsonPath('data.slug', 'sales-2');

        app(TicketService::class)->open(['department' => $d, 'subject' => 's', 'body' => 'b', 'client' => ($c = $this->customer())->client, 'user' => $c, 'notify' => false]);
        $this->as($admin)->deleteJson("/api/v1/admin/ticket-departments/{$d->id}")->assertStatus(422)->assertJsonPath('error.code', 'domain_error');
        $this->assertNotNull(Department::find($d->id));
        $this->assertSame(1, Ticket::count());
    }

    public function test_imap_password_never_appears_in_any_response(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->staff()->create();
        $d = $this->dept(['imap_enabled' => true, 'imap_host' => 'imap.example.com', 'imap_username' => 'u', 'imap_password' => self::SECRET, 'imap_error' => 'Login failed']);
        $d->staff()->sync([$staff->id]);
        $ticket = app(TicketService::class)->open(['department' => $d, 'subject' => 's', 'body' => 'b', 'client' => ($c = $this->customer())->client, 'user' => $c, 'notify' => false]);

        $responses = [
            $this->as($admin)->getJson('/api/v1/admin/ticket-departments'),
            $this->as($admin)->getJson("/api/v1/admin/ticket-departments/{$d->id}"),
            $this->as($admin)->postJson('/api/v1/admin/ticket-departments', ['name' => 'N', 'imap_password' => self::SECRET]),
            $this->as($admin)->patchJson("/api/v1/admin/ticket-departments/{$d->id}", ['imap_password' => self::SECRET.'2']),
            $this->as($staff)->getJson('/api/v1/admin/ticket-departments'),
            $this->as($admin)->getJson("/api/v1/admin/tickets/{$ticket->id}"),
            $this->as($admin)->getJson('/api/v1/admin/tickets'),
            $this->as($c)->getJson('/api/v1/ticket-departments'),
            $this->as($c)->getJson("/api/v1/tickets/{$ticket->uuid}"),
            $this->as($admin)->getJson('/api/v1/admin/staff-users'),
        ];
        foreach ($responses as $i => $res) {
            $this->assertStringNotContainsString('S3CRET', $res->getContent(), "response #$i");
            $this->assertStringNotContainsString('"imap_password"', $res->getContent(), "response #$i");
            $this->assertStringNotContainsString(self::SECRET, $res->getContent(), "response #$i");
        }
        $this->assertTrue($responses[0]->json('data.0.has_imap_password'));
    }

    public function test_staff_tokens_get_a_redacted_view_and_cannot_write(): void
    {
        $staff = User::factory()->staff()->create();
        $d = $this->dept(['email' => 'sales@example.com', 'imap_enabled' => true, 'imap_host' => 'imap.example.com', 'imap_username' => 'secret-user', 'imap_password' => self::SECRET]);
        $d->staff()->sync([$staff->id]);

        $res = $this->as($staff)->getJson('/api/v1/admin/ticket-departments')->assertOk();
        $this->assertSame('sales@example.com', $res->json('data.0.email'));
        foreach (['imap_host', 'imap_username', 'has_imap_password', 'staff_ids', 'imap_error'] as $key) {
            $this->assertArrayNotHasKey($key, $res->json('data.0'), $key);
        }
        $this->assertStringNotContainsString('secret-user', $res->getContent());

        $this->as($staff)->postJson('/api/v1/admin/ticket-departments', ['name' => 'N'])->assertStatus(403);
        $this->as($staff)->patchJson("/api/v1/admin/ticket-departments/{$d->id}", ['name' => 'N'])->assertStatus(403);
        $this->as($staff)->deleteJson("/api/v1/admin/ticket-departments/{$d->id}")->assertStatus(403);
        $this->as($staff)->postJson('/api/v1/admin/ticket-fields', ['key' => 'k', 'label' => 'K', 'type' => 'text'])->assertStatus(403);
        $this->assertSame('Sales', $d->refresh()->name);
        // abilities
        $this->as($this->admin(), ['tickets.read', 'tickets.write'])->getJson('/api/v1/admin/ticket-departments')->assertStatus(403);
        $this->as($this->admin(), ['support.read'])->postJson('/api/v1/admin/ticket-departments', ['name' => 'N'])->assertStatus(403);
        $this->as($this->admin(), ['support.write'])->getJson('/api/v1/admin/ticket-departments')->assertStatus(403);
        $this->as($this->admin(), ['support.write'])->postJson('/api/v1/admin/ticket-departments', ['name' => 'N'])->assertCreated();
        $this->as($this->customer())->getJson('/api/v1/admin/ticket-departments')->assertStatus(403);
    }

    public function test_ticket_fields_crud(): void
    {
        $admin = $this->admin();
        $d = $this->dept();

        $res = $this->as($admin)->postJson('/api/v1/admin/ticket-fields', [
            'key' => 'priority_reason', 'label' => 'Reason', 'type' => 'select', 'options' => ['outage', 'billing', 'outage'], 'required' => true, 'staff_only' => true, 'department_id' => $d->id, 'help' => 'Pick one',
        ])->assertCreated()->assertJsonPath('data.options', ['outage', 'billing'])->assertJsonPath('data.staff_only', true)->assertJsonPath('data.department_id', $d->id);
        $id = $res->json('data.id');

        $this->as($admin)->patchJson("/api/v1/admin/ticket-fields/$id", ['label' => 'Why', 'required' => false, 'department_id' => null])->assertOk()
            ->assertJsonPath('data.label', 'Why')->assertJsonPath('data.required', false)->assertJsonPath('data.department_id', null)->assertJsonPath('data.options', ['outage', 'billing']);
        $this->as($admin)->patchJson("/api/v1/admin/ticket-fields/$id", ['key' => 'renamed'])->assertStatus(422); // the key is permanent
        $this->as($admin)->getJson("/api/v1/admin/ticket-fields/$id")->assertOk()->assertJsonPath('data.key', 'priority_reason');
        $this->assertCount(1, $this->as($admin)->getJson('/api/v1/admin/ticket-fields?staff_only=1')->json('data'));
        $this->assertCount(0, $this->as($admin)->getJson('/api/v1/admin/ticket-fields?staff_only=0')->json('data'));
        $this->assertCount(0, $this->as($admin)->getJson('/api/v1/admin/ticket-fields?department_id='.$d->id)->json('data'));

        foreach ([
            ['key' => 'priority_reason', 'label' => 'dup', 'type' => 'text'], ['key' => 'Bad Key', 'label' => 'x', 'type' => 'text'], ['key' => 'k', 'label' => 'x', 'type' => 'wat'],
            ['key' => 'k', 'label' => 'x', 'type' => 'select'], ['key' => 'k', 'label' => 'x', 'type' => 'select', 'options' => ['a,b']], ['key' => 'k', 'label' => 'x', 'type' => 'text', 'department_id' => 999],
            ['label' => 'x', 'type' => 'text'],
        ] as $bad) {
            $this->as($admin)->postJson('/api/v1/admin/ticket-fields', $bad)->assertStatus(422);
        }

        $this->as($admin)->deleteJson("/api/v1/admin/ticket-fields/$id")->assertNoContent();
        $this->assertNull(TicketField::find($id));
        $this->as($admin)->getJson("/api/v1/admin/ticket-fields/$id")->assertNotFound();
    }

    public function test_staff_users_expose_and_accept_department_ids(): void
    {
        $admin = $this->admin();
        $a = $this->dept();
        $b = $this->dept(['name' => 'Tech', 'slug' => 'tech']);

        $res = $this->as($admin)->postJson('/api/v1/admin/staff-users', ['name' => 'Sam', 'email' => 'sam@example.com', 'role' => 'staff', 'password' => 'a-good-password', 'department_ids' => [$a->id, $b->id]])
            ->assertCreated();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $res->json('data.department_ids'));
        $sam = User::where('email', 'sam@example.com')->first();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $sam->departments()->pluck('ticket_departments.id')->all());

        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['department_ids' => [$b->id]])->assertOk()->assertJsonPath('data.department_ids', [$b->id]);
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['name' => 'Samuel'])->assertOk()->assertJsonPath('data.department_ids', [$b->id]); // untouched
        $this->as($admin)->getJson("/api/v1/admin/staff-users/{$sam->id}")->assertJsonPath('data.department_ids', [$b->id]);
        $this->assertSame([$b->id], collect($this->as($admin)->getJson('/api/v1/admin/staff-users')->json('data'))->firstWhere('id', $sam->id)['department_ids']);
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['department_ids' => []])->assertOk()->assertJsonPath('data.department_ids', []);
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['department_ids' => [9999]])->assertStatus(422);

        // access follows: Sam sees a department's tickets once added
        $c = $this->customer();
        $t = app(TicketService::class)->open(['department' => $a, 'subject' => 's', 'body' => 'b', 'client' => $c->client, 'user' => $c, 'notify' => false]);
        $this->as($sam)->getJson("/api/v1/admin/tickets/{$t->id}")->assertNotFound();
        $this->as($admin)->patchJson("/api/v1/admin/staff-users/{$sam->id}", ['department_ids' => [$a->id]])->assertOk();
        $this->as($sam)->getJson("/api/v1/admin/tickets/{$t->id}")->assertOk();
        // and a department's staff_ids is the same relation seen from the other side
        $this->assertSame([$sam->id], $this->as($admin)->getJson("/api/v1/admin/ticket-departments/{$a->id}")->json('data.staff_ids'));
    }
}
