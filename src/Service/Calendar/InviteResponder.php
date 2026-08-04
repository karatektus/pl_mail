<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Service\Mail\MailSenderRegistry;
use Psr\Log\LoggerInterface;

/**
 * Answering an invitation: what it changes here, and what it tells the sender.
 *
 * Both halves matter and they fail independently, which is the whole design of
 * this class. The local half — the participation status on the event — is the
 * one the user can see, and it must survive an organiser whose mail server is
 * down. The remote half is an iTIP REPLY, and a reply that could not be sent is
 * worth saying out loud rather than swallowing: an organiser who never heard
 * "no" will keep a seat for somebody who thinks they declined.
 *
 * So the status is written first and unconditionally, and the send is reported
 * back to the caller rather than thrown. The user is told; nothing is rolled
 * back.
 *
 * An RSVP does NOT mark the event user-edited. That flag stops a later message
 * from changing an event, and it exists for a person who corrected a wrong
 * extraction — but answering an invitation is not a correction. The organiser
 * is still the authority on when the meeting is, and a moved meeting must still
 * be allowed to move here after somebody accepted it.
 *
 * Does not flush — it joins the caller's unit of work.
 */
final readonly class InviteResponder
{
    public function __construct(
        private ItipReplyBuilder   $replies,
        private MailSenderRegistry $senders,
        private LoggerInterface    $logger,
    ) {
    }

    /**
     * @return bool whether the organiser was told; false means the status is
     *              recorded here and nowhere else
     */
    public function respond(MessageInvite $invite, ParticipationStatus $status): bool
    {
        $this->record($invite->event, $invite->me->email ?? '', $status);

        return $this->tell($invite, $status);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The answer, into the event's canonical object.
     *
     * Written straight onto $jscalendar rather than through
     * CalendarEventWriter: the writer rebuilds the object from the columns, and
     * participation status is not one of them — routing this through it would
     * mean projecting a field nothing queries into a column nothing reads, for
     * one write. The columns are untouched by an RSVP, so there is nothing here
     * for the two to disagree about.
     *
     * The whole array is reassigned because Doctrine compares a JSON column by
     * value against the version it hydrated: mutating the nested array in place
     * leaves both sides of that comparison pointing at the same data, and the
     * change is never written.
     */
    private function record(CalendarEvent $event, string $address, ParticipationStatus $status): void
    {
        if ('' === $address) {
            return;
        }

        $jscalendar   = $event->jscalendar;
        $participants = $jscalendar['participants'] ?? [];

        if (false === is_array($participants)) {
            $participants = [];
        }

        $key = $this->keyFor($participants, $address);

        $participant = $participants[$key] ?? [
            '@type' => 'Participant',
            'email' => $address,
            'roles' => ['attendee' => true],
        ];

        if (false === is_array($participant)) {
            return;
        }

        $participant['participationStatus'] = $status->value;

        $participants[$key]         = $participant;
        $jscalendar['participants'] = $participants;
        $event->jscalendar          = $jscalendar;
    }

    /**
     * The key this participant already lives under, or the one to create.
     *
     * RFC 8984 says the key is opaque, so an event imported from elsewhere may
     * key its participants by anything at all. Matching on the address inside
     * the entry is what stops an RSVP writing a second row for a person who is
     * already on the invitation.
     *
     * @param array<mixed> $participants
     */
    private function keyFor(array $participants, string $address): string
    {
        $needle = mb_strtolower($address);

        foreach ($participants as $key => $entry) {
            if (false === is_array($entry)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($entry['email'] ?? $key))) === $needle) {
                return (string) $key;
            }
        }

        return $needle;
    }

    /**
     * Send the reply, and never let a send failure become the user's problem to
     * decode. Refused, throttled, misconfigured — the answer to all three is
     * the same at this level: it did not go, and the caller says so.
     *
     * No Sent copy is filed for an account that does not file its own. Gmail
     * and Graph put the reply in Sent because their senders always do; an SMTP
     * account will not see it there. Appending it means going through
     * MessageSendService, which works on a persisted draft Message with parts
     * on disk — a lot of machinery to file protocol traffic addressed to a
     * calendar agent rather than correspondence anyone will read.
     */
    private function tell(MessageInvite $invite, ParticipationStatus $status): bool
    {
        $account = $invite->message->account;

        $email = $this->replies->build($invite, $status, $account->name);

        if (null === $email) {
            // No organiser to answer. Not a failure — there was never a
            // question — so the user is not told a send failed.
            return true;
        }

        try {
            return $this->senders->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->error('InviteResponder: could not send the invitation reply', [
                'accountId' => $account->id,
                'eventId'   => $invite->event->id,
                'status'    => $status->value,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }
}
