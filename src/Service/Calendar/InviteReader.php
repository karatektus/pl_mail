<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\InviteParticipant;
use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\EventSourceLinkRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a message is an invitation, and what the card above it should say.
 *
 * The parse happened long ago — IcsEventExtractor read the VEVENT when the mail
 * arrived and wrote its participants into the event's JSCalendar object. This
 * is the read side, and it exists because the three things the card needs live
 * in three places: the event has the times and the people, the source link has
 * the METHOD that separates an invitation from a cancellation, and the account
 * has the addresses that decide which of those people is the one reading.
 *
 * Loads per conversation, not per message. The card is drawn by a partial that
 * is included once per message, so a lookup keyed on the message would be an
 * indexed query per row on every thread anyone opens — to answer "not an
 * invitation" for nearly all of them. The first question about any message in a
 * thread loads the whole thread's links; the rest are answered from memory.
 *
 * Resettable rather than merely per-request by convention: this holds entities,
 * and under a worker runtime a cache that outlives its request hands out
 * objects belonging to a closed entity manager.
 */
final class InviteReader implements ResetInterface
{
    /**
     * Message id to its invitation, including the nulls — "asked and there is
     * none" has to be distinguishable from "not asked yet", or every miss is
     * re-queried.
     *
     * @var array<int, MessageInvite|null>
     */
    private array $invites = [];

    /** @var array<int, true> thread ids already loaded */
    private array $loadedThreads = [];

    public function __construct(
        private readonly EventSourceLinkRepository $links,
    ) {
    }

    public function forMessage(Message $message, ?User $user): ?MessageInvite
    {
        $id = $message->id;

        if (null === $id || false === $user instanceof User) {
            return null;
        }

        // The account is the authorisation, not the route: this is reached from
        // a template with whatever message that template was handed.
        if ($message->account->usr !== $user) {
            return null;
        }

        if (true === array_key_exists($id, $this->invites)) {
            return $this->invites[$id];
        }

        foreach ($this->load($message) as $link) {
            $linkedId = $link->message?->id;

            if (null === $linkedId) {
                continue;
            }

            // First link per message wins. A message carrying several VEVENTs
            // makes several events and several links; the card shows the first,
            // because RSVP is answered per invitation and an invitation that
            // arrives as a bundle is a shape nothing in the wild sends.
            $this->invites[$linkedId] ??= $this->toInvite($link, $user);
        }

        // Still absent means this message has no calendar link at all.
        return $this->invites[$id] ??= null;
    }

    public function reset(): void
    {
        $this->invites       = [];
        $this->loadedThreads = [];
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @return list<EventSourceLink>
     */
    private function load(Message $message): array
    {
        $thread = $message->thread;

        if (null === $thread || null === $thread->id) {
            return $this->links->findInvitesForMessage($message);
        }

        if (true === array_key_exists($thread->id, $this->loadedThreads)) {
            return [];
        }

        $this->loadedThreads[$thread->id] = true;

        return $this->links->findInvitesForThread($thread);
    }

    private function toInvite(EventSourceLink $link, User $user): ?MessageInvite
    {
        $event   = $link->event;
        $message = $link->message;

        if (null === $event || null === $message) {
            return null;
        }

        $method       = mb_strtoupper(trim((string) ($link->payload['method'] ?? '')));
        $participants = $this->participantsOf($event, $message->account->ownedAddresses);

        $organiser = null;
        $me        = null;

        foreach ($participants as $participant) {
            if (null === $organiser && true === $participant->isOrganiser) {
                $organiser = $participant;
            }

            if (null === $me && true === $participant->isMe) {
                $me = $participant;
            }
        }

        $isCancellation = 'CANCEL' === $method || EventStatus::Cancelled === $event->status;

        return new MessageInvite(
            message:        $message,
            event:          $event,
            organiser:      $organiser,
            participants:   $participants,
            me:             $me,
            isCancellation: $isCancellation,
            canRespond:     $this->canRespond($method, $organiser, $me, $isCancellation),
        );
    }

    /**
     * An invitation somebody else sent, addressed to us, still standing.
     *
     * A missing METHOD counts as a request when the shape says so. It is
     * supposed to be there and usually is, but senders omit it and Exchange
     * strips it on some paths — and an .ics naming an organiser and listing us
     * as an attendee is an invitation whatever its envelope forgot to say.
     * PUBLISH is not accepted: that is a calendar being shared, and replying to
     * one sends mail nobody asked for.
     */
    private function canRespond(
        string             $method,
        ?InviteParticipant $organiser,
        ?InviteParticipant $me,
        bool               $isCancellation,
    ): bool {
        if (true === $isCancellation || null === $me || null === $organiser) {
            return false;
        }

        // Answering your own invitation is not a thing, and the reply would be
        // addressed to yourself.
        if (true === $me->isOrganiser) {
            return false;
        }

        return 'REQUEST' === $method || '' === $method;
    }

    /**
     * @param list<string> $ownedAddresses lowercased, from Account::$ownedAddresses
     *
     * @return list<InviteParticipant> organiser first
     */
    private function participantsOf(CalendarEvent $event, array $ownedAddresses): array
    {
        $raw = $event->jscalendar['participants'] ?? null;

        if (false === is_array($raw)) {
            return [];
        }

        $organisers = [];
        $attendees  = [];

        foreach ($raw as $key => $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            // Keyed by lowercased address where this application wrote it, but
            // the key is opaque in RFC 8984 — a participant imported from
            // elsewhere may be keyed by anything, so the address comes from the
            // entry and the key is only the fallback.
            $email = trim((string) ($entry['email'] ?? $key));

            if ('' === $email) {
                continue;
            }

            $roles = $entry['roles'] ?? [];
            $name  = trim((string) ($entry['name'] ?? ''));

            $participant = new InviteParticipant(
                email:       $email,
                name:        '' !== $name ? $name : null,
                status:      ParticipationStatus::fromJsCalendar($entry['participationStatus'] ?? null),
                isOrganiser: is_array($roles) && true === ($roles['owner'] ?? false),
                isMe:        in_array(mb_strtolower($email), $ownedAddresses, true),
            );

            if (true === $participant->isOrganiser) {
                $organisers[] = $participant;

                continue;
            }

            $attendees[] = $participant;
        }

        return array_merge($organisers, $attendees);
    }
}
