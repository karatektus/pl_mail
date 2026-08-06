<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\ParticipationStatus;

/**
 * Whether an event is an invitation *to this mailbox*, and what was answered.
 *
 * The one place that reads `jscalendar.participants` in order to decide
 * CalendarEvent::$myParticipation, which is what governs whether an invitation
 * is drawn at all. InviteReader reads the same key for the card above a message,
 * and the two deliberately do not share code: that one builds a list of people
 * for a human to look at, this one answers a single yes/no/not-applicable that a
 * materialiser acts on, and merging them would give the card's charitable
 * "unknown reads as needs-action" a consequence it must not have here.
 *
 * **Null means "not an invitation to us".** Three cases produce it, and all
 * three must be drawn unconditionally:
 *
 *   nobody on the participant list is one of this mailbox's addresses — a
 *   booking read out of a confirmation, an event somebody typed, a row mirrored
 *   from a provider;
 *
 *   there is no organiser other than us — an invitation the owner sent is
 *   theirs, and waiting for them to accept their own meeting is absurd;
 *
 *   there are no participants at all.
 *
 * Everything else is a real invitation and answers a real status, which is
 * NeedsAction until somebody says otherwise.
 */
final readonly class InviteParticipationResolver
{
    /**
     * Takes the JSCalendar object rather than the event, so it can be asked
     * about a claim that has not been written yet — which is exactly when the
     * reconciler needs the answer, since whether occurrences get written
     * depends on it.
     *
     * @param array<string,mixed> $jscalendar
     * @param list<string>        $ownedAddresses lowercased, as Account::$ownedAddresses gives them
     */
    public function resolve(array $jscalendar, array $ownedAddresses): ?ParticipationStatus
    {
        $participants = $jscalendar['participants'] ?? null;

        if (false === is_array($participants) || [] === $participants || [] === $ownedAddresses) {
            return null;
        }

        $mine             = null;
        $organisedByOther = false;

        foreach ($participants as $key => $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            // Keyed by lowercased address where this application wrote it, but
            // RFC 8984 says the key is opaque — a participant imported from
            // elsewhere may be keyed by anything, so the address comes from the
            // entry and the key is only the fallback. Same rule as InviteReader.
            $address = mb_strtolower(trim((string) ($entry['email'] ?? $key)));

            if ('' === $address) {
                continue;
            }

            $roles       = $entry['roles'] ?? [];
            $isOrganiser = is_array($roles) && true === ($roles['owner'] ?? false);
            $isMe        = in_array($address, $ownedAddresses, true);

            if (true === $isOrganiser && false === $isMe) {
                $organisedByOther = true;
            }

            if (true === $isMe) {
                // An organiser who is also an attendee appears twice, and the
                // attendee line is the one carrying PARTSTAT. First answer that
                // is not the default wins, so the two orders agree.
                $status = ParticipationStatus::fromJsCalendar(
                    is_string($entry['participationStatus'] ?? null) ? $entry['participationStatus'] : null,
                );

                if (null === $mine || ParticipationStatus::NeedsAction === $mine) {
                    $mine = $status;
                }
            }
        }

        if (null === $mine || false === $organisedByOther) {
            return null;
        }

        return $mine;
    }

    /**
     * What to store, given what is already stored — the rule that keeps an
     * organiser from un-answering a meeting somebody is going to.
     *
     * An organiser's REQUEST carries the attendee list as *they* last saw it,
     * so a resend routinely arrives saying NEEDS-ACTION for a person who
     * accepted last week. Taking it at face value would empty the meeting out of
     * their calendar, and the only visible symptom would be a missing chip.
     *
     * A downgrade to NeedsAction is therefore refused whenever an answer exists.
     * A real answer in the incoming invitation is not refused — the organiser
     * may legitimately be relaying an answer made in another client — and
     * neither is losing the invitation shape entirely, which is what a null
     * incoming value means and is not a downgrade at all.
     */
    public function merge(?ParticipationStatus $stored, ?ParticipationStatus $incoming): ?ParticipationStatus
    {
        if (ParticipationStatus::NeedsAction !== $incoming) {
            return $incoming;
        }

        return null !== $stored && true === $stored->isAnswer() ? $stored : $incoming;
    }
}
