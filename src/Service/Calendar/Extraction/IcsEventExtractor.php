<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Helper\CharsetHelper;
use App\Domain\Interface\EventExtractorInterface;
use App\Entity\Mail\MessagePart;
use App\Service\Calendar\RecurrenceRuleConverter;
use App\Service\Graph\GraphMessageBuilder;
use App\Service\Mail\AttachmentResolver;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\Exception\ORMException;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property\ICalendar\DateTime as ICalDateTime;
use Sabre\VObject\Reader;

/**
 * Events from a real calendar invite.
 *
 * The most trustworthy source there is and the only one that is not a guess:
 * the sender's own UID, their own SEQUENCE, their own cancellation. RFC 5546
 * settled what identity and revision mean for calendar mail decades ago, and
 * following it is what makes plMail agree with every other client about which
 * update supersedes which.
 *
 * That is why this stops the cascade. There is nothing a structured-data
 * mapper or a model can add to an invite that the invite does not already say.
 *
 * Three shapes reach here, and the third is why rawMime exists:
 *
 *   IMAP stores a text/calendar MessagePart already, with bytes on disk.
 *   Gmail does too, since the ingest fix — inline or as a lazy stub.
 *   Graph has no part at all, because a text/calendar section inside
 *   multipart/alternative is not an attachment in its object model. All it
 *   gives is meetingMessageType on the message, so the invite is read out of
 *   the raw MIME instead — one fetch, cached to disk, only for messages that
 *   flag themselves.
 *
 * A recurring invitation is converted, not merely kept. The RRULE used to be
 * stashed verbatim under `plmail:rrule` because RRULE-to-JSCalendar did not
 * exist, and RecurrenceMaterialiser reads recurrenceRules and nothing else — so
 * somebody's weekly meeting arrived by mail and appeared on the calendar once.
 * The stash is now only what a rule that could not be converted falls back to;
 * see RecurrenceRuleConverter for which rules those are and why refusing one
 * outright beats converting most of it.
 */
