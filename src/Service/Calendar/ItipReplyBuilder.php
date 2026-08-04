<?php

declare(strict_types=1);

namespace App\Service\Calendar;

use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The mail an RSVP actually is: an iTIP REPLY, addressed to the organiser.
 *
 * An answer that only changed a row here would be an answer nobody receives.
 * RFC 5546 defines the exchange — the organiser sent METHOD:REQUEST, the
 * attendee sends back METHOD:REPLY carrying the same UID, the same SEQUENCE and
 * exactly one ATTENDEE line: their own, with a PARTSTAT. That is what every
 * other calendar reads to tick a name off, and it is why the reply echoes the
 * sender's UID rather than inventing an identity of its own.
 *
 * One ATTENDEE, deliberately. A reply listing the whole invitee list is a claim
 * to have answered on their behalf, and organisers that trust it will tick off
 * people who never responded.
 *
 * The reply is sent from the address the invitation was addressed to, not from
 * the account's default From. The organiser matches the reply to a row in their
 * attendee list by address, so a reply sent from an alias they have never heard
 * of is a reply they file as an unknown participant — or drop.
 */
final readonly class ItipReplyBuilder
{
    private const string PRODUCT_ID = '-//plMail//Calendar//EN';

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Null when there is nobody to reply to. An .ics with no ORGANIZER is a
     * calendar entry someone sent for information, and answering it would put
     * mail in front of a person who asked no question.
     */
    public function build(MessageInvite $invite, ParticipationStatus $status, ?string $fromName): ?Email
    {
        $organiser = $invite->organiser;
        $me        = $invite->me;

        if (null === $organiser || null === $me || false === $status->isAnswer()) {
            return null;
        }

        $event  = $invite->event;
        $title  = (string) ($event->title ?? '');
        $answer = $this->translator->trans($status->label());

        $email = new Email()
            ->from(new Address($me->email, $fromName ?? ''))
            ->to(new Address($organiser->email, $organiser->name ?? ''))
            ->subject(sprintf('%s: %s', $answer, $title))
            ->text($this->body($invite, $answer, $title));

        // Threaded against the invitation, so the organiser's client files the
        // answer under the conversation it belongs to rather than starting a
        // new one. Stored ids are bracket-less here (MessageIdHelper); the
        // header adds its own.
        $inReplyTo = $invite->message->messageId;

        if (null !== $inReplyTo && '' !== $inReplyTo) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
            $email->getHeaders()->addIdHeader('References', $inReplyTo);
        }

        $email->addPart($this->calendarPart($invite, $status));

        return $email;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The .ics, as a part carrying `method=REPLY`.
     *
     * The parameter is the whole point of the part and Symfony has no argument
     * for it, so it is pre-declared on the part's own headers: TextPart's
     * prepared headers set the Content-Type *body* on a header that already
     * exists rather than replacing the header, so parameters put there survive
     * into the wire format. Without it a strict organiser treats the attachment
     * as a plain calendar file and never processes the response.
     */
    private function calendarPart(MessageInvite $invite, ParticipationStatus $status): DataPart
    {
        $part = new DataPart($this->ics($invite, $status), 'invite.ics', 'text/calendar');

        $part->getHeaders()->addParameterizedHeader('Content-Type', 'text/calendar', [
            'method'  => 'REPLY',
            'charset' => 'utf-8',
        ]);

        return $part;
    }

    private function ics(MessageInvite $invite, ParticipationStatus $status): string
    {
        $event     = $invite->event;
        $organiser = $invite->organiser;
        $me        = $invite->me;

        $calendar = new VCalendar(['PRODID' => self::PRODUCT_ID]);
        $calendar->add('METHOD', 'REPLY');

        // Constructed rather than added by name, because Component::add()
        // hands back a Node and everything below this line needs a component.
        $vevent = new VEvent($calendar, 'VEVENT', [
            // The sender's identity, echoed. Re-deriving it here is how a reply
            // ends up matching nothing at the other end.
            'UID'      => $event->uid,
            'SEQUENCE' => $event->sequence,
            'DTSTAMP'  => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'SUMMARY'  => (string) ($event->title ?? ''),
        ]);

        $calendar->add($vevent);

        $this->addTimes($vevent, $invite);

        $vevent->add(
            'ORGANIZER',
            'mailto:' . $organiser->email,
            null !== $organiser->name ? ['CN' => $organiser->name] : [],
        );

        $parameters = ['PARTSTAT' => $status->partStat()];

        if (null !== $me->name) {
            $parameters['CN'] = $me->name;
        }

        $vevent->add('ATTENDEE', 'mailto:' . $me->email, $parameters);

        return $calendar->serialize();
    }

    /**
     * DTSTART and DTEND, echoed so an organiser can tell which instance was
     * answered.
     *
     * All-day events are written as DATE values by hand rather than handed to
     * the library as a DateTime: a date-time in a reply to an all-day event
     * shifts it by the zone offset at the far end, which is how a birthday
     * arrives on the wrong day.
     */
    private function addTimes(VEvent $vevent, MessageInvite $invite): void
    {
        $event = $invite->event;
        $utc   = new DateTimeZone('UTC');

        if (true === $event->isAllDay) {
            $vevent->add('DTSTART', $event->startsAt->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->endsAt->format('Ymd'), ['VALUE' => 'DATE']);

            return;
        }

        $vevent->add('DTSTART', $event->startsAt->setTimezone($utc));
        $vevent->add('DTEND', $event->endsAt->setTimezone($utc));
    }

    private function body(MessageInvite $invite, string $answer, string $title): string
    {
        $me    = $invite->me;
        $event = $invite->event;

        $zone = null !== $event->timeZone
            ? new DateTimeZone($event->timeZone)
            : new DateTimeZone('UTC');

        $when = true === $event->isAllDay
            ? $event->startsAt->format('D, j M Y')
            : $event->startsAt->setTimezone($zone)->format('D, j M Y H:i T');

        return sprintf(
            "%s\n\n%s\n%s\n",
            $this->translator->trans('calendar.invite.reply.body', [
                '%name%'   => $me->displayName(),
                '%status%' => mb_strtolower($answer),
            ]),
            $title,
            $when,
        );
    }
}
