<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\Client;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\InboundEmail;
use App\Services\TicketService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Mail::fake();
        $this->sales = Department::create(['name' => 'Sales', 'slug' => 'sales', 'email' => 'sales@example.test']);
    }

    private function mail(string $fixture): string
    {
        return file_get_contents(base_path('tests/Fixtures/mail/'.$fixture.'.eml'));
    }

    private function process(string $fixture, ?Department $mailbox = null): array
    {
        return app(InboundEmail::class)->process($this->mail($fixture), $mailbox);
    }

    /** An existing ticket raised by jane@customer.test, whose first message has a known Message-ID. */
    private function existing(array $over = []): Ticket
    {
        $ticket = app(TicketService::class)->open([
            'department' => $this->sales, 'subject' => 'Help', 'body' => 'It broke', 'name' => 'Jane', 'email' => 'jane@customer.test', 'notify' => false,
        ]);
        $ticket->messages()->first()->update(['message_id' => 'orig-1@example.test']);
        $ticket->update($over);

        return $ticket->refresh();
    }

    public function test_plain_mail_opens_a_ticket(): void
    {
        $result = $this->process('new_plain');

        $this->assertSame('created', $result['status']);
        $ticket = $result['ticket'];
        $this->assertSame($this->sales->id, $ticket->department_id);
        $this->assertSame('Can you help with my invoice?', $ticket->subject);
        $this->assertSame('jane@customer.test', $ticket->requester_email);
        $this->assertSame('Jane Customer', $ticket->requester_name);
        $this->assertSame('email', $ticket->source);
        $this->assertNull($ticket->client_id);
        $message = $ticket->messages()->first();
        $this->assertStringContainsString('My invoice looks wrong', $message->body);
        $this->assertSame('new-plain-1@customer.test', $message->message_id);
        $this->assertSame('email', $message->source);
    }

    public function test_html_only_mail_is_converted_to_text(): void
    {
        $ticket = $this->process('html_only')['ticket'];

        $this->assertSame('Layout problem', $ticket->subject); // Fwd: Re: stripped
        $body = $ticket->messages()->first()->body;
        $this->assertStringContainsString("Hello & welcome,\nLine one\nLine two <ok>", $body);
        $this->assertStringContainsString('Bye', $body);
        $this->assertStringNotContainsString('alert', $body);
        $this->assertStringNotContainsString('color: red', $body);
        $this->assertStringNotContainsString('<p>', $body);
    }

    public function test_reply_is_threaded_by_in_reply_to_and_quotes_are_stripped(): void
    {
        $ticket = $this->existing();

        $result = $this->process('reply_in_reply_to');

        $this->assertSame('replied', $result['status']);
        $this->assertTrue($result['ticket']->is($ticket));
        $this->assertSame(1, Ticket::count());
        $this->assertSame(TicketStatus::CustomerReply, $ticket->refresh()->status);
        $last = $ticket->messages()->where('kind', 'reply')->reorder('id', 'desc')->first();
        $this->assertSame('Here is the extra detail you asked for.', $last->body);
        $this->assertSame('reply-irt-1@customer.test', $last->message_id);
        $this->assertSame('email', $last->source);
        $this->assertSame('customer', $last->author_type);
    }

    public function test_reply_is_threaded_by_our_own_outgoing_message_id(): void
    {
        $ticket = $this->existing();
        $raw = str_replace('<orig-1@example.test>', '<tkt-'.$ticket->uuid.'-1@billing.test>', $this->mail('reply_in_reply_to'));

        $this->assertSame('replied', app(InboundEmail::class)->process($raw)['status']);
        $this->assertSame(1, Ticket::count());
    }

    public function test_reply_is_threaded_by_subject_tag_from_the_requester_and_the_marker_cuts_the_quote(): void
    {
        $ticket = $this->existing();

        $result = $this->process('reply_subject_tag');

        $this->assertSame('replied', $result['status']);
        $this->assertSame('Still broken, please advise.', $ticket->messages()->where('kind', 'reply')->reorder('id', 'desc')->first()->body);
    }

    public function test_subject_tag_from_someone_else_cannot_inject_a_reply(): void
    {
        $ticket = $this->existing();

        $result = $this->process('spoof_subject_tag');

        $this->assertSame('created', $result['status']);
        $this->assertFalse($result['ticket']->is($ticket));
        $this->assertSame('mallory@evil.test', $result['ticket']->requester_email);
        $this->assertSame('Help', $result['ticket']->subject);
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_a_user_of_the_tickets_client_may_reply(): void
    {
        $client = Client::factory()->create();
        $colleague = User::factory()->create(['role' => 'customer', 'client_id' => $client->id, 'email' => 'colleague@customer.test']);
        $ticket = $this->existing(['client_id' => $client->id]);
        $raw = str_replace('From: Jane Customer <jane@customer.test>', 'From: Col <Colleague@Customer.test>', $this->mail('reply_subject_tag'));

        $result = app(InboundEmail::class)->process($raw);

        $this->assertSame('replied', $result['status']);
        $this->assertSame($colleague->id, $ticket->messages()->where('kind', 'reply')->reorder('id', 'desc')->first()->user_id);
    }

    public function test_staff_addresses_are_not_special_on_email(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'email' => 'agent@example.test']);
        $ticket = $this->existing();
        $raw = str_replace('From: Jane Customer <jane@customer.test>', 'From: Agent <agent@example.test>', $this->mail('reply_subject_tag'));

        $result = app(InboundEmail::class)->process($raw);

        $this->assertSame('created', $result['status']);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertNull($result['ticket']->user_id);
        $this->assertNotNull($staff);
    }

    public function test_locked_ticket_ignores_the_reply(): void
    {
        $ticket = $this->existing(['is_locked' => true]);

        $result = $this->process('reply_subject_tag');

        $this->assertSame('ignored', $result['status']);
        $this->assertSame('locked', $result['reason']);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_closed_ticket_is_reopened_by_a_valid_reply(): void
    {
        $ticket = $this->existing();
        app(TicketService::class)->close($ticket);

        $this->assertSame('replied', $this->process('reply_subject_tag')['status']);

        $ticket->refresh();
        $this->assertSame(TicketStatus::CustomerReply, $ticket->status);
        $this->assertNull($ticket->closed_at);
    }

    public function test_auto_replies_are_ignored(): void
    {
        $result = $this->process('auto_reply');

        $this->assertSame('ignored', $result['status']);
        $this->assertSame('auto_submitted', $result['reason']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_bulk_noreply_and_own_address_senders_are_ignored(): void
    {
        $base = $this->mail('new_plain');
        $cases = [
            'Precedence: bulk' => str_replace('Message-ID:', "Precedence: bulk\nMessage-ID:", $base),
            'X-Autoreply' => str_replace('Message-ID:', "X-Autoreply: yes\nMessage-ID:", $base),
            'mailer-daemon' => str_replace('"Jane Customer" <Jane@Customer.test>', 'Mail Delivery <MAILER-DAEMON@customer.test>', $base),
            'no-reply' => str_replace('"Jane Customer" <Jane@Customer.test>', 'x <no-reply@customer.test>', $base),
            'own address' => str_replace('"Jane Customer" <Jane@Customer.test>', 'Us <Sales@Example.test>', $base),
        ];
        foreach ($cases as $label => $raw) {
            $this->assertSame('ignored', app(InboundEmail::class)->process($raw)['status'], $label);
        }
        $this->assertSame(0, Ticket::count());
    }

    public function test_auto_submitted_no_is_not_auto_mail(): void
    {
        $raw = str_replace('Message-ID:', "Auto-Submitted: no\nMessage-ID:", $this->mail('new_plain'));

        $this->assertSame('created', app(InboundEmail::class)->process($raw)['status']);
    }

    public function test_the_same_message_id_is_only_processed_once(): void
    {
        $this->assertSame('created', $this->process('new_plain')['status']);

        $again = $this->process('new_plain');

        $this->assertSame('duplicate', $again['status']);
        $this->assertSame(1, Ticket::count());
    }

    public function test_unknown_recipient_is_rejected_and_inactive_departments_do_not_receive(): void
    {
        $this->assertSame('no_department', $this->process('unknown_recipient')['status']);

        $this->sales->update(['is_active' => false]);
        $this->assertSame('no_department', $this->process('new_plain')['status']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_recipient_matching_is_case_insensitive_and_uses_delivery_headers(): void
    {
        $raw = str_replace(['Delivered-To: sales@example.test', 'To: Sales <sales@example.test>'], ['Delivered-To: SALES@Example.test', 'To: undisclosed-recipients:;'], $this->mail('new_plain'));

        $this->assertSame('created', app(InboundEmail::class)->process($raw)['status']);
    }

    public function test_a_given_mailbox_decides_the_department_whatever_the_headers_say(): void
    {
        $tech = Department::create(['name' => 'Tech', 'slug' => 'tech', 'email' => 'tech@example.test']);

        $ticket = $this->process('unknown_recipient', $tech)['ticket'];

        $this->assertSame($tech->id, $ticket->department_id);
    }

    public function test_client_is_matched_by_a_customer_users_email(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create(['role' => 'customer', 'client_id' => $client->id, 'email' => 'jane@customer.test']);

        $ticket = $this->process('new_plain')['ticket'];

        $this->assertSame($client->id, $ticket->client_id);
        $this->assertSame($user->id, $ticket->user_id);
    }

    public function test_unknown_senders_can_be_rejected_by_setting(): void
    {
        Settings::set('support.reject_unknown_senders', true);

        $this->assertSame('rejected', $this->process('new_plain')['status']);

        $client = Client::factory()->create();
        User::factory()->create(['role' => 'customer', 'client_id' => $client->id, 'email' => 'jane@customer.test']);
        $this->assertSame('created', $this->process('new_plain')['status']);
    }

    public function test_good_attachment_is_stored_and_bad_one_is_dropped_with_a_note(): void
    {
        $result = $this->process('attachments');

        $this->assertSame('created', $result['status']);
        $ticket = $result['ticket'];
        $this->assertSame(1, $ticket->attachments()->count());
        $attachment = $ticket->attachments()->first();
        $this->assertSame('notes.txt', $attachment->original_name);
        Storage::disk('tickets')->assertExists($attachment->path);
        $body = $ticket->messages()->first()->body;
        $this->assertStringContainsString('See attached.', $body);
        $this->assertStringContainsString("[Attachment 'evil.exe' was not accepted:", $body);
    }

    public function test_too_many_attachments_are_dropped_not_fatal(): void
    {
        Settings::set('support.max_attachments', 1);
        $raw = str_replace("--BOUNDARY--\n", "--BOUNDARY\nContent-Type: text/plain; name=\"b.txt\"\nContent-Disposition: attachment; filename=\"b.txt\"\n\nsecond\n--BOUNDARY--\n", $this->mail('attachments'));
        $raw = preg_replace('/--BOUNDARY\nContent-Type: application\/octet-stream.*?(?=--BOUNDARY)/s', '', $raw);

        $ticket = app(InboundEmail::class)->process($raw)['ticket'];

        $this->assertSame(1, $ticket->attachments()->count());
        $this->assertStringContainsString("Attachment 'b.txt' was not accepted: too many", $ticket->messages()->first()->body);
    }

    public function test_empty_subject_and_control_characters_and_huge_bodies_are_handled(): void
    {
        $raw = "From: a@b.test\nTo: sales@example.test\nSubject: Re: \nMessage-ID: <x1@b.test>\nContent-Type: text/plain; charset=UTF-8\n\nhi\x00 there\x07\n".str_repeat('x', 150000);

        $ticket = app(InboundEmail::class)->process($raw)['ticket'];

        $this->assertSame('(no subject)', $ticket->subject);
        $body = $ticket->messages()->first()->body;
        $this->assertStringStartsWith("hi there\n", $body);
        $this->assertSame(100000, mb_strlen($body));
    }

    public function test_quoted_html_reply_text_is_dropped_for_replies_only(): void
    {
        $this->existing();
        $html = "From: Jane <jane@customer.test>\nTo: sales@example.test\nSubject: Re: [TKT-00001] Help\nMessage-ID: <q1@customer.test>\nContent-Type: text/html; charset=UTF-8\n\n<div>New info</div><blockquote>old quoted</blockquote>";

        $reply = app(InboundEmail::class)->process($html);

        $this->assertSame('New info', $reply['ticket']->messages()->where('kind', 'reply')->reorder('id', 'desc')->first()->body);
    }
}
