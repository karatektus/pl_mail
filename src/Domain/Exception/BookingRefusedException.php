<?php

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * The booking request itself was wrong: a missing name, an address that is not
 * one, a slot the page does not offer.
 *
 * Carries a message already written for the person who typed it, because that
 * person is a stranger with no account here and nowhere else to find out what
 * happened. The caller renders it beside the form with the fields still filled
 * in — see BookingException for why that differs from a lost race.
 */
final class BookingRefusedException extends BookingException
{
}
