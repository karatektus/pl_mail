<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Infrastructure\ImapClient\Exception\ImapCommandException;
use App\Infrastructure\ImapClient\ImapSession;

/**
 * UID SEARCH, with the one wrong answer a server gives to it corrected.
 *
 * Proton Mail Bridge answered the sync's `UID SEARCH UID n:*` with
 * `NO no such message` (#219), and the folder's sync failed. RFC 3501 has no
 * such answer for a search — one that matches nothing is `OK` with an empty
 * list — and a range a server cannot find a message in is one that matches
 * nothing, most likely a folder with no new mail or none at all.
 *
 * Read as empty only for a RANGE, and only when asked. A range that finds
 * nothing moves nothing: the next run asks it again. A search for a SET of
 * UIDs is different — the importer asks for UIDs it found a moment ago, and
 * if the server refuses the set because one has gone since, "none" would let
 * it move past the rest for good.
 */
final class ImapUidSearch
{
    /**
     * @return list<int> ascending
     */
    public static function range(ImapSession $session, string $path, string $range): array
    {
        try {
            return $session->uidSearch($path, sprintf('UID %s', $range));
        } catch (ImapCommandException $refused) {
            if (true === self::saysNoSuchMessage($refused)) {
                return [];
            }

            throw $refused;
        }
    }

    /** Whether the server's NO was the one Proton Mail Bridge gives for "found nothing". */
    public static function saysNoSuchMessage(ImapCommandException $refused): bool
    {
        return 'NO' === $refused->response->status && 1 === preg_match('~\bno such message\b~i', $refused->response->text);
    }
}
