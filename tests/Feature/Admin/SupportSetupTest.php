<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketField;
use App\Models\User;
use App\Services\Support\ImapPuller;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Settings::set('support.enabled', true);
    }

    private function payload(array $over = []): array
    {
        return $over + ['name' => 'Billing Desk', 'slug' => '', 'is_public' => 1, 'is_active' => 1, 'auto_reply' => 1, 'sort' => 2, 'email' => 'Billing@Example.test',
            'imap_enabled' => 0, 'imap_port' => 993, 'imap_encryption' => 'ssl', 'imap_validate_cert' => 1, 'imap_folder' => 'INBOX', 'imap_after' => 'seen'];
    }

    public function test_settings_section_saves(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.settings.index', 'support'))->assertOk()->assertSee('Enable support tickets')->assertSee('always refused', false);
        $this->actingAs($admin)->put(route('admin.settings.update', 'support'), ['s' => [
            'support__enabled' => '1', 'support__ticket_prefix' => 'HELP-', 'support__max_attachment_mb' => '4', 'support__max_attachments' => '2',
            'support__allowed_extensions' => 'png, pdf', 'support__notify_staff' => '0', 'support__notify_customer_on_close' => '1',
        ]])->assertRedirect(route('admin.settings.index', 'support'));
        $this->assertTrue(Settings::bool('support.enabled'));
        $this->assertSame('HELP-', Settings::get('support.ticket_prefix'));
        $this->assertEquals(4, Settings::get('support.max_attachment_mb'));
        $this->assertSame('png, pdf', Settings::get('support.allowed_extensions'));
        $this->assertFalse(Settings::bool('support.notify_staff', true));
        $this->actingAs(User::factory()->staff()->create())->put(route('admin.settings.update', 'support'), ['s' => ['support__enabled' => '0']])->assertForbidden();
    }

    public function test_department_crud_with_staff_and_imap_password_kept(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->staff()->create();
        $bob = User::factory()->staff()->create();

        $this->actingAs($admin)->post(route('admin.departments.store'), $this->payload(['staff' => [$alice->id], 'imap_enabled' => 1, 'imap_host' => 'imap.example.test', 'imap_username' => 'desk', 'imap_password' => 's3cret']))->assertSessionHasNoErrors();
        $d = Department::firstOrFail();
        $this->assertSame('billing-desk', $d->slug);
        $this->assertSame('billing@example.test', $d->email);
        $this->assertSame('s3cret', $d->imap_password);
        $this->assertSame([$alice->id], $d->staff()->pluck('users.id')->all());

        $this->actingAs($admin)->get(route('admin.departments.edit', $d))->assertOk()->assertSee('tickets:pipe')->assertDontSee('s3cret');
        $this->actingAs($admin)->put(route('admin.departments.update', $d), $this->payload(['name' => 'Accounts', 'slug' => 'billing-desk', 'is_public' => 0, 'staff' => [$bob->id], 'imap_enabled' => 1, 'imap_host' => 'imap.example.test', 'imap_username' => 'desk', 'imap_password' => '']))->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame('Accounts', $d->name);
        $this->assertFalse($d->is_public);
        $this->assertSame('s3cret', $d->imap_password);
        $this->assertSame([$bob->id], $d->staff()->pluck('users.id')->all());

        // validation: unique email, IMAP needs a host when enabled
        $this->actingAs($admin)->post(route('admin.departments.store'), $this->payload(['name' => 'Other']))->assertSessionHasErrors('email');
        $this->actingAs($admin)->post(route('admin.departments.store'), $this->payload(['name' => 'Third', 'email' => 'third@example.test', 'imap_enabled' => 1]))->assertSessionHasErrors('imap_host');
        $this->actingAs($admin)->get(route('admin.departments.index'))->assertOk()->assertSee('Accounts');
    }

    public function test_departments_are_admin_only(): void
    {
        $staff = User::factory()->staff()->create();
        $d = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->actingAs($staff)->get(route('admin.departments.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.departments.store'), $this->payload())->assertForbidden();
        $this->actingAs($staff)->delete(route('admin.departments.destroy', $d))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.ticket-fields.index'))->assertForbidden();
        $this->actingAs($staff)->post(route('admin.departments.test', $d))->assertForbidden();
    }

    public function test_a_department_with_tickets_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $d = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $empty = Department::create(['name' => 'Empty', 'slug' => 'empty']);
        $ticket = Ticket::create(['number' => 'TKT-1', 'department_id' => $d->id, 'requester_name' => 'A', 'requester_email' => 'a@example.test', 'subject' => 'S']);

        $this->actingAs($admin)->delete(route('admin.departments.destroy', $d))->assertSessionHas('error');
        $this->assertNotNull($d->fresh());
        $this->assertSame($d->id, $ticket->fresh()->department_id);
        $this->actingAs($admin)->delete(route('admin.departments.destroy', $empty))->assertRedirect(route('admin.departments.index'));
        $this->assertNull(Department::find($empty->id));
    }

    public function test_test_connection_shows_the_result(): void
    {
        $d = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $this->mock(ImapPuller::class, fn ($m) => $m->shouldReceive('test')->twice()->andReturn(['ok' => true, 'message' => 'Logged in, 3 messages.'], ['ok' => false, 'message' => 'Bad password']));

        $this->actingAs($this->admin())->post(route('admin.departments.test', $d))->assertRedirect(route('admin.departments.edit', $d))->assertSessionHas('status', 'Connection works: Logged in, 3 messages.');
        $this->actingAs($this->admin())->post(route('admin.departments.test', $d))->assertSessionHas('error', 'Connection failed: Bad password');
    }

    public function test_department_page_shows_last_check_and_error(): void
    {
        $d = Department::create(['name' => 'Sales', 'slug' => 'sales', 'imap_enabled' => true, 'imap_checked_at' => now()->subMinutes(5), 'imap_error' => 'Login refused']);
        $this->actingAs($this->admin())->get(route('admin.departments.edit', $d))->assertSee('Login refused')->assertSee('5 minutes ago');
    }

    public function test_ticket_field_crud(): void
    {
        $admin = $this->admin();
        $d = Department::create(['name' => 'Sales', 'slug' => 'sales']);

        $this->actingAs($admin)->post(route('admin.ticket-fields.store'), ['label' => 'Order reference', 'key' => '', 'type' => 'text', 'required' => 1, 'staff_only' => 0, 'is_active' => 1, 'sort' => 1])->assertSessionHasNoErrors();
        $field = TicketField::firstOrFail();
        $this->assertSame('order_reference', $field->key);
        $this->assertNull($field->department_id);
        $this->assertTrue($field->required);

        $this->actingAs($admin)->post(route('admin.ticket-fields.store'), ['label' => 'Dup', 'key' => 'order_reference', 'type' => 'text'])->assertSessionHasErrors('key');
        $this->actingAs($admin)->post(route('admin.ticket-fields.store'), ['label' => 'Same key, one department', 'key' => 'order_reference', 'type' => 'text', 'department_id' => $d->id])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.ticket-fields.store'), ['label' => 'Pick', 'type' => 'select', 'choices' => ''])->assertSessionHasErrors('choices');

        $this->actingAs($admin)->post(route('admin.ticket-fields.store'), ['label' => 'Plan', 'type' => 'select', 'choices' => "Basic\n Pro, Plus \n\nBasic", 'staff_only' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
        $plan = TicketField::where('key', 'plan')->firstOrFail();
        $this->assertSame(['Basic', 'Pro Plus'], $plan->options);
        $this->assertTrue($plan->staff_only);

        $this->actingAs($admin)->get(route('admin.ticket-fields.edit', $plan))->assertOk()->assertSee('Pro Plus');
        $this->actingAs($admin)->put(route('admin.ticket-fields.update', $field), ['label' => 'Order number', 'key' => 'order_reference', 'type' => 'text', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Order number', $field->fresh()->label);
        $this->assertFalse($field->fresh()->is_active);
        $this->actingAs($admin)->get(route('admin.ticket-fields.index'))->assertOk()->assertSee('Order number')->assertSee('All departments');
        $this->actingAs($admin)->delete(route('admin.ticket-fields.destroy', $plan))->assertRedirect(route('admin.ticket-fields.index'));
        $this->assertNull(TicketField::find($plan->id));
    }

    public function test_staff_form_assigns_departments(): void
    {
        $admin = $this->admin();
        $a = Department::create(['name' => 'Sales', 'slug' => 'sales']);
        $b = Department::create(['name' => 'Technical', 'slug' => 'technical']);
        $user = User::factory()->staff()->create();

        $this->actingAs($admin)->get(route('admin.staff.edit', $user))->assertOk()->assertSee('Staff in no department only see tickets assigned directly to them', false);
        $this->actingAs($admin)->put(route('admin.staff.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => 'staff', 'departments' => [$a->id, $b->id]])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $user->departments()->pluck('ticket_departments.id')->all());
        $this->actingAs($admin)->get(route('admin.staff.index'))->assertSee('Sales, Technical');

        $this->actingAs($admin)->put(route('admin.staff.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => 'staff', 'departments' => [$b->id]]);
        $this->assertSame([$b->id], $user->departments()->pluck('ticket_departments.id')->all());
        $this->actingAs($admin)->put(route('admin.staff.update', $user), ['name' => $user->name, 'email' => $user->email, 'role' => 'staff']);
        $this->assertSame([], $user->departments()->pluck('ticket_departments.id')->all());

        $this->actingAs($admin)->post(route('admin.staff.store'), ['name' => 'New', 'email' => 'new@example.test', 'role' => 'staff', 'password_mode' => 'set', 'password' => 'longenough1', 'departments' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertSame([$a->id], User::where('email', 'new@example.test')->firstOrFail()->departments()->pluck('ticket_departments.id')->all());
    }
}
