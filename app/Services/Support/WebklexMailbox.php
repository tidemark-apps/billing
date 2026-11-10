<?php

namespace App\Services\Support;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\IMAP;

class WebklexMailbox implements Mailbox
{
    public function __construct(private readonly Client $client) {}

    public function unseenCount(): int
    {
        return count($this->search());
    }

    public function unseenIds(int $limit): array
    {
        $ids = array_map('intval', $this->search());
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    public function fetch(int|string $id): string
    {
        $conn = $this->client->getConnection();
        // Fetching without PEEK sets \Seen on most servers; the puller decides what the final flag should be.
        $header = $this->single($conn->headers([(int) $id], 'RFC822', IMAP::ST_UID)->validatedData());
        $body = $this->single($conn->content([(int) $id], 'RFC822', IMAP::ST_UID)->validatedData());
        $header = rtrim(str_replace("\r\n", "\n", $header), "\n");

        return str_replace("\n", "\r\n", $header)."\r\n\r\n".$body;
    }

    public function markSeen(int|string $id): void
    {
        $this->client->getConnection()->store(['\\Seen'], (int) $id, null, '+', true, IMAP::ST_UID);
    }

    public function markUnseen(int|string $id): void
    {
        $this->client->getConnection()->store(['\\Seen'], (int) $id, null, '-', true, IMAP::ST_UID);
    }

    public function delete(int|string $id): void
    {
        $this->client->getConnection()->store(['\\Deleted'], (int) $id, null, '+', true, IMAP::ST_UID);
    }

    public function expunge(): void
    {
        $this->client->expunge();
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
    }

    /** @return array<int, mixed> */
    private function search(): array
    {
        $found = $this->client->getConnection()->search(['UNSEEN'], IMAP::ST_UID)->validatedData();

        return is_array($found) ? $found : [];
    }

    private function single(mixed $data): string
    {
        if (is_array($data)) {
            $data = reset($data);
        }

        return is_string($data) ? $data : '';
    }
}
