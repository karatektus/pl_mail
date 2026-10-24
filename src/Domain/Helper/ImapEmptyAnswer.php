<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Throwable;
use Webklex\PHPIMAP\Exceptions\ResponseException;

/**
 * Tells "the server answered, and the answer had nothing in it" from every
 * other way an IMAP command fails.
 *
 * webklex treats a FETCH that completed with no FETCH lines as a failure. Its
 * Response is "successful" only when it holds data or is one of the few
 * commands marked as allowed to be empty, and FETCH is not among them — so
 * `validatedData()` throws a ResponseException whose one listed cause is
 * "Empty response". The server said OK; the library says the command failed.
 *
 * It matters because an empty answer is a FACT and the other failures are not.
 * A dropped connection, a NO, a BAD mean nothing is known and the right thing
 * is to try again later. "OK, and here is nothing" means the question was
 * answered: the folder holds no messages, or the server will not hand over the
 * one that was asked for. Retrying that gets the same answer for ever, which
 * is how an import sat at 486 of 487 (#96) — iCloud lists a UID in SEARCH that
 * it returns nothing for in FETCH.
 *
 * HOW THE TWO ARE TOLD APART
 *
 *  - a dropped connection never gets as far as a Response: the read throws
 *    webklex's RuntimeException("empty response") — lower case, another class;
 *  - NO, BAD and BYE throw ImapServerErrorException from readResponse();
 *  - a Response with errors lists them as causes beside "Empty response".
 *
 * So: a ResponseException, anywhere in the chain (Query wraps it in
 * GetMessagesFailedException), whose only cause is the empty one.
 */
final class ImapEmptyAnswer
{
    private const string CAUSE = "\t- Empty response\n";

    public static function is(Throwable $e): bool
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if (false === $current instanceof ResponseException) {
                continue;
            }

            $message = $current->getMessage();

            return true === str_contains($message, self::CAUSE)
                && 1 === substr_count($message, "\t- ");
        }

        return false;
    }
}
