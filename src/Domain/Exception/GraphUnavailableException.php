<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * HTTP 500, 502 or 504 from Graph: Microsoft's side did not answer (#603).
 *
 * The shape is nearly always the same — `"code": "UnknownError"` with an empty
 * message — and it says nothing about the request or the account. It is what
 * an Exchange Online incident looks like from here, and the one that prompted
 * this (12–13 November 2026) lasted the better part of a day.
 *
 * Its own class, and not GraphThrottledException, because the two are not the
 * same statement and the log lines must not pretend they are: a throttle names
 * a limit and carries Retry-After, an outage carries neither. What they share
 * is the answer at the end of the retry ladder, which GiveUpQuietlyOnThrottling
 * gives both: a sync that will be asked again is dropped with one warning, and
 * a batch of messages nothing will list again is put back to wait. Before this
 * class a 502 was a plain GraphApiException, so every job caught in an outage
 * was logged at CRITICAL and parked on the failed queue for someone to put back
 * by hand.
 *
 * Deliberately NOT Recoverable. Messenger retries a plain exception up to the
 * transport's max_retries anyway, and that is all this needs; the marker would
 * add nothing but the suggestion that a write which may have landed — a 502 is
 * the gateway giving up on the answer, not proof that Exchange never acted — is
 * safe to repeat. Whether a POST is repeated stays where it was decided before.
 *
 * Still a GraphApiException with the status as its code, so every caller that
 * catches the parent or reads getCode() sees exactly what it saw before.
 */
final class GraphUnavailableException extends GraphApiException
{
    /** The statuses that mean "Microsoft's side failed", not "you asked wrongly". */
    public const array STATUSES = [500, 502, 504];
}
