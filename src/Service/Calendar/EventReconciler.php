<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Domain\Enum\Mail\SenderVerdict;
use App\Domain\Helper\AddressHelper;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Calendar\EventSourceLinkRepository;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\Extraction\ExtractedEvent;
use App\Service\Calendar\Extraction\IcsEventExtractor;
use App\Service\Mail\SenderAuthentication;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns what an extractor claimed into what the calendar shows.
 *
 * This is where a booking's life happens: a confirmation, then a change, then
 * a cancellation, usually across a thread and not always in order. Getting it
 * wrong shows up as three copies of one dinner, or a meeting that quietly
 * un-cancels itself because an older mail was synced last.
 *
 * Seven rules, each of which exists because the obvious alternative is worse:
 *
 *   Identity is the uid, unique per calendar. For an invite that is the
 *   sender's own UID, verbatim — RFC 5546 settled this and re-deciding it
 *   makes plMail disagree with every other client about which update wins.
 *
 *   A later revision wins, by SEQUENCE, falling back to when the mail arrived.
 *   Out-of-order delivery is normal, so an older revision arriving after a
 *   newer one is filed rather than applied.
 *
 *   A superseded extraction is still recorded, with applied = false. That is
 *   what makes "why is this on my calendar?" answerable, and it is the
 *   difference between an audit trail and a guess.
 *
 *   Cancellation sets a status; it never deletes. Users want to see that the
 *   thing was called off, and deleting the row fights anyone who wants it
 *   back.
 *
 *   A user-edited event is never overwritten. A later mail may know more about
 *   the booking, but it does not know more than the person who corrected it.
 *
 *   Nor is an event this was never responsible for. Everything here revises
 *   *claims*; an event somebody typed, or accepted out of a sentence, is not
 *   one — EventSource::mayBeRewrittenByMail() is where that line is drawn, and
 *   the claim is still filed against the event so the audit trail survives.
 *
 *   A claim is only applied unasked when it comes from where the event came
 *   from. Everything above decides WHICH revision wins and says nothing about
 *   WHO may send one, and for a long time nobody was asked: an event is found
 *   by its UID, and a UID is not a secret — everyone invited holds it, and so
 *   does anyone a copy was forwarded to. Any of them could mail in a CANCEL
 *   or a REQUEST with a higher SEQUENCE and a different meeting link, and it
 *   was applied, with the reader's earlier "accepted" carried over on top
 *   (issue #34). mayApplyUnasked() is the rule and its docblock is the
 *   reasoning; a claim it turns down is HELD — filed against the event,
 *   marked, and offered on the message's card — never dropped, because the
 *   rule is a judgment about a sender and the reader may know better.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class EventReconciler
{
    public function __construct(
        private CalendarEventRepository        $events,
        private EventSourceLinkRepository      $links,
        private EventSuppressionRepository     $suppressions,
        private ExtractedEventCalendarResolver $calendarResolver,
        private InviteParticipationResolver    $participation,
        private CalendarEventWriter            $writer,
        private RecurrenceRuleConverter        $recurrence,
        private RecurrenceMaterialiser         $materialiser,
        private SenderAuthentication           $authentication,
        private EntityManagerInterface         $em,
        private LoggerInterface                $logger,
    ) {
    }

    /**
     * @param list<ExtractedEvent> $extracted
     *
     * @param bool $confirmed the reader has looked at this message's claim and
     *                        said to apply it — the "apply anyway" on a held
     *                        claim's card. It answers the one question
     *                        mayApplyUnasked() asks and no other: a confirmed
     *                        claim still loses to a newer revision, and still
     *                        does not touch an event the reader edited.
     *
     * @return list<CalendarEvent> the events this message created or changed
     */
    public function reconcile(Message $message, array $extracted, bool $confirmed = false): array
    {
        if ([] === $extracted) {
            return [];
        }

        $account  = $message->account;
        $user     = $account->usr;
        $calendar = $this->calendarResolver->resolve($account);

        if (false === $user instanceof User || null === $calendar) {
            return [];
        }

        $touched = [];

        // Series before their instances. A REQUEST carrying a master and its
        // exceptions lists them in whatever order the sender liked, and an
        // exception reconciled first — on a series this install has never seen
        // — would have no series to be filed on. usort is stable, so the order
        // within each half is the sender's.
        usort(
            $extracted,
            static fn (ExtractedEvent $a, ExtractedEvent $b): int => (null !== $a->recurrenceId) <=> (null !== $b->recurrenceId),
        );

        foreach ($extracted as $claim) {
            // Asked before anything is created: dismissing an event has to
            // survive re-extraction, or every backfill puts back the thing the
            // user just threw away.
            if (true === $this->suppressions->isSuppressed($user, $claim->dedupKey)) {
                continue;
            }

            // EVERY copy this user holds under the UID, not just the one on the
            // calendar extraction would file a new event to.
            //
            // This is the fix for a bug with no visible symptom. A user who
            // ticks a second calendar in the editor gets a second row under the
            // same UID — that is what a copy is — and the reconciler used to
            // look for the claim's subject on one calendar only. So a later
            // mail about the meeting updated whichever copy happened to be on
            // that calendar and silently left the others describing the old
            // time. The two copies then disagree, EventClusterer stops merging
            // them, and the calendar draws the same meeting twice at two
            // different hours: a duplicate that looks like a sync fault and is
            // really an update that only went half way.
            //
            // A UID is unique within a calendar and deliberately not across
            // them, so this is a list. Each copy is judged on its own — one may
            // be user-edited and refuse the update while its sibling takes it —
            // and that is the honest outcome, because the flags are per row.
            $existing = $this->copiesOf($user, $calendar, $claim->uid);

            if ([] === $existing) {
                $touched[] = $this->create($claim, $calendar, $user, $message, $confirmed);

                continue;
            }

            foreach ($existing as $copy) {
                // One instance of a series is a patch on it, never the series.
                // Applied as an update, "the 3rd is cancelled" cancelled the
                // whole meeting, and a moved instance moved every other one.
                // Only a copy that actually repeats takes the patch; an
                // instance-only invitation that became a one-off row of its own
                // is simply updated, as before.
                $event = null !== $claim->recurrenceId && true === $this->repeats($copy)
                    ? $this->updateInstance($copy, $claim, $message, $confirmed)
                    : $this->update($copy, $claim, $message, $confirmed);

                if (null !== $event) {
                    $touched[] = $event;
                }
            }
        }

        return $touched;
    }

    /**
     * Every row under one UID that this unit of work can see, committed or
     * merely queued.
     *
     * The queued half is not a nicety — see pendingByUid(): a resend and its
     * original land in the same batch routinely, and a claim that could not see
     * the insert it just scheduled would create a second row and lose the flush
     * to the unique constraint.
     *
     * @return list<CalendarEvent>
     */
    private function copiesOf(User $user, object $calendar, string $uid): array
    {
        $copies = $this->events->findByUidForUser($user, $uid);

        $pending = $this->pendingByUid($calendar, $uid);

        if (null !== $pending && false === in_array($pending, $copies, true)) {
            $copies[] = $pending;
        }

        return $copies;
    }

    private function create(
        ExtractedEvent $claim,
        object         $calendar,
        User           $user,
        Message        $message,
        bool           $confirmed,
    ): CalendarEvent {
        $event      = new CalendarEvent();
        $event->uid = $claim->uid;

        $this->apply($event, $claim, $calendar, $user, $message, $confirmed);
        $this->link($event, $claim, $message, applied: true);

        return $event;
    }

    private function update(CalendarEvent $event, ExtractedEvent $claim, Message $message, bool $confirmed): ?CalendarEvent
    {
        // Asked before isUserEdited, and not covered by it: that flag is only
        // ever set on an event that was extracted in the first place
        // (CalendarEventWriter::markUserEdited), so it says nothing about an
        // event a person made or accepted. Until this line the only thing
        // keeping a mail claim off a date the user accepted out of prose was
        // that CalendarEventWriter mints a UID no sender can collide with —
        // an accident of where UIDs come from, not a rule about who decides.
        if (false === $event->source->mayBeRewrittenByMail()) {
            $this->logger->info('EventReconciler: skipping update to an event a person decided on', [
                'eventId' => $event->id,
                'uid'     => $event->uid,
                'source'  => $event->source->value,
            ]);

            $this->link($event, $claim, $message, applied: false);

            return null;
        }

        if (true === $event->isUserEdited) {
            $this->logger->info('EventReconciler: skipping update to a user-edited event', [
                'eventId' => $event->id,
                'uid'     => $event->uid,
            ]);

            $this->link($event, $claim, $message, applied: false);

            return null;
        }

        if (false === $this->supersedes($event, $claim, $message)) {
            $this->link($event, $claim, $message, applied: false);

            return null;
        }

        // After the three guards above, deliberately. Those are outcomes
        // nobody is asked about — the reader's own edit stands, an older
        // revision stays filed — and holding first would put "apply anyway"
        // on a card for a claim that could not have been applied anyway.
        if (false === $confirmed && false === $this->mayApplyUnasked($event, $claim, $message)) {
            $this->hold($event, $claim, $message);

            return null;
        }

        $this->apply($event, $claim, $event->calendar, $event->usr, $message, $confirmed);
        $this->link($event, $claim, $message, applied: true);

        return $event;
    }

    /**
     * File one instance's revision onto the series it belongs to.
     *
     * The same three guards update() has, for the same reasons, with one
     * difference in the third: an instance carries its own SEQUENCE, which is
     * compared against the series' but never written back to it. A series at
     * sequence 2 whose third instance was moved at sequence 3 is still at 2,
     * and the next update to the series must not look stale beside it.
     *
     * A cancelled instance is excluded — the RFC 5546 meaning of a CANCEL
     * carrying a RECURRENCE-ID — and any other revision becomes a patch with
     * the instance's own start, length and title, keyed where the rule put the
     * instance, which is exactly what RecurrenceMaterialiser looks up.
     */
    private function updateInstance(CalendarEvent $event, ExtractedEvent $claim, Message $message, bool $confirmed): ?CalendarEvent
    {
        $recurrenceId = $claim->recurrenceId ?? throw new \LogicException('Not an instance claim.');

        if (
            false === $event->source->mayBeRewrittenByMail()
            || true === $event->isUserEdited
            || $claim->sequence < $event->sequence
        ) {
            $this->link($event, $claim, $message, applied: false, instance: true);

            return null;
        }

        // One instance moved or struck out is as much somebody else's meeting
        // changed as the whole series is. Same rule, same place in the order.
        if (false === $confirmed && false === $this->mayApplyUnasked($event, $claim, $message)) {
            $this->hold($event, $claim, $message, instance: true);

            return null;
        }

        $zone = $this->materialiser->zoneOf($event);
        $key  = $this->recurrence->overrideKey($recurrenceId, $zone);

        if (EventStatus::Cancelled === $claim->status) {
            $patch = ['excluded' => true];
        } else {
            $patch = [
                '@type'    => 'Event',
                'start'    => $claim->startsAt->setTimezone($zone)->format('Y-m-d\TH:i:s'),
                'duration' => $this->writer->isoDuration($claim->endsAt->getTimestamp() - $claim->startsAt->getTimestamp()),
            ];

            // Only when it differs, for the reason EventInstanceEditor gives: a
            // patch repeating the series' title reads as a rename, and a later
            // rename of the series would leave this instance behind.
            if (null !== $claim->title && $claim->title !== (string) $event->title) {
                $patch['title'] = $claim->title;
            }
        }

        $this->writer->overrideInstances($event, [$key => $patch]);
        $this->link($event, $claim, $message, applied: true, instance: true);

        return $event;
    }

    /** Whether this row is a series an instance can be filed on. */
    private function repeats(CalendarEvent $event): bool
    {
        $rules = $event->jscalendar['recurrenceRules'] ?? null;

        return (true === is_array($rules) && [] !== $rules)
            || true === is_string($event->jscalendar['plmail:rrule'] ?? null);
    }

    /**
     * SEQUENCE first, since that is what iCalendar revisions are for. Equal
     * sequences fall back to arrival time, which is the only ordering a
     * non-invite source gives us at all — a booking confirmation carries no
     * revision number, only a date.
     */
    private function supersedes(CalendarEvent $event, ExtractedEvent $claim, Message $message): bool
    {
        if ($claim->sequence > $event->sequence) {
            return true;
        }

        if ($claim->sequence < $event->sequence) {
            return false;
        }

        $arrived = $message->receivedAt ?? $message->sentAt;

        // An event this batch created has no id yet, so it cannot be bound as
        // a query parameter — and it has no committed links to find either.
        // Both facts say the same thing: there is nothing older to lose to.
        $latest = null === $event->id ? null : $this->links->latestAppliedAt($event);

        return null === $arrived || null === $latest || $arrived >= $latest;
    }

    /**
     * May this message change an event that already exists, without the
     * reader being asked?
     *
     * RFC 5546 §3.2 gives REQUEST and CANCEL to the organiser alone, and for
     * years this class read the method and never checked who was using it.
     *
     * The rule is about the SENDER OF THE MAIL, not the ORGANIZER line inside
     * the calendar file. That line is the attacker's to write: a forged update
     * names the real organiser, because the file it was copied from did.
     *
     *   No, if a believed server looked at the message and could not vouch
     *   for its From (SenderVerdict::Fail). Nothing below is worth asking
     *   about a From line that is itself not real — including "is it the
     *   reader's own address", which is exactly what a forger would write.
     *
     *   Yes, for the reader's own mail. A change they sent is in their Sent
     *   folder because they made it.
     *
     *   For an invitation: yes, when the sender is one this event's content
     *   has already come from, or is the organiser the event already names.
     *   "Already come from" rather than "is the organiser", because the two
     *   routinely differ and the first is the one that holds: a calendar
     *   service sends invitations from an address of its own, and it sends
     *   the updates from the same one. An event that came from a connected
     *   calendar has no such mail behind it, which is what the organiser half
     *   is for.
     *
     *   For anything else — a booking read out of markup — yes only on a
     *   Pass. Its identity is the issuer's domain plus a reservation number
     *   that is printed on boarding passes; the domain is all that stands
     *   between a stranger and somebody's flight, so it has to be real.
     *
     * What this does not catch, and cannot: a forged From on an account whose
     * server reports nothing (SenderVerdict::Unknown) is indistinguishable
     * from the genuine sender. Holding every such update would hold every
     * update a self-hosted mailbox receives, so there the address is taken at
     * its word — the same word the reader takes it at when they read the mail.
     */
    private function mayApplyUnasked(CalendarEvent $event, ExtractedEvent $claim, Message $message): bool
    {
        $verdict = $this->authentication->verdict($message);

        if (SenderVerdict::Fail === $verdict) {
            return false;
        }

        $sender = AddressHelper::email($message->fromAddress);

        if ('' === $sender) {
            return false;
        }

        if (true === $this->isOwn($sender, $message)) {
            return true;
        }

        if (IcsEventExtractor::NAME !== $claim->extractor) {
            return SenderVerdict::Pass === $verdict;
        }

        return true === in_array($sender, $this->knownSenders($event), true);
    }

    /**
     * Everyone this event's content may come from without asking: the senders
     * of the claims already applied to it, and the organiser it names.
     *
     * @return list<string> lowercased addresses
     */
    private function knownSenders(CalendarEvent $event): array
    {
        $known = $this->links->appliedSenders($event);

        // The half the repository cannot see: a link this unit of work has
        // queued. An invitation and its update land in one batch whenever a
        // mailbox is imported, and the update must recognise the invitation
        // that was read a moment before it.
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $queued) {
            if (true === $queued instanceof EventSourceLink
                && null !== $queued->event
                && $queued->event->uid === $event->uid
                && $queued->event->usr === $event->usr
                && true === $queued->applied) {
                $known[] = AddressHelper::email($queued->message?->fromAddress);
            }
        }

        foreach ($event->jscalendar['participants'] ?? [] as $participant) {
            if (true === is_array($participant) && true === ($participant['roles']['owner'] ?? false)) {
                $known[] = AddressHelper::email((string) ($participant['email'] ?? ''));
            }
        }

        return array_values(array_unique(array_filter($known, static fn (string $address): bool => '' !== $address)));
    }

    private function isOwn(string $sender, Message $message): bool
    {
        return true === in_array(
            $sender,
            array_map(static fn (string $owned): string => AddressHelper::email($owned), $message->account->ownedAddresses),
            true,
        );
    }

    /**
     * File a claim that was turned down by mayApplyUnasked(): against the
     * event, unapplied, and marked so the card can say why and offer to apply
     * it.
     */
    private function hold(CalendarEvent $event, ExtractedEvent $claim, Message $message, bool $instance = false): void
    {
        $this->logger->info('EventReconciler: holding a claim from a sender the event did not come from', [
            'eventId'   => $event->id,
            'uid'       => $event->uid,
            'messageId' => $message->id,
            'extractor' => $claim->extractor,
        ]);

        $this->link($event, $claim, $message, applied: false, instance: $instance, hold: EventSourceLink::HOLD_UNVERIFIED);
    }

    /**
     * A calendar file somebody else mailed in, naming nobody here.
     *
     * An appointment attached by a recruiter, a published calendar entry, an
     * invitation forwarded from somebody else's diary: none of them is an
     * invitation TO this mailbox, so resolve() says null, and null used to mean
     * "draw it". That made a calendar anybody could write to by sending mail.
     * It is held as unanswered instead — not drawn, with a card above the
     * message offering to add it — which is the state an invitation waits in,
     * minus the reply: there is nobody who asked.
     *
     * Three things are not this, and stay as they were:
     *
     *   the owner's own mail. A file they sent is in Sent for the reason they
     *   sent it, and asking them to accept it is absurd;
     *
     *   a booking read out of markup whose sender a believed server vouches
     *   for. It is confirmed by its own mail, and asking about every flight
     *   would be asking about the one kind of entry people want unasked;
     *
     *   an event that is ALREADY on the calendar with nothing recorded against
     *   it. It was drawn under the old rule, and re-reading stored mail must
     *   not take things off a calendar that somebody has been looking at.
     *
     * A booking whose sender is NOT vouched for is this, though, and that is
     * the other half of issue #34. Markup in a mail body is a claim by whoever
     * wrote the body, keyed on a From line nobody had checked, and it used to
     * be drawn at once: a stranger's "flight" on the calendar, with a title,
     * time and place of their choosing. It is held like the calendar file is.
     */
    private function isOfferedByMail(CalendarEvent $event, ExtractedEvent $claim, Message $message): bool
    {
        if (null !== $event->id && null === $event->myParticipation) {
            return false;
        }

        $owned   = $message->account->ownedAddresses;
        $sender  = AddressHelper::email($message->fromAddress);
        $verdict = $this->authentication->verdict($message);

        // Asked before "is it their own", for the reason mayApplyUnasked()
        // gives: on a From that failed, their own address is the forgery.
        if (SenderVerdict::Fail !== $verdict && true === $this->isOwn($sender, $message)) {
            return false;
        }

        if (IcsEventExtractor::NAME !== $claim->extractor) {
            return SenderVerdict::Pass !== $verdict;
        }

        return $this->participation->namesNobodyHere($claim->jscalendar, $owned);
    }

    private function apply(
        CalendarEvent  $event,
        ExtractedEvent $claim,
        object         $calendar,
        User           $user,
        Message        $message,
        bool           $confirmed = false,
    ): void {
        // Before write(), because write() materialises — and whether an
        // invitation is drawn at all is decided by this field. Setting it
        // afterwards would leave the occurrences reflecting the previous
        // answer until the next write, which for a new event means an
        // unanswered invitation sitting in the calendar exactly once.
        //
        // merge() is what stops an organiser's re-sent REQUEST un-accepting a
        // meeting: their attendee list is as they last saw it, and a stale
        // NEEDS-ACTION in it would take the chip away with no other symptom.
        // Read off the CLAIM's object rather than the event's: the event still
        // holds the previous revision until write() merges the overlay below,
        // and the answer being decided here is the one this message states.
        $incoming = $this->participation->resolve($claim->jscalendar, $message->account->ownedAddresses);

        // A confirmed claim is one the reader has already said yes to; it is
        // not offered to them a second time.
        if (null === $incoming && false === $confirmed && true === $this->isOfferedByMail($event, $claim, $message)) {
            $incoming = ParticipationStatus::NeedsAction;
        }

        $event->myParticipation = $this->participation->merge($event->myParticipation, $incoming);

        $this->writer->write(
            event:       $event,
            calendar:    $calendar,
            user:        $user,
            title:       $claim->title ?? 'Untitled',
            startsAt:    $claim->startsAt,
            endsAt:      $claim->endsAt,
            timeZone:    $claim->timeZone,
            isAllDay:    $claim->isAllDay,
            location:    $claim->location,
            description: $claim->jscalendar['description'] ?? null,
            status:      $claim->status,
            jscalendarOverlay: $claim->jscalendar,
        );

        $event->source     = $claim->source;
        $event->confidence = $claim->confidence;
        $event->kind       = $claim->kind;
        $event->sequence   = $claim->sequence;

        if (EventStatus::Cancelled === $claim->status) {
            // Kept, struck through, never deleted — see the class docblock.
            foreach ($event->occurrences as $occurrence) {
                $occurrence->cancelled = true;
            }
        }
    }

    /**
     * An event this unit of work has created but not yet flushed.
     *
     * findOneByUid() asks the database, which cannot see a queued INSERT — so
     * two messages in one batch carrying the same UID each found nothing, each
     * created an event, and the flush was rejected by the unique constraint on
     * (calendar_id, uid). A resend and its original land in the same batch
     * routinely: a backfill processes a whole mailbox at once, and an invite
     * is usually sent more than once.
     *
     * Read from the UnitOfWork rather than kept in a property here. The
     * scheduled set is the actual answer to "what will exist after the flush",
     * it is emptied by em->clear() between batches with nothing to remember to
     * reset, and this service stays stateless.
     */
    private function pendingByUid(object $calendar, string $uid): ?CalendarEvent
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $queued) {
            if (false === $queued instanceof CalendarEvent) {
                continue;
            }

            if ($queued->calendar === $calendar && $queued->uid === $uid) {
                return $queued;
            }
        }

        return null;
    }

    /**
     * One link per (event, message, extractor), so a message re-processed by a
     * backfill updates its own row instead of growing a second one.
     *
     * The queued half matters since a message can hold several claims about
     * one event — a master and its exceptions. findOneBy() cannot see the link
     * the master's claim queued a moment ago, and a second one for the same
     * triple is a flush the unique constraint refuses.
     *
     * An instance claim leaves an existing link alone: in a message that also
     * carried the series, that link is the series' claim, and it is the one
     * the invite card and "why is this here?" should read.
     */
    private function link(
        CalendarEvent  $event,
        ExtractedEvent $claim,
        Message        $message,
        bool           $applied,
        bool           $instance = false,
        ?string        $hold = null,
    ): void {
        $existing = $this->links->findOneBy([
            'event'     => $event,
            'message'   => $message,
            'extractor' => $claim->extractor,
        ]) ?? $this->pendingLink($event, $message, $claim->extractor);

        if (null !== $existing && true === $instance) {
            return;
        }

        $link = $existing ?? new EventSourceLink();

        $link->event       = $event;
        $link->message     = $message;
        $link->messagePart = $claim->part;
        $link->extractor   = $claim->extractor;
        $link->confidence  = $claim->confidence;
        $link->dedupKey    = $claim->dedupKey;
        $link->applied     = $applied;
        // Set on every write, null included: a held claim the reader then
        // applies goes through here again and must stop saying it is held.
        $link->holdReason  = $hold;
        $link->payload     = $claim->sourcePayload;

        $this->em->persist($link);
    }

    /** A link this unit of work has queued but not yet flushed — see link(). */
    private function pendingLink(CalendarEvent $event, Message $message, string $extractor): ?EventSourceLink
    {
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $queued) {
            if (
                true === $queued instanceof EventSourceLink
                && $queued->event === $event
                && $queued->message === $message
                && $queued->extractor === $extractor
            ) {
                return $queued;
            }
        }

        return null;
    }
}
