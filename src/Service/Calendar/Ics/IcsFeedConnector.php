<?php

declare(strict_types=1);

namespace App\Service\Calendar\Ics;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Service\Calendar\Subscription\CalendarSubscriber;
use App\Repository\Integration\IntegrationRepository;
use App\Service\Calendar\Sync\IcsUrl\IcsUrlNormaliser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turning a pasted calendar address into a mirrored calendar, in one step.
 *
 * The counterpart of CalDavConnector, and shorter for a reason worth naming:
 * connecting a CalDAV server is a credential problem — an address, a username,
 * an app password, and the deliberate offer to reuse a mail account's — where
 * subscribing to a feed is an address and nothing else. There is nobody to
 * authenticate as. For a published calendar the address *is* the credential,
 * which is why the connect form warns that anyone holding it can read the
 * calendar and why nothing renders it back once it is stored.
 *
 * ── One read, and a failed subscription leaves nothing behind ─────────────
 *
 * The row is written first and removed again if the address turns out not to be
 * a calendar, rather than the connection being probed before it is written.
 * That looks backwards and buys the thing that matters: **the feed is fetched
 * exactly once.** Probing first would mean a verify() read and then a discover()
 * read of the same file a second later, and a feed is the whole calendar rather
 * than a status endpoint — for a decade of fixtures that is a few megabytes
 * downloaded twice to answer one question.
 *
 * Removing it on failure is the other half, and is a genuine difference from
 * CalDavConnector rather than an inconsistency. A CalDAV connection that fails
 * to verify is worth keeping: the password may be rotated, the server may come
 * back, and the settings list renders its lastError so the user can retry it.
 * A subscription whose address is not a calendar has nothing to retry — there
 * is no credential to correct and no second field to change — so keeping it
 * would leave a permanently broken row that the user has to notice and delete
 * before pasting the corrected address, which is the same address with a typo
 * fixed.
 *
 * The single most likely failure is worth stating, because it is what the
 * message has to explain: a "Subscribe" button copies a link to a *web page*
 * about as often as it copies a link to an .ics, and the two are
 * indistinguishable until something tries to parse one.
 */
final readonly class IcsFeedConnector
{
    public function __construct(
        private IcsUrlNormaliser       $normaliser,
        private CalendarSubscriber     $subscriber,
        private IntegrationRepository  $integrations,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Subscribe to one published calendar.
     *
     * @param string $name what to call it, or empty to let the feed name itself
     *
     * @return string|null the failure message, written for a person, or null
     *                     when the calendar is now mirrored
     *
     * @throws IntegrationException when the address is malformed, uses a scheme
     *                              plMail cannot fetch, or points inside the
     *                              deployment's own network — a refusal about
     *                              the field rather than about the far end,
     *                              which is why it is raised rather than
     *                              returned
     */
    public function connect(User $user, string $url, string $name = ''): ?string
    {
        // Normalised before anything else: webcal:// is rewritten once, here,
        // so nothing downstream has to remember that a stored address might not
        // be fetchable as written. The validator's refusal travels as
        // IntegrationException, which the form renders against the address
        // field.
        $address = $this->normaliser->normalise($url);

        $integration          = new Integration($user, Provider::Ics, $this->nameFor($user, $address, $name));
        $integration->baseUrl = $address;

        $this->em->persist($integration);
        $this->em->flush();

        try {
            $this->subscriber->subscribeAll(CalendarSource::ofIntegration($integration));
        } catch (CalendarSyncException $e) {
            $this->em->remove($integration);
            $this->em->flush();

            return $e->getMessage();
        }

        $integration->recordSuccess();

        $this->em->flush();

        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A name for the connection that is not already taken.
     *
     * Integration is unique on (usr, provider, name), and this is the only
     * provider a user is likely to connect several of — one holiday feed, one
     * for the school term dates, one per team. Left to the entity's own
     * fallback every unnamed feed would be called "Calendar feed" and the
     * *second* one would fail the constraint, which arrives at the user as a
     * 500 on a form they filled in correctly.
     *
     * So an empty name becomes the address's own filename, and a name already
     * in use gains a number. Numbered rather than refused: the user did not
     * choose the fallback and cannot be asked to resolve a collision between
     * two names they never typed — and a user who *did* type a duplicate name
     * meant a second feed, not a correction to the first.
     *
     * Bounded, because the loop's exit condition is data: a hundred feeds under
     * one name is somebody probing rather than somebody organising, and the
     * hundred-and-first is left to the constraint.
     */
    private function nameFor(User $user, string $address, string $requested): string
    {
        $wanted = trim($requested);
        $base   = mb_substr('' === $wanted ? $this->normaliser->suggestedName($address) : $wanted, 0, 90);
        $taken  = [];

        foreach ($this->integrations->findForUserOrdered($user) as $existing) {
            if (Provider::Ics === $existing->provider) {
                $taken[] = $existing->name;
            }
        }

        if (false === in_array($base, $taken, true)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 100; ++$suffix) {
            $candidate = sprintf('%s %d', $base, $suffix);

            if (false === in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return $base;
    }
}
