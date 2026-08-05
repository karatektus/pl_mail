<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;

/**
 * One calendar, and this meeting's row on it — the row already there, or the
 * row a save would make.
 *
 * The editor's calendar list is a list of these, one per calendar the user
 * owns, and that is what lets a single control mean both "keep writing this
 * copy" and "put it here too". A pair of (calendar, maybe a row) would have
 * been the obvious shape and is not the one used: every reader would then
 * carry its own "and if there is none, make one" branch, and the two that
 * matter — the template deciding what to tick and the save deciding what to
 * write — would be free to disagree about what a copy on an empty calendar is.
 * Here there is always exactly one row per calendar, and the only question left
 * is whether it has been persisted yet.
 *
 * $isChosen is what the checkbox opens as, not what the user chose: the save
 * reads the posted calendar ids and never this. It is false on every read-only
 * calendar, because a copy nothing may write must not be ticked — the template
 * renders those disabled and EventCopyResolver::chosen() refuses them again,
 * since a disabled checkbox is a statement to a browser and not a guarantee to
 * a server.
 */
final readonly class EventCopy
{
    public function __construct(
        public Calendar      $calendar,
        public CalendarEvent $event,
        public bool          $isChosen,
    ) {
    }

    /**
     * Whether saving this copy would create the row rather than update it.
     *
     * Stays a method for the reason CalendarEvent::isExtracted() does: it is an
     * interpretation of another object's identifier, not a piece of state this
     * DTO holds, and a field beside $event could be handed in disagreeing with
     * it. A create is not cosmetic — it is the difference between
     * markLocallyCreated() and markLocallyChanged(), which is the difference
     * between a POST and a PUT at the provider.
     */
    public function isNew(): bool
    {
        return null === $this->event->id;
    }
}
