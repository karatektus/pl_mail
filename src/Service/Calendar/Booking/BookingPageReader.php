<?php

declare(strict_types=1);

namespace App\Service\Calendar\Booking;

use App\Entity\Calendar\BookingPage;
use App\Repository\Calendar\BookingPageRepository;
use App\Service\Calendar\Sharing\PublicLinkToken;

/**
 * Turning a token in a URL into a booking page, or into nothing.
 *
 * The booking half's counterpart to ShareLinkReader::resolve(), and separate
 * from BookingAvailabilityReader on purpose: resolving a credential and
 * computing availability are different jobs with different failure modes, and
 * the availability reader is also used by the settings preview, where the token
 * has already been resolved by having the entity in hand.
 *
 * **Disabled, unknown and malformed all answer null.** The controller makes one
 * 404 out of all three. Telling them apart would confirm which tokens had once
 * been real — the rule DevicePairingService::redeem() states about its own
 * three failure modes — and it would also let anyone holding a URL learn that
 * the owner had taken their page down, which is a fact about somebody's
 * availability that they chose to stop publishing.
 *
 * A page whose destination calendar has gone answers null too. That cannot
 * happen through the cascade, which takes the page with the calendar, but it
 * can happen to an entity mid-request, and a page that would accept a booking
 * it has nowhere to put must not be reachable.
 */
final readonly class BookingPageReader
{
    public function __construct(
        private BookingPageRepository $pages,
        private PublicLinkToken       $tokens,
    ) {
    }

    public function resolve(string $token): ?BookingPage
    {
        $page = $this->pages->findOneByDigest($this->tokens->digest($token));

        if (null === $page || false === $page->isEnabled) {
            return null;
        }

        return $page;
    }
}
