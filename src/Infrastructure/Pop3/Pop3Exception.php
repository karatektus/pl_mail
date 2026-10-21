<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

/**
 * A POP3 conversation that did not go the way it was asked to.
 *
 * One exception for the protocol, deliberately, and not a hierarchy: nothing
 * above this class branches on WHY a session failed. A wrong password, a
 * certificate the CA store will not accept, a server that hung up mid-RETR and
 * a DNS failure all cause the same thing — the sync stops, the mailbox's
 * high-water mark stays where it was, and Account::recordSyncFailure() puts
 * the message in front of the user. The message is for a person to read; the
 * class is what tells every caller that the mail is not in hand.
 *
 * The one distinction that does get made is in the message text, because
 * "authentication failed" and "could not reach" are the two a user has
 * different remedies for. That is a sentence, not a type.
 */
final class Pop3Exception extends \RuntimeException
{
}
