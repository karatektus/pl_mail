<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Mailbox;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;

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
 * Read off the raw response because webklex's own SELECT drops the code, and
 * its tokenizer splits it oddly — `\*)]` is one token — so the parse looks for
 * the `\*` among the tokens that follow `[PERMANENTFLAGS` rather than trusting
 * the brackets.
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
    public function probe(Client $client, Mailbox $mailbox): ?bool
    {
        $protocol = $client->getConnection();

        if (false === $protocol instanceof ImapProtocol) {
            return null;
        }

        try {
            $lines = $protocol->requestAndResponse('SELECT', [$protocol->escapeString((string) $mailbox->fullPath)])->data();
        } catch (\Throwable) {
            return null;
        }

        $answer = self::fromResponse((array) $lines);

        if (null !== $answer) {
            $mailbox->keywordsAllowed = $answer;
        }

        return $answer;
    }

    /**
     * The `\*` question asked of a SELECT's untagged lines, as webklex parsed
     * them. Public and static so the parse can be tested on what real servers
     * sent.
     *
     * @param array<mixed> $lines
     */
    public static function fromResponse(array $lines): ?bool
    {
        foreach ($lines as $line) {
            if (false === is_array($line)) {
                continue;
            }

            $tokens = self::flatten($line);
            $at     = array_search('[PERMANENTFLAGS', $tokens, true);

            if (false === $at) {
                continue;
            }

            foreach (array_slice($tokens, (int) $at + 1) as $token) {
                if (str_starts_with($token, '\\*')) {
                    return true;
                }

                if (str_contains($token, ']')) {
                    break;
                }
            }

            return false;
        }

        return null;
    }

    /**
     * @param array<mixed> $tokens
     *
     * @return list<string>
     */
    private static function flatten(array $tokens): array
    {
        $flat = [];

        foreach ($tokens as $token) {
            if (true === is_array($token)) {
                array_push($flat, ...self::flatten($token));
            } elseif (true === is_scalar($token)) {
                $flat[] = (string) $token;
            }
        }

        return $flat;
    }
}
