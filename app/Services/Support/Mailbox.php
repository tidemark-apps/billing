<?php

namespace App\Services\Support;

/** An open mail folder, as the puller sees it. Swappable so tests need no IMAP server. */
interface Mailbox
{
    /** How many messages are unread. */
    public function unseenCount(): int;

    /**
     * Ids of unread messages, oldest first.
     *
     * @return array<int, int|string>
     */
    public function unseenIds(int $limit): array;

    /** The whole raw message (headers and body). */
    public function fetch(int|string $id): string;

    public function markSeen(int|string $id): void;

    public function markUnseen(int|string $id): void;

    public function delete(int|string $id): void;

    /** Remove messages flagged as deleted. */
    public function expunge(): void;

    public function close(): void;
}
