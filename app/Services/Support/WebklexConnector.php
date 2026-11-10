<?php

namespace App\Services\Support;

use App\Models\Department;
use Webklex\PHPIMAP\ClientManager;

/** The real thing: talks IMAP with webklex/php-imap (pure PHP, no ext-imap needed). */
class WebklexConnector implements MailboxConnector
{
    public function open(Department $department): Mailbox
    {
        $client = $this->build($department);
        $client->connect();
        $folder = $department->imap_folder ?: 'INBOX';
        $client->openFolder($folder);

        return new WebklexMailbox($client);
    }

    /** The configured client (not yet connected). */
    protected function build(Department $department): SaslClient
    {
        $encryption = match ($department->imap_encryption) {
            'ssl' => 'ssl',
            'starttls', 'tls' => 'starttls',
            default => false,
        };
        $made = (new ClientManager)->make([
            'host' => (string) $department->imap_host,
            'port' => (int) ($department->imap_port ?: 993),
            'protocol' => 'imap',
            'encryption' => $encryption,
            'validate_cert' => (bool) $department->imap_validate_cert,
            'username' => (string) $department->imap_username,
            'password' => (string) $department->imap_password,
            'authentication' => null,
            'timeout' => 20,
        ]);
        // Same configuration, but signing in like a mail client does: SASL PLAIN first, then LOGIN.
        $client = new SaslClient($made->getConfig());

        return $client;
    }
}
