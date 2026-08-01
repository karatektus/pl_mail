<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Interface\EventExtractorInterface;
use App\Service\Calendar\Extraction\StructuredData\JsonLdReader;
use App\Service\Calendar\Extraction\StructuredData\MappedEvent;
use App\Service\Calendar\Extraction\StructuredData\Node;
use App\Service\Calendar\Extraction\StructuredData\StructuredDataMapperInterface;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Events from the schema.org markup a booking confirmation already carries.
 *
 * Google created email markup for exactly this — flights, parcels, hotels,
 * tickets — and it is why Gmail can tell you what is happening this week
 * without a model anywhere near it. The sender has already parsed their own
 * booking into structured fields; reading them is arithmetic, not inference,
 * which is what makes this deterministic enough to write to a calendar
 * unsupervised.
 *
 * Priority 80, below the invite and above anything that guesses. It does NOT
 * stop the cascade: one message routinely carries several unrelated things — an
 * order with three parcels, a flight out and a flight back — and a sender
 * parser written later for one airline may know something this does not about
 * a booking this one has already seen. Every claim is keyed separately, so the
 * runner settles only real collisions.
 *
 * Confidence 90 rather than the invite's 100. The fields are exact but their
 * meaning is not always: an itinerary is several objects with one reservation
 * number, a delivery estimate is a promise rather than a fact, and the sender
 * chose which of half a dozen legal shapes to emit. That is a different kind of
 * certainty from a UID and a SEQUENCE.
 *
 * The input is Message::$bodyHtml, unsanitised and untouched — the script tag
 * this reads is stripped from bodyHtmlSafe, quite correctly, which is why the
 * two copies exist and why BodyHtmlPreservesStructuredDataTest pins it. It is
 * also attacker-influenced: a body is written by whoever sent the mail, so
 * nothing here trusts a field to exist, to be the type it should be, or to be
 * a sane size.
 *
 * There is no sequence. These sources carry no revision number, and inventing
 * one — a counter, a hash of the payload — would make an arbitrary ordering
 * look authoritative to the reconciler. Left at 0, the reconciler falls back to
 * when the mail arrived, which for a booking confirmation followed by a change
 * notice is exactly right.
 */
