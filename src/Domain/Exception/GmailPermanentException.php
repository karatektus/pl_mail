<?php

declare(strict_types=1);

namespace App\Domain\Exception;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A Gmail 403 that will answer the same way on every attempt —
 * insufficientPermissions (the grant is wrong, only re-consent fixes it),
 * dailyLimitExceeded (nothing resets before midnight Pacific) and
 * accountSuspended.
 *
 * Unrecoverable, so the message goes straight to the failure transport. The
 * point is not tidiness: retrying insufficientPermissions buries the one log
 * line that says what is actually wrong under three identical ones, and
 * retrying dailyLimitExceeded spends quota the account no longer has.
 */
final class GmailPermanentException extends GmailApiException implements UnrecoverableExceptionInterface
{
}
