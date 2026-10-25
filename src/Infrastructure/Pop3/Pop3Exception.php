<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

/**
 * A POP3 conversation that did not go the way it was asked to.
 *
 * One exception for the protocol, and one subclass: Pop3RefusedException, for
 * a server that answered -ERR to a command. That is the one distinction the
 * syncer makes — a message the server will not hand over is that message's
 * problem, a dropped connection is the whole sync's. Nothing else branches on
 * WHY a session failed. A wrong password, a
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
class Pop3Exception extends \RuntimeException
{
}
