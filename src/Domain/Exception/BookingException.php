<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * A booking that could not be made.
 *
 * Shaped by what the caller should do, like the sync hierarchies above it, and
 * the two subclasses are the two answers a public booking endpoint can give:
 *
 *   BookingRefusedException  — the request was wrong. Re-render the form with
 *                              the message, keep what the person typed.
 *   BookingSlotTakenException — the request was fine and somebody else got
 *                              there first. Throw the form away and re-offer
 *                              the slot list, because it has changed.
 *
 * The distinction is not cosmetic. Re-rendering a taken slot's form leaves the
 * booker looking at a time that no longer exists with their details still in
 * it, and every further attempt fails the same way; re-offering the list is the
 * only response that leads anywhere. Conversely, throwing away a form because
 * an address had a typo is how somebody gives up on booking.
 *
 * Every message here is read by a stranger with no account and no context, so
 * it says what to do and never why the server thinks so — no slot instants, no
 * calendar names, no indication of what else is in the owner's diary.
 */
class BookingException extends \RuntimeException
{
}