final readonly class StructuredDataEventExtractor implements EventExtractorInterface
{
    /** Exact fields, inexact meanings. See the class docblock. */
    private const int CONFIDENCE = 90;

    /**
     * A message claiming more events than this is an attack or a template bug,
     * not an itinerary. The runner would happily reconcile all of them.
     */
    private const int MAX_EVENTS = 25;

    /** Titles and locations are projected into list views; a description is not. */
    private const int MAX_TITLE       = 200;
    private const int MAX_LOCATION    = 300;
    private const int MAX_DESCRIPTION = 2000;

    /**
     * @param iterable<StructuredDataMapperInterface> $mappers
     */
    public function __construct(
        private JsonLdReader    $reader,
        private LoggerInterface $logger,
        #[AutowireIterator('app.structured_data_mapper')]
        private iterable        $mappers,
    ) {
    }

    public function priority(): int
    {
        return 80;
    }

    public function stopsCascade(): bool
    {
        return false;
    }

    /**
     * A substring test, because this runs on every message and parsing the
     * body would not. "ld+json" cannot appear in a body that has no JSON-LD
     * script tag without someone having written it on purpose.
     */
    public function supports(ExtractionContext $context): bool
    {
        $html = $context->bodyHtml;

        return null !== $html && '' !== $html && false !== stripos($html, 'ld+json');
    }

    /**
     * @return list<ExtractedEvent>
     */
    public function extract(ExtractionContext $context): array
    {
        $html = $context->bodyHtml;

        if (null === $html) {
            return [];
        }

        $issuer = $this->issuerDomain($context->fromAddress);
        $byType = $this->mappersByType();
        $events = [];

        foreach ($this->reader->read($html) as $node) {
            $mapper = $byType[mb_strtolower($node->type())] ?? null;

            if (null === $mapper) {
                // The overwhelming majority: EmailMessage wrappers, Organization
                // footers, BreadcrumbLists. Not worth a log line each.
                continue;
            }

            foreach ($this->mapped($mapper, $node) as $mapped) {
                $event = $this->toExtractedEvent($mapped, $node, $issuer);

                if (null !== $event) {
                    $events[] = $event;
                }

                if (self::MAX_EVENTS <= count($events)) {
                    $this->logger->info('StructuredDataEventExtractor: event cap reached', [
                        'from'  => $context->fromAddress,
                        'limit' => self::MAX_EVENTS,
                    ]);

                    return $events;
                }
            }
        }

        return $events;
    }

    /**
     * One mapper throwing must not cost the other reservations in the same
     * message. The runner catches per extractor, which is a message at a time;
     * this is the same protection a node at a time.
     *
     * @return list<MappedEvent>
     */
    private function mapped(StructuredDataMapperInterface $mapper, Node $node): array
    {
        try {
            return $mapper->map($node);
        } catch (\Throwable $e) {
            $this->logger->info('StructuredDataEventExtractor: mapper failed', [
                'mapper' => $mapper::class,
                'type'   => $node->type(),
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function toExtractedEvent(MappedEvent $mapped, Node $node, string $issuer): ?ExtractedEvent
    {
        $identity = $this->normalised($mapped->identity);

        if ('' === $identity) {
            // Without something to recognise the booking by, every resend of
            // the same confirmation would be a new event and no change notice
            // could ever find the one it is about.
            return null;
        }

        // Separated by a NUL, which cannot occur in any of the three parts, so
        // ("AB", "1234") and ("AB1", "234") are not the same booking. The issuer
        // domain is in the key because a reservation number is six characters
        // and unique only to the company that issued it.
        $hash = hash('sha256', implode("\0", [$issuer, $mapped->type, $identity]));

        $status   = $this->statusOf($node);
        $title    = $this->clamp($mapped->title, self::MAX_TITLE);
        $location = $this->clamp($mapped->location, self::MAX_LOCATION);

        // A backstop, not the rule: the mappers each apply their own nominal
        // length. A zero-length or inverted event is invisible in every view.
        $endsAt = $mapped->endsAt > $mapped->startsAt
            ? $mapped->endsAt
            : $mapped->startsAt->modify(true === $mapped->isAllDay ? '+1 day' : '+1 hour');

        return new ExtractedEvent(
            uid:           $hash . '@plmail',
            dedupKey:      'jsonld:' . $hash,
            jscalendar:    $this->toJsCalendar($hash, $mapped, $endsAt, $title, $location, $status),
            startsAt:      $mapped->startsAt,
            endsAt:        $endsAt,
            extractor:     'structuredData',
            source:        EventSource::StructuredData,
            confidence:    self::CONFIDENCE,
            title:         $title,
            location:      $location,
            // A schema.org timestamp carries an offset, not a zone, and a zone
            // cannot be recovered from one: -05:00 is Chicago in summer and
            // Bogotá all year. The instant is exact either way, so the event is
            // stored as UTC and rendered in the user's own zone rather than
            // asserting a zone nobody stated.
            timeZone:      null,
            isAllDay:      $mapped->isAllDay,
            status:        $status,
            kind:          $mapped->kind,
            // No revision number exists in this vocabulary — see the class
            // docblock. The reconciler falls back to arrival time.
            sequence:      0,
            part:          null,
            sourcePayload: [
                'type'     => $mapped->type,
                'identity' => $identity,
                'issuer'   => $issuer,
                // Verbatim, so an improved mapper can be replayed by
                // `app:backfill events` without touching a mail server — and so
                // that a re-keying backfill can recompute the hash above from
                // the row alone.
                'jsonld'   => $node->data,
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function toJsCalendar(
        string            $hash,
        MappedEvent       $mapped,
        DateTimeImmutable $endsAt,
        ?string           $title,
        ?string           $location,
        EventStatus       $status,
    ): array {
        $jscalendar = [
            '@type'    => 'Event',
            'uid'      => $hash . '@plmail',
            'title'    => $title ?? '',
            // JSCalendar times are LocalDateTime — no offset, no trailing Z.
            // UTC because that is the zone these are stored in; see timeZone
            // in toExtractedEvent().
            'start'    => $mapped->startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'),
            'duration' => $this->isoDuration($endsAt->getTimestamp() - $mapped->startsAt->getTimestamp()),
            'status'   => $status->value,
        ];

        if (true === $mapped->isAllDay) {
            $jscalendar['showWithoutTime'] = true;
        }

        $description = $this->clamp($mapped->description, self::MAX_DESCRIPTION);

        if (null !== $description) {
            $jscalendar['description'] = $description;
        }

        if (null !== $location) {
            $jscalendar['locations'] = ['1' => ['@type' => 'Location', 'name' => $location]];
        }

        return $jscalendar;
    }

    /**
     * schema.org writes an enumeration either as a bare term or as its URL, and
     * senders use both. OrderCancelled is included with ReservationCancelled
     * because a cancelled order's parcel is not arriving either.
     *
     * Pending and Hold become Tentative rather than Confirmed: a table held
     * pending confirmation is genuinely uncertain, and that is what the status
     * is for.
     */
    private function statusOf(Node $node): EventStatus
    {
        $raw = $node->string('reservationStatus')
            ?? $node->string('orderStatus')
            ?? $node->child('reservationStatus')?->string('name')
            ?? $node->child('orderStatus')?->string('name');

        if (null === $raw) {
            return EventStatus::Confirmed;
        }

        return match (mb_strtolower($node->term($raw))) {
            'reservationcancelled', 'ordercancelled' => EventStatus::Cancelled,
            'reservationpending', 'reservationhold'  => EventStatus::Tentative,
            default                                  => EventStatus::Confirmed,
        };
    }

    /**
     * The sender's registrable domain, near enough.
     *
     * Retailers and airlines send from bulk-mail subdomains that change without
     * warning — delta.com one month, mail.delta.com the next — and scoping
     * the key to the full host would make every such change orphan the events
     * already keyed under the old one. Two labels, or three when the second is
     * one of the common second-level suffixes, gets that right for everything
     * short of the long tail; the correct answer needs the public suffix list,
     * which is a dependency and a data file that goes stale.
     */
    private function issuerDomain(string $address): string
    {
        $at = strrpos($address, '@');
        $host = mb_strtolower(trim(false === $at ? $address : substr($address, $at + 1)));

        if ('' === $host) {
            return '';
        }

        $labels = explode('.', $host);

        if (3 > count($labels)) {
            return $host;
        }

        $secondLevel = $labels[count($labels) - 2] ?? '';
        $depth       = in_array($secondLevel, ['co', 'com', 'org', 'net', 'ac', 'gov', 'edu'], true) ? 3 : 2;

        return implode('.', array_slice($labels, -$depth));
    }

    /**
     * Case and spacing are the sender's formatting, not part of the booking:
     * "ab 1234" in the confirmation and "AB1234" in the change notice is one
     * reservation, and keyed literally it would be two.
     */
    private function normalised(string $identity): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($identity)));
    }

    private function clamp(?string $value, int $limit): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : mb_substr($value, 0, $limit);
    }

    /**
     * Built per call rather than in the constructor, so that a message with no
     * markup at all — which is most of them — instantiates no mappers. The
     * tagged iterator stays lazy right up until something is actually found.
     *
     * A type claimed twice keeps the first mapper. Two mappers for one type is
     * a mistake to notice, not a fallback chain to run.
     *
     * @return array<string,StructuredDataMapperInterface>
     */
    private function mappersByType(): array
    {
        $byType = [];

        foreach ($this->mappers as $mapper) {
            foreach ($mapper->types() as $type) {
                $key = mb_strtolower(trim($type));

                if ('' !== $key) {
                    $byType[$key] ??= $mapper;
                }
            }
        }

        return $byType;
    }

    /** ISO 8601 duration, which is how JSCalendar says how long something is. */
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
