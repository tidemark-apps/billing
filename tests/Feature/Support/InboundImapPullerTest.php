<?php

namespace Tests\Feature\Support;

use App\Models\Department;
use App\Models\Ticket;
use App\Services\Support\ImapPuller;
use App\Services\Support\InboundEmail;
use App\Services\Support\Mailbox;
use App\Services\Support\MailboxConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundImapPullerTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tickets');
        Mail::fake();
        $this->sales = Department::create([
            'name' => 'Sales', 'slug' => 'sales', 'email' => 'sales@example.test', 'imap_enabled' => true,
            'imap_host' => 'imap.example.test', 'imap_username' => 'sales', 'imap_password' => 's3cret-pass',
        ]);
    }

    private function fixture(string $name): string
    {
        return file_get_contents(base_path('tests/Fixtures/mail/'.$name.'.eml'));
    }

    private function puller(FakeMailbox $box): ImapPuller
    {
        return new ImapPuller(app(InboundEmail::class), new FakeConnector($box));
    }

    public function test_new_mail_becomes_tickets_oldest_first_and_is_marked_seen(): void
    {
        $box = new FakeMailbox([7 => $this->fixture('auto_reply'), 3 => $this->fixture('new_plain'), 5 => $this->fixture('html_only')]);

        $count = $this->puller($box)->pull($this->sales);

        $this->assertSame(3, $count);
        $this->assertSame([3, 5, 7], $box->fetched);
        $this->assertSame([3, 5, 7], $box->seen);   // ignored mail is marked seen too
        $this->assertSame([], $box->deleted);
        $this->assertSame(2, Ticket::count());
        $this->assertTrue($box->closed);
        $this->sales->refresh();
        $this->assertNotNull($this->sales->imap_checked_at);
        $this->assertNull($this->sales->imap_error);
    }

    public function test_delete_mode_deletes_and_expunges(): void
    {
        $this->sales->update(['imap_after' => 'delete']);
        $box = new FakeMailbox([1 => $this->fixture('new_plain'), 2 => $this->fixture('unknown_recipient')]);

        $this->puller($box)->pull($this->sales);

        $this->assertSame([1, 2], $box->deleted);
        $this->assertSame([], $box->seen);
        $this->assertTrue($box->expunged);
    }

    public function test_the_mailboxs_department_receives_mail_whatever_it_is_addressed_to(): void
    {
        $box = new FakeMailbox([1 => $this->fixture('unknown_recipient')]);

        $this->puller($box)->pull($this->sales);

        $this->assertSame($this->sales->id, Ticket::first()->department_id);
    }

    public function test_a_failing_message_is_left_unseen_and_the_error_is_recorded_without_the_password(): void
    {
        $box = new FakeMailbox([1 => $this->fixture('new_plain'), 2 => 'boom s3cret-pass', 3 => $this->fixture('html_only')]);
        $box->failOn = 2;

        $count = $this->puller($box)->pull($this->sales);

        $this->assertSame(2, $count);
        $this->assertSame([1, 3], $box->seen);
        $this->assertContains(2, $box->unseen);
        $this->sales->refresh();
        $this->assertStringContainsString('failed to read', (string) $this->sales->imap_error);
        $this->assertStringNotContainsString('s3cret-pass', (string) $this->sales->imap_error);
        $this->assertNotNull($this->sales->imap_checked_at);
        $this->assertSame(2, Ticket::count());
    }

    public function test_a_later_clean_run_clears_the_error(): void
    {
        $this->sales->update(['imap_error' => 'old problem']);

        $this->puller(new FakeMailbox([]))->pull($this->sales);

        $this->assertNull($this->sales->refresh()->imap_error);
    }

    public function test_connection_failure_is_recorded_and_hides_the_password(): void
    {
        $connector = new FakeConnector(null, new \RuntimeException('Login failed for user sales with password s3cret-pass'));
        $puller = new ImapPuller(app(InboundEmail::class), $connector);

        $this->assertSame(0, $puller->pull($this->sales));

        $error = (string) $this->sales->refresh()->imap_error;
        $this->assertStringContainsString('Login failed', $error);
        $this->assertStringNotContainsString('s3cret-pass', $error);
        $this->assertNotNull($this->sales->imap_checked_at);
    }

    public function test_batch_size_is_limited(): void
    {
        $mails = [];
        foreach (range(1, 60) as $i) {
            $mails[$i] = str_replace('new-plain-1@', "n{$i}@", $this->fixture('new_plain'));
        }
        $box = new FakeMailbox($mails);

        $this->assertSame(50, $this->puller($box)->pull($this->sales));
        $this->assertCount(50, $box->seen);
    }

    public function test_connection_test_reports_the_unread_count_or_the_failure(): void
    {
        $box = new FakeMailbox([1 => 'x', 2 => 'y']);
        $this->assertSame(['ok' => true, 'message' => 'Connected. 2 unread messages in INBOX.'], $this->puller($box)->test($this->sales));
        $this->assertSame([], $box->fetched); // nothing is read or changed

        $bad = new ImapPuller(app(InboundEmail::class), new FakeConnector(null, new \RuntimeException('connection setup failed (s3cret-pass)')));
        $result = $bad->test($this->sales);
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('s3cret-pass', $result['message']);
    }

    public function test_inactive_departments_are_not_polled(): void
    {
        $this->sales->update(['is_active' => false]);
        $box = new FakeMailbox([1 => $this->fixture('new_plain')]);

        $this->assertSame(0, $this->puller($box)->pull($this->sales));
        $this->assertSame([], $box->fetched);
    }
}

class FakeConnector implements MailboxConnector
{
    public function __construct(private readonly ?FakeMailbox $box, private readonly ?\Throwable $error = null) {}

    public function open(Department $department): Mailbox
    {
        if ($this->error) {
            throw $this->error;
        }

        return $this->box;
    }
}

class FakeMailbox implements Mailbox
{
    /** @var array<int, int> */
    public array $fetched = [];

    public array $seen = [];

    public array $unseen = [];

    public array $deleted = [];

    public bool $expunged = false;

    public bool $closed = false;

    public ?int $failOn = null;

    /** @param array<int, string> $messages uid => raw mime */
    public function __construct(private array $messages) {}

    public function unseenCount(): int
    {
        return count($this->messages);
    }

    public function unseenIds(int $limit): array
    {
        $ids = array_keys($this->messages);
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    public function fetch(int|string $id): string
    {
        $this->fetched[] = $id;
        if ($id === $this->failOn) {
            throw new \RuntimeException('failed to read message with s3cret-pass');
        }

        return $this->messages[$id];
    }

    public function markSeen(int|string $id): void
    {
        $this->seen[] = $id;
    }

    public function markUnseen(int|string $id): void
    {
        $this->unseen[] = $id;
    }

    public function delete(int|string $id): void
    {
        $this->deleted[] = $id;
    }

    public function expunge(): void
    {
        $this->expunged = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