final readonly class IcsEventExtractor implements EventExtractorInterface
{
    /**
     * What this extractor calls itself on an EventSourceLink.
     *
     * A constant because the name is no longer written only here: the invite
     * card finds its event by asking for the link this extractor left, and a
     * string typed twice is a card that silently stops appearing the day
     * somebody renames one of them.
     */
    public const string NAME = 'ics';

    /**
     * A VEVENT with neither an end nor a duration is an instant, and iCalendar
     * says to treat a date-time one as zero-length. A zero-length row is
     * invisible in every view, so it gets a nominal hour instead — an invite
     * that says nothing about its length is not an invite to nothing.
     */
    private const int DEFAULT_DURATION_MINUTES = 60;

    public function __construct(
        private AttachmentResolver      $attachments,
        private RecurrenceRuleConverter $recurrence,
        private LoggerInterface         $logger,
    ) {
    }

    public function priority(): int
    {
        return 100;
    }

    public function stopsCascade(): bool
    {
        return true;
    }

    public function supports(ExtractionContext $context): bool
    {
        return [] !== $context->calendarParts || $this->looksLikeAGraphInvite($context);
    }

    /**
     * @return list<ExtractedEvent>
     */
    public function extract(ExtractionContext $context): array
    {
        $events = [];

        foreach ($context->calendarParts as $part) {
            $bytes = $this->bytesOf($part);

            if (null !== $bytes) {
                foreach ($this->parse($bytes, $part) as $event) {
                    $events[] = $event;
                }
            }
        }

        // Only when the parts yielded nothing: on IMAP and Gmail the part is
        // the same invite the MIME holds, and fetching raw bytes to find it
        // twice would be an API call for a duplicate.
        if ([] === $events && true === $this->looksLikeAGraphInvite($context)) {
            $raw = $context->rawMime();

            if (null !== $raw && '' !== $raw) {
                foreach ($this->calendarSectionsOf($raw) as $ics) {
                    foreach ($this->parse($ics, null) as $event) {
                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @return list<ExtractedEvent>
     */
    private function parse(string $ics, ?MessagePart $part): array
    {
        // Both routes in reach here holding bytes nobody converted: a stored
        // MessagePart is the attachment exactly as it arrived, and the raw-MIME
        // fallback cuts a VCALENDAR out of the message without decoding one.
        // RFC 5545 says UTF-8 and sabre reads it as UTF-8, but senders still
        // emit latin-1 under a `charset=` parameter MessagePart does not keep —
        // and a SUMMARY carrying one 0xE4 is not mojibake, it is an INSERT
        // Postgres rejects, taking the whole extraction with it.
        $ics = CharsetHelper::ensureUtf8($ics);

        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (\Throwable $e) {
            // Malformed calendar data is routine — senders emit all sorts —
            // and it costs an event, never the message.
            $this->logger->info('IcsEventExtractor: unreadable calendar data', [
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }

        if (false === $calendar instanceof VCalendar) {
            return [];
        }

        // METHOD lives on the VCALENDAR, not the part's Content-Type. The
        // transport parameter says the same thing, but MessagePart stores the
        // bare MIME type without parameters — and widening that column would
        // break every exact comparison against it.
        $method = mb_strtoupper(trim((string) ($calendar->METHOD ?? '')));

        $events = [];

        foreach ($calendar->VEVENT ?? [] as $vevent) {
            $event = $this->toExtractedEvent($vevent, $method, $part, $ics);

            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function toExtractedEvent(
        VEvent  $vevent,
        string  $method,
        ?MessagePart $part,
        string  $ics,
    ): ?ExtractedEvent {
        $uid = trim((string) ($vevent->UID ?? ''));

        if ('' === $uid) {
            // Without a UID there is no identity, so a later update could not
            // find this again and every resend would be a new event.
            return null;
        }

        $start = $this->dateTimeOf($vevent, 'DTSTART');

        if (null === $start) {
            return null;
        }

        $isAllDay = $this->isAllDay($vevent);
        $end      = $this->endOf($vevent, $start);

        $status = match (true) {
            'CANCEL' === $method                                         => EventStatus::Cancelled,
            'CANCELLED' === mb_strtoupper((string) ($vevent->STATUS ?? '')) => EventStatus::Cancelled,
            'TENTATIVE' === mb_strtoupper((string) ($vevent->STATUS ?? '')) => EventStatus::Tentative,
            default                                                      => EventStatus::Confirmed,
        };

        $title    = trim((string) ($vevent->SUMMARY ?? ''));
        $location = trim((string) ($vevent->LOCATION ?? ''));
        $zone     = $this->zoneOf($vevent);

        return new ExtractedEvent(
            uid:           $uid,
            // The UID is the identity, so it is also the dedup key. Every
            // other extractor has to invent one; this one is given it.
            dedupKey:      'ics:' . $uid,
            jscalendar:    $this->toJsCalendar($vevent, $uid, $start, $end, $zone, $isAllDay, $status),
            startsAt:      $start,
            endsAt:        $end,
            extractor:     self::NAME,
            source:        EventSource::Ics,
            confidence:    100,
            title:         '' !== $title ? $title : null,
            location:      '' !== $location ? $location : null,
            timeZone:      $isAllDay ? null : $zone,
            isAllDay:      $isAllDay,
            status:        $status,
            kind:          ExtractionKind::Meeting,
            sequence:      (int) ($vevent->SEQUENCE?->getValue() ?? 0),
            part:          $part,
            sourcePayload: [
                'method'       => $method,
                'uid'          => $uid,
                'sequence'     => (int) ($vevent->SEQUENCE?->getValue() ?? 0),
                'recurrenceId' => (string) ($vevent->{'RECURRENCE-ID'} ?? ''),
                // Verbatim, so a better mapper can be replayed over it without
                // the mail server ever being touched again.
                'ics'          => $ics,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function toJsCalendar(
        VEvent            $vevent,
        string            $uid,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string           $zone,
        bool              $isAllDay,
        EventStatus       $status,
    ): array {
        $local = $start->setTimezone(new DateTimeZone($zone ?? 'UTC'));

        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $uid,
            'title'    => trim((string) ($vevent->SUMMARY ?? '')),
            'start'    => $local->format('Y-m-d\TH:i:s'),
            'duration' => $this->isoDuration($end->getTimestamp() - $start->getTimestamp()),
            'status'   => $status->value,
        ];

        if (null !== $zone && false === $isAllDay) {
            $jscalendar['timeZone'] = $zone;
        }

        if (true === $isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        $description = trim((string) ($vevent->DESCRIPTION ?? ''));

        if ('' !== $description) {
            $jscalendar['description'] = $description;
        }

        $location = trim((string) ($vevent->LOCATION ?? ''));

        if ('' !== $location) {
            $jscalendar['locations'] = ['1' => ['@type' => 'Location', 'name' => $location]];
        }

        $participants = $this->participantsOf($vevent);

        if ([] !== $participants) {
            $jscalendar['participants'] = $participants;
        }

        $rrules = $vevent->select('RRULE');

        if ([] !== $rrules) {
            $verbatim = (string) reset($rrules);
            $rule     = $this->recurrence->fromRrule($verbatim, $zone);

            if (null !== $rule) {
                $jscalendar['recurrenceRules'] = [$rule];
            } else {
                // Kept as the sender wrote it, and only when it could not be
                // converted. A rule that half-converts produces an event on the
                // wrong days, which is worse than one that does not recur — but
                // losing it entirely would also lose it on the way back out, and
                // CalDavEventConverter writes this key through unchanged so a
                // round trip cannot un-repeat somebody's standing meeting.
                $jscalendar['plmail:rrule'] = $verbatim;
            }

            $excluded = $this->recurrence->exclusionOverrides(
                $this->exclusionsOf($vevent),
                new DateTimeZone($zone ?? 'UTC'),
            );

            if ([] !== $excluded) {
                $jscalendar['recurrenceOverrides'] = $excluded;
            }
        }

        return $jscalendar;
    }

    /**
     * The instances an EXDATE takes off the series.
     *
     * Only read beside an RRULE, because an EXDATE without one excludes nothing.
     * The property is multi-valued and may appear several times, each occurrence
     * with its own TZID, which is why sabre is asked for the instants rather than
     * the strings — an EXDATE in Europe/Berlin compared against a UTC expansion
     * misses by an hour and takes the wrong instance off, or none.
     *
     * @return list<DateTimeImmutable>
     */
    private function exclusionsOf(VEvent $vevent): array
    {
        $instants = [];

        foreach ($vevent->select('EXDATE') as $exdate) {
            if (false === $exdate instanceof ICalDateTime) {
                continue;
            }

            foreach ($exdate->getDateTimes() as $dateTime) {
                $instants[] = DateTimeImmutable::createFromInterface($dateTime);
            }
        }

        return $instants;
    }

    /**
     * Organiser and attendees, with their response where they gave one — this
     * is what an RSVP view needs and what an .ics export has to put back.
     *
     * Roles are MERGED onto whoever already holds the address, never assigned
     * over the top of them. One person is routinely both: an organiser who is
     * also going appears as ORGANIZER and again as ATTENDEE, which is what
     * Google Calendar sends and what RFC 5545 expects. Keyed by address and
     * written in property order, the second line replaced the first — so the
     * only participant carrying `owner` lost it, the event ended up with no
     * organiser at all, and the invite card had nobody to answer and so offered
     * no answer. The address is the identity here; the roles accumulate.
     *
     * @return array<string,array<string,mixed>>
     */
    private function participantsOf(VEvent $vevent): array
    {
        $participants = [];

        foreach (['ORGANIZER' => 'owner', 'ATTENDEE' => 'attendee'] as $property => $role) {
            foreach ($vevent->{$property} ?? [] as $entry) {
                $address = preg_replace('/^mailto:/i', '', trim((string) $entry));

                if (null === $address || '' === $address) {
                    continue;
                }

                $key = mb_strtolower($address);

                $participant = $participants[$key] ?? [
                    '@type' => 'Participant',
                    'email' => $address,
                    'roles' => [],
                ];

                $participant['roles'][$role] = true;

                $name = trim((string) ($entry['CN'] ?? ''));

                if ('' !== $name) {
                    $participant['name'] = $name;
                }

                // An ORGANIZER line carries no PARTSTAT, so the attendee line
                // for the same person is where the organiser's own answer
                // comes from. Only ever written when the line has one, so a
                // second mention cannot blank an answer already read.
                $status = mb_strtolower(trim((string) ($entry['PARTSTAT'] ?? '')));

                if ('' !== $status) {
                    $participant['participationStatus'] = $status;
                }

                $participants[$key] = $participant;
            }
        }

        return $participants;
    }

    private function bytesOf(MessagePart $part): ?string
    {
        try {
            $path = $this->attachments->absolutePathFor($part);
        } catch (ORMException | DBALException $e) {
            // Not this part's fault, and not survivable: the resolver flushes
            // to cache the path it just materialised, so a database failure
            // arrives here wearing the part's clothes. Reported as "calendar
            // part unavailable" it read as a bad attachment, when what had
            // actually happened was a rejected write that closed the manager
            // and doomed everything after it.
            throw $e;
        } catch (\Throwable $e) {
            // A part that genuinely cannot be read — expired provider id,
            // missing file, revoked account. One missed event, nothing more.
            $this->logger->info('IcsEventExtractor: calendar part unavailable', [
                'partId'    => $part->id,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }

        if ('' === $path || false === is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);

        return false === $bytes ? null : $bytes;
    }

    /**
     * Graph says so on the message rather than in a header — see
     * GraphMessageBuilder. The content-class and x-microsoft-cdo-* headers are
     * the same signal from an Exchange server that came in over SMTP.
     */
    private function looksLikeAGraphInvite(ExtractionContext $context): bool
    {
        if (null !== $context->header(GraphMessageBuilder::MEETING_TYPE_HEADER)) {
            return true;
        }

        if (str_contains(
            mb_strtolower((string) $context->header('content-class')),
            'calendarmessage',
        )) {
            return true;
        }

        foreach (array_keys($context->headers) as $name) {
            if (str_starts_with((string) $name, 'x-microsoft-cdo-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the text/calendar sections out of raw MIME without a full parse.
     *
     * A VCALENDAR is self-delimiting, so the envelope does not have to be
     * understood to find one — and the alternative is decoding an entire
     * message tree to reach a section we already know the shape of. Handles
     * base64, which is how most senders encode it.
     *
     * @return list<string>
     */
    private function calendarSectionsOf(string $raw): array
    {
        $sections = [];

        if (0 < preg_match_all('/BEGIN:VCALENDAR.*?END:VCALENDAR/is', $raw, $matches)) {
            foreach ($matches[0] as $match) {
                $sections[] = $this->unfold($match);
            }
        }

        if ([] !== $sections) {
            return $sections;
        }

        // Nothing in the clear: look for a base64 body that decodes to one.
        foreach (preg_split('/\r?\n\r?\n/', $raw) ?: [] as $chunk) {
            $candidate = preg_replace('/\s+/', '', $chunk);

            if (null === $candidate || strlen($candidate) < 32) {
                continue;
            }

            if (1 !== preg_match('#^[A-Za-z0-9+/=]+$#', $candidate)) {
                continue;
            }

            $decoded = base64_decode($candidate, true);

            if (false !== $decoded && str_contains($decoded, 'BEGIN:VCALENDAR')) {
                $sections[] = $this->unfold($decoded);
            }
        }

        return $sections;
    }

    /** iCalendar folds long lines with CRLF + a space; sabre wants them whole. */
    private function unfold(string $ics): string
    {
        return (string) preg_replace("/\r?\n[ \t]/", '', $ics);
    }

    private function dateTimeOf(VEvent $vevent, string $property): ?DateTimeImmutable
    {
        $value = $vevent->{$property} ?? null;

        if (null === $value) {
            return null;
        }

        try {
            return DateTimeImmutable::createFromInterface($value->getDateTime())
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function endOf(VEvent $vevent, DateTimeImmutable $start): DateTimeImmutable
    {
        $end = $this->dateTimeOf($vevent, 'DTEND');

        if (null !== $end && $end > $start) {
            return $end;
        }

        $durations = $vevent->select('DURATION');

        if ([] !== $durations) {
            try {
                return $start->add(
                    \Sabre\VObject\DateTimeParser::parseDuration((string) reset($durations)),
                );
            } catch (\Throwable) {
                // fall through
            }
        }

        return $start->modify(sprintf('+%d minutes', self::DEFAULT_DURATION_MINUTES));
    }

    /** A DATE value rather than a DATE-TIME is what makes an event all-day. */
    private function isAllDay(VEvent $vevent): bool
    {
        $start = $vevent->DTSTART ?? null;

        return null !== $start && 'DATE' === mb_strtoupper((string) ($start['VALUE'] ?? ''));
    }

    private function zoneOf(VEvent $vevent): ?string
    {
        $tzid = (string) ($vevent->DTSTART['TZID'] ?? '');

        if ('' === $tzid) {
            return null;
        }

        try {
            return (new DateTimeZone($tzid))->getName();
        } catch (\Exception) {
            return null;
        }
    }

    private function isoDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if (0 === $seconds) {
            return 'PT0S';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest    = $seconds % 60;

        $time = (0 < $hours ? $hours . 'H' : '')
            . (0 < $minutes ? $minutes . 'M' : '')
            . (0 < $rest ? $rest . 'S' : '');

        return 'P' . (0 < $days ? $days . 'D' : '') . ('' === $time ? '' : 'T' . $time);
    }
}
