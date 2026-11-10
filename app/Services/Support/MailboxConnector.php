<?php

namespace App\Services\Support;

use App\Models\Department;

interface MailboxConnector
{
    /** Connect with the department's IMAP settings and open its folder. @throws \Throwable when it cannot. */
    public function open(Department $department): Mailbox;
}
