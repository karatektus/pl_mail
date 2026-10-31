<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\ImapClient\ImapSession;

/**
 * A logged-in session on the test stack's Dovecot (compose.test.yaml), as a
 * user nobody else uses: the server takes any name with the stack's
 * password and gives each its own empty mailbox.
 *
 * In the clear on the plain port, which tests/dovecot/cleartext.conf allows;
 * the client's own TLS is tested in DovecotImapTest. Skips the test where
 * IMAP_TEST_HOST names no server, which is anywhere outside the stack.
 */
trait UsesDovecot
{
    protected function dovecotSession(?ImapLoop $loop = null, ?string $user = null): ImapSession
    {
        $host     = getenv('IMAP_TEST_HOST');
        $password = getenv('IMAP_TEST_PASSWORD');

        if (false === $host || '' === $host || false === $password) {
            self::markTestSkipped('Set IMAP_TEST_HOST and IMAP_TEST_PASSWORD to run against an IMAP server; the test stack does.');
        }

        $connection = ImapConnection::open($loop ?? new ImapLoop(), new ImapEndpoint($host, 31143, ImapSecurity::None));
        $connection->authenticate(new ImapCredentials($user ?? 'test-' . bin2hex(random_bytes(6)), $password));

        return new ImapSession($connection);
    }
}
