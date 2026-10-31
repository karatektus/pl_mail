<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\ImapSession;

/**
 * Whether a folder keeps keywords of our choosing (#221 step 2).
 *
 * The server says so when a folder is selected, in a response code:
 * `* OK [PERMANENTFLAGS (\Answered \Flagged \Deleted \Seen \Draft \*)]`. The
 * `\*` is the answer: new keywords may be created and are kept. Without it a
 * keyword we STORE may be accepted for the session and gone at the next one.
 *
 * Asked with SELECT, not EXAMINE: a read-only selection answers with an empty
 * list (Dovecot does), which would read as "no" for every folder.
 *
 * ImapSession::select() reads the code off the response
 * (ImapMailboxState::$permanentFlags).
 *
 * Leaves the folder selected, read-write, on that connection, which is what the
 * caller wants next: the STORE. The answer is written to the row.
 */
final class ImapPermanentFlags
{
    /**
     * @return bool|null null when the server did not say (no PERMANENTFLAGS
     *                   code at all, or the SELECT failed): unknown, not "no"
     */
    public function probe(ImapSession $session, Mailbox $mailbox): ?bool
    {
        try {
            $state = $session->select($mailbox->serverPath());
        } catch (ImapCommandException) {
            return null;
        }

        $answer = self::keepsKeywords($state->permanentFlags);

        if (null !== $answer) {
            $mailbox->keywordsAllowed = $answer;
        }

        return $answer;
    }

    /**
     * The `\*` question, asked of what the server listed. Public and static so
     * it can be tested on what real servers sent.
     *
     * @param list<string>|null $permanentFlags
     */
    public static function keepsKeywords(?array $permanentFlags): ?bool
    {
        if (null === $permanentFlags) {
            return null;
        }

        return true === in_array('\\*', $permanentFlags, true);
    }
}
