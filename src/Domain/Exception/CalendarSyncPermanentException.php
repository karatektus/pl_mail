<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A calendar failure that answers the same way on every attempt: the grant no
 * longer carries the calendar scope, the calendar has been deleted at the
 * remote, the CalDAV collection is gone, the account is suspended.
 *
 * Unrecoverable, so the envelope goes straight to the failure transport. That
 * is not tidiness. Retrying a missing scope buries the one log line that says
 * "reconnect and allow calendar access" under three identical ones, and the
 * user is left watching a calendar that never fills in with nothing on screen
 * to explain why.
 *
 * The distinction that matters most here is against the throttled case, and it
 * is not always visible from the status. Google answers 403 for a missing scope
 * and 403 for a rate limit; a driver that cannot tell them apart from the body
 * must raise the plain CalendarSyncException instead of guessing this one,
 * because a permanent classification is a decision not to try again.
 */
final class CalendarSyncPermanentException extends CalendarSyncException implements UnrecoverableExceptionInterface
{
}
