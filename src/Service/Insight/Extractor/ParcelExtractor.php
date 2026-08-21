<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\InsightDraft;
use App\Service\Insight\InsightExtractorInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Parcels: tracking numbers read out of carrier and shop mail, deterministically.
 *
 * Two gates, because tracking numbers are the least distinctive things regexes
 * ever hunt: a bare twelve-digit run is as often an order number, an invoice
 * number or a customer id as it is a DHL Sendungsnummer. supports() narrows to
 * senders who plausibly ship things — carriers by domain, shops by domain plus
 * a shipping word in the subject, anybody else only when the subject itself
 * talks about a shipment — and extract() then insists on a recognizable SHAPE,
 * with the ambiguous shapes additionally demanding a context word ("Sendungs-
 * nummer", "tracking") somewhere in the mail. The distinctive shapes (UPS's
 * 1Z…, the universal S10 with its letter bookends) need no such chaperone.
 *
 * The ETA is read only where the mail states one next to a word that promises
 * one — "voraussichtlich", "estimated", "Zustellung am" — and is otherwise
 * null. A parcel card with no date is honest; a parcel card with a guessed
 * date is the kind of noise that gets the whole feature switched off (see
 * InsightExtractorInterface on returning nothing rather than probably-
 * something).
 */
final readonly class ParcelExtractor implements InsightExtractorInterface
{
    /**
     * Sender domains that ARE a carrier, matched on the suffix so
     * noreply@paket.dhl.de counts as dhl.de. The value is the carrier id the
     * payload and the tracking-url table speak.
     *
     * @var array<string, string>
     */
    private const array CARRIER_DOMAINS = [
        'dhl.de'          => 'dhl',
        'dhl.com'         => 'dhl',
        'dhl.paket.de'    => 'dhl',
        'ups.com'         => 'ups',
        'fedex.com'       => 'fedex',
        'dpd.de'          => 'dpd',
        'dpd.com'         => 'dpd',
        'hermesworld.com' => 'hermes',
        'myhermes.de'     => 'hermes',
        'gls-group.eu'    => 'gls',
        'gls-pakete.de'   => 'gls',
    ];

    /**
     * Shops that ship but also send marketing all day long, so the domain
     * alone admits nothing: the subject has to talk about a shipment too.
     *
     * @var list<string>
     */
    private const array MERCHANT_DOMAINS = ['amazon.de', 'amazon.com'];

    /**
     * Subject words that make a mail about a shipment, EN and DE, lowercase.
     * Substring matching on purpose, and the stems are deliberately short:
     * "versand" catches Versandbestätigung and versandt, "versend" catches
     * versendet, "dispatch" catches Amazon's "Dispatched:" and "dispatch",
     * and "Sendungsnummer" is caught by "sendung". A shop whose subject the
     * gate does not recognise is a shop whose parcels are invisible — the
     * whole mail is refused before a tracking number is ever looked for.
     *
     * @var list<string>
     */
    private const array SHIPPING_WORDS = [
        'versand', 'versend', 'shipped', 'dispatch', 'unterwegs', 'delivery',
        'lieferung', 'zustellung', 'package', 'paket', 'parcel', 'tracking',
        'sendung',
    ];

    /** @var array<string, string> carrier id to the name a card wears */
    private const array CARRIER_NAMES = [
        'dhl'           => 'DHL',
        'ups'           => 'UPS',
        'fedex'         => 'FedEx',
        'dpd'           => 'DPD',
        'hermes'        => 'Hermes',
        'gls'           => 'GLS',
        'deutsche-post' => 'Deutsche Post',
        'amazon'        => 'Amazon',
    ];

    /**
     * A mail listing more than two shipments is a summary or a digest, and a
     * digest re-stated as many cards is noise, not facts.
     */
    private const int MAX_DRAFTS = 2;

    /**
     * Amazon's order number — 303-1114330-8516368 — and the only identity its
     * shipping mail states.
     *
     * Distinctive enough to need no chaperone, unlike the bare digit runs: three
     * digits, seven, seven, hyphenated in that exact shape is not something an
     * invoice number or a customer id looks like. It is still only consulted for
     * a merchant sender, because the shape's safety is not the point — the
     * number means "a parcel" only because Amazon is the one saying it.
     */
    private const string MERCHANT_ORDER = '~\b\d{3}-\d{7}-\d{7}\b~';

    /**
     * Weekday names, spelled out, EN and DE, ISO numbering — written out for
     * the reason MONTHS is written out: the parse must give the same answer on
     * every machine, whatever locale data it happens to carry.
     *
     * @var array<string, int>
     */
    private const array WEEKDAYS = [
        'monday' => 1, 'montag' => 1,
        'tuesday' => 2, 'dienstag' => 2,
        'wednesday' => 3, 'mittwoch' => 3,
        'thursday' => 4, 'donnerstag' => 4,
        'friday' => 5, 'freitag' => 5,
        'saturday' => 6, 'samstag' => 6, 'sonnabend' => 6,
        'sunday' => 7, 'sonntag' => 7,
    ];

    /**
     * How far past an ETA context word a date may sit and still be the date
     * that word promised. "Voraussichtliche Zustellung am 24.12.2026" is well
     * inside; a date three paragraphs later is about something else.
     */
    private const int ETA_WINDOW = 80;

    /**
     * Month names, spelled and abbreviated, in both languages — written out
     * rather than derived from ICU for the same reason
     * DeterministicDateDetector writes them out: the parse must give the same
     * answer on every machine.
     *
     * @var array<string, int>
     */
    private const array MONTHS = [
        'januar' => 1, 'january' => 1, 'jan' => 1,
        'februar' => 2, 'february' => 2, 'feb' => 2,
        'märz' => 3, 'maerz' => 3, 'march' => 3, 'mar' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4,
        'mai' => 5, 'may' => 5,
        'juni' => 6, 'june' => 6, 'jun' => 6,
        'juli' => 7, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8,
        'september' => 9, 'sept' => 9, 'sep' => 9,
        'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11,
        'dezember' => 12, 'december' => 12, 'dez' => 12, 'dec' => 12,
    ];

    public static function key(): string
    {
        return 'parcel';
    }

    public function icon(): string
    {
        return 'fa-solid fa-box';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(Message $message): bool
    {
        $domain = $this->senderDomain($message);

        if (null !== $domain) {
            if (null !== $this->carrierForDomain($domain)) {
                return true;
            }

            // A shop domain narrows to shipping mail by subject; anything else
            // it sends is receipts and recommendations.
            if (true === $this->isMerchantDomain($domain)) {
                return $this->mentionsShipping((string) $message->subject);
            }
        }

        // Any other sender may still be a shop we have never heard of. Let the
        // subject open the gate — extract() only emits when it also finds a
        // recognizable tracking number, so this admits cheaply and commits to
        // nothing.
        return $this->mentionsShipping((string) $message->subject);
    }

    public function extract(Message $message): array
    {
        $subject = trim((string) $message->subject);
        $body = trim((string) $message->bodyText);

        // Context words count wherever they appear — the number sits in the
        // body while "Sendungsverfolgung" is the subject often enough.
        $whole = $subject . "\n" . $body;

        $found = $this->trackingNumbersIn($body, $message, $whole);

        // Some carriers put the number in the subject and a login wall in the
        // body; the subject is the fallback, not an addition, so a body that
        // yielded numbers is not second-guessed.
        if ([] === $found) {
            $found = $this->trackingNumbersIn($subject, $message, $whole);
        }

        $senderDomain = $this->senderDomain($message);

        // A shop that hands the parcel over to a carrier it never names states
        // no tracking number anywhere, and Amazon — which ships more parcels
        // than every carrier in CARRIER_DOMAINS puts together — is the loudest
        // example: its mail carries an order number and a link into its own
        // progress tracker, and nothing a carrier would recognise. Read that
        // way it is still a parcel with a status and an ETA, which is the whole
        // fact the card exists to state.
        //
        // Only when the carrier hunt came back empty. A merchant mail that DOES
        // quote a real tracking number is the better reading of the same parcel,
        // and taking both would put the same shipment on the radar twice under
        // two identities.
        if ([] === $found && null !== $senderDomain && true === $this->isMerchantDomain($senderDomain)) {
            return $this->merchantDrafts($message, $senderDomain, $subject, $whole);
        }

        if ([] === $found) {
            return [];
        }

        $senderCarrier = null === $senderDomain ? null : $this->carrierForDomain($senderDomain);
        $status = $this->status($subject, $whole);
        $eta = $this->eta($whole, $message->receivedAt);
        $fromName = trim((string) $message->fromName);

        $drafts = [];

        foreach (array_slice($found, 0, self::MAX_DRAFTS) as $candidate) {
            // The sender knows itself better than the number's shape does: a
            // 20-digit run in a DHL mail is DHL because DHL sent it, whatever
            // else the shape could be. The shape decides only for shop and
            // unknown senders.
            $carrier = $senderCarrier ?? $candidate['carrier'];

            $drafts[] = new InsightDraft(
                kind: InsightKind::Parcel,
                title: $this->carrierName($carrier, $senderDomain) . ' · ' . $candidate['number'],
                dedupeKey: strtoupper($candidate['number']),
                payload: [
                    'carrier'        => $carrier,
                    'trackingNumber' => $candidate['number'],
                    'trackingUrl'    => $this->trackingUrl($carrier, $candidate['number']),
                    // The shop the parcel comes from, when a shop (not the
                    // carrier itself) sent the mail — that name is the half
                    // the user actually recognizes on a card.
                    'merchant'       => null === $senderCarrier && '' !== $fromName ? $fromName : null,
                    'status'         => $status,
                ],
                happensAt: $eta,
            );
        }

        return $drafts;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The parcel a merchant mail states without ever naming a carrier.
     *
     * Identity is the order number plus WHICH package of that order this is:
     * one order shipped in three boxes is three parcels, and keying on the
     * order alone would collapse them into one card that keeps overwriting
     * itself. The package index comes out of the tracking link, and defaults to
     * the first — a mail with no link is a mail about a single shipment.
     *
     * The link is rebuilt rather than lifted out of the body, because the one
     * in the mail is loaded with campaign parameters (`vt=NOTIFICATIONS`,
     * `ref_=…`) that identify the mail it came from; a card is not a click
     * tracker, and the bare form works.
     *
     * @return list<InsightDraft>
     */
    private function merchantDrafts(Message $message, string $domain, string $subject, string $whole): array
    {
        if (1 !== preg_match(self::MERCHANT_ORDER, $whole, $matches)) {
            return [];
        }

        $order = $matches[0];
        $index = $this->packageIndex($whole, $order);
        $shipment = $this->shipmentId($whole, $order);
        $fromName = trim((string) $message->fromName);

        return [new InsightDraft(
            kind: InsightKind::Parcel,
            title: $this->carrierName('amazon', $domain) . ' · ' . $order,
            dedupeKey: $order . '#' . $index,
            payload: [
                'carrier' => 'amazon',
                // Null, and deliberately not the order number wearing the
                // field's name: a card that offers this to a carrier's
                // tracking box would be offering something no carrier has
                // ever heard of. The order number has its own key.
                'trackingNumber' => null,
                'orderNumber'    => $order,
                'shipmentId'     => $shipment,
                'trackingUrl'    => $this->merchantTrackingUrl($domain, $order, $index, $shipment),
                'merchant'       => '' === $fromName ? null : $fromName,
                'status'         => $this->status($subject, $whole),
            ],
            happensAt: $this->eta($whole, $message->receivedAt),
        )];
    }

    /** Which package of the order this mail is about; the first, when unsaid. */
    private function packageIndex(string $text, string $order): int
    {
        $pattern = '~orderId=' . preg_quote($order, '~') . '\S*?packageIndex=(\d+)~';

        if (1 === preg_match($pattern, $text, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * The opaque shipment id from the mail's own tracking link, when it
     * carries one.
     *
     * Anchored to THIS order, so a mail that happens to mention two shipments
     * cannot hand the second one's id to the first one's card.
     */
    private function shipmentId(string $text, string $order): ?string
    {
        $pattern = '~orderId=' . preg_quote($order, '~') . '\S*?shipmentId=([A-Za-z0-9_-]+)~';

        if (1 === preg_match($pattern, $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Where the card's button goes, and the shipment id is not optional
     * decoration.
     *
     * The first version of this built the progress-tracker URL from the order
     * number and the package index alone, on the reasoning that the rest of
     * what Amazon puts in the link — `vt=NOTIFICATIONS`, `ref_=…` — is campaign
     * tracking that a card has no business carrying. That was right about the
     * campaign parameters and wrong about `shipmentId`: without it the tracker
     * answers "Leider können wir die Informationen zur Sendungsverfolgung
     * gerade nicht abrufen" and bounces to the order after seven seconds. The
     * id identifies the parcel, not the reader, so it is kept and the campaign
     * parameters are still dropped.
     *
     * With no id in the mail there is no tracker to reach, so the button goes
     * to the order instead — a page that always resolves and that states the
     * delivery status itself. A button that lands somewhere useful beats one
     * that lands on an apology.
     */
    private function merchantTrackingUrl(string $domain, string $order, int $index, ?string $shipment): string
    {
        $root = true === $this->domainIs($domain, 'amazon.com') ? 'amazon.com' : 'amazon.de';

        if (null === $shipment) {
            return sprintf('https://www.%s/gp/your-account/order-details?orderID=%s', $root, $order);
        }

        return sprintf(
            'https://www.%s/progress-tracker/package?orderId=%s&packageIndex=%d&shipmentId=%s',
            $root,
            $order,
            $index,
            $shipment,
        );
    }

    /**
     * Tracking numbers in this text, in reading order, each with the carrier
     * its SHAPE suggests — the sender may overrule that later.
     *
     * The distinctive shapes are always safe: nothing else looks like UPS's
     * 1Z + 16, JJD + digits, or the S10's two-letter bookends. Bare digit runs
     * are the dangerous ones, and each length is admitted only under context:
     * DHL's words for 12 and 20 digits, a literal "fedex" for 12 and 15, a
     * literal "dpd" for 14. No context, no match — an order number stays an
     * order number.
     *
     * @return list<array{number: string, carrier: string}>
     */
    private function trackingNumbersIn(string $text, Message $message, string $whole): array
    {
        if ('' === $text) {
            return [];
        }

        $context = mb_strtolower($whole . "\n" . (string) $message->fromAddress);

        $dhlContext = 1 === preg_match('~sendungsnummer|sendungsverfolgung|tracking|piececode~i', $whole);
        $fedexContext = true === str_contains($context, 'fedex');
        $dpdContext = true === str_contains($context, 'dpd');

        $found = [];

        foreach ($this->allMatches('~\b1Z[0-9A-Z]{16}\b~', $text) as $number) {
            $found = $this->remember($found, $number, 'ups');
        }

        foreach ($this->allMatches('~\bJJD\d{16,20}\b~', $text) as $number) {
            $found = $this->remember($found, $number, 'dhl');
        }

        // The universal S10 — RR123456789DE and kin. Any postal operator may
        // issue one; Deutsche Post is what it means in this user's mailbox
        // when the sender does not say otherwise.
        foreach ($this->allMatches('~\b[A-Z]{2}\d{9}[A-Z]{2}\b~', $text) as $number) {
            $found = $this->remember($found, $number, 'deutsche-post');
        }

        foreach ($this->allMatches('~\b\d{12,20}\b~', $text) as $number) {
            // A mail that literally says "fedex" outranks DHL's generic
            // context words for the 12-digit shape — "tracking" appears in
            // every carrier's mail, the competitor's name only deliberately.
            $carrier = match (strlen($number)) {
                12 => true === $fedexContext ? 'fedex' : (true === $dhlContext ? 'dhl' : null),
                20 => true === $dhlContext ? 'dhl' : null,
                15 => true === $fedexContext ? 'fedex' : null,
                14 => true === $dpdContext ? 'dpd' : null,
                default => null,
            };

            if (null !== $carrier) {
                $found = $this->remember($found, $number, $carrier);
            }
        }

        return $found;
    }

    /**
     * Append unless the number is already known — the same number quoted in
     * the text and again in a tracking link is one parcel, not two.
     *
     * @param list<array{number: string, carrier: string}> $found
     *
     * @return list<array{number: string, carrier: string}>
     */
    private function remember(array $found, string $number, string $carrier): array
    {
        foreach ($found as $candidate) {
            if ($candidate['number'] === $number) {
                return $found;
            }
        }

        $found[] = ['number' => $number, 'carrier' => $carrier];

        return $found;
    }

    /**
     * Where in its life the mail says the parcel is.
     *
     * **The subject is asked first, and its answer is final.** A shop's
     * progress bar renders into plain text as every step it has — "Ordered /
     * Dispatched / Out for delivery / Delivered", all four, in every mail of
     * the series — so a body scan answers with the LAST stage the parcel will
     * ever reach rather than the one it is at, and reports a parcel delivered
     * while it is still in a depot. The subject carries one stage because a
     * subject line has room for one: "Dispatched: …", "Out for delivery: …".
     * Only a subject that names no stage at all falls through to the body,
     * which is the case the carriers' own mail is usually in.
     *
     * Order matters within each pass: "wird zugestellt" contains "zugestellt",
     * so out-for-delivery has to be asked before delivered or every DHL
     * "arrives today" mail would claim the parcel already landed.
     */
    private function status(string $subject, string $whole): string
    {
        return $this->statusIn($subject) ?? $this->statusIn($whole) ?? 'announced';
    }

    /** The stage this text names, or null when it names none. */
    private function statusIn(string $text): ?string
    {
        $text = mb_strtolower($text);

        foreach (['out for delivery', 'wird zugestellt', 'in zustellung'] as $phrase) {
            if (true === str_contains($text, $phrase)) {
                return 'out_for_delivery';
            }
        }

        foreach (['delivered', 'zugestellt'] as $phrase) {
            if (true === str_contains($text, $phrase)) {
                return 'delivered';
            }
        }

        foreach (['shipped', 'dispatched', 'versandt', 'versendet', 'unterwegs', 'on its way'] as $phrase) {
            if (true === str_contains($text, $phrase)) {
                return 'in_transit';
            }
        }

        return null;
    }

    /**
     * The delivery date the mail promises, or null when it promises none.
     *
     * Only a date within ETA_WINDOW of a word that announces one counts —
     * "gültig bis 31.12.2026" in a footer is a date, not an ETA, and putting
     * it on a parcel card is exactly the guess this extractor exists not to
     * make. Every announcing word gets a chance, because "estimated delivery"
     * and the actual "Zustellung am 24.12.2026" are frequently different
     * sentences of the same mail.
     */
    private function eta(string $text, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        preg_match_all(
            '~voraussichtlich|estimated|expected|arriving|zustellung am|liefertermin~iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$word, $offset]) {
            $date = $this->dateIn(substr($text, $offset, strlen($word) + self::ETA_WINDOW), $receivedAt);

            if (null !== $date) {
                return $date;
            }
        }

        return null;
    }

    /**
     * The first explicit date in this window, at noon UTC — noon because a
     * carrier promises a day, never an hour, and midnight would render as the
     * previous evening in any timezone west of the parcel.
     */
    private function dateIn(string $window, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        // 24.12.2026 — the German convention, day first, full year required.
        if (1 === preg_match('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[1], (int) $m[2], (int) $m[3], $receivedAt);
        }

        // 2026-12-24 — the one form no locale reads two ways.
        if (1 === preg_match('~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[3], (int) $m[2], (int) $m[1], $receivedAt);
        }

        $months = implode('|', array_keys(self::MONTHS));

        // 24. Dezember [2026] — the month name removes the day/month
        // ambiguity, which is what lets the year be optional here and not in
        // the numeric forms.
        if (1 === preg_match(sprintf('~\b(\d{1,2})\.?\s*(%s)\b(?:\s*,?\s*(\d{4}))?~iu', $months), $window, $m)) {
            return $this->dateFrom(
                (int) $m[1],
                self::MONTHS[$this->monthKey($m[2])],
                '' === ($m[3] ?? '') ? null : (int) $m[3],
                $receivedAt,
            );
        }

        // December 24[, 2026].
        if (1 === preg_match(sprintf('~\b(%s)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:\s*,?\s*(\d{4}))?~iu', $months), $window, $m)) {
            return $this->dateFrom(
                (int) $m[2],
                self::MONTHS[$this->monthKey($m[1])],
                '' === ($m[3] ?? '') ? null : (int) $m[3],
                $receivedAt,
            );
        }

        // "Arriving today", "Ankunft morgen", "Arriving Monday" — a day named
        // in words. Asked last, so a mail that states a real date is never
        // resolved against the clock instead.
        return $this->relativeDayIn($window, $receivedAt);
    }

    /**
     * A day named relative to the mail itself, resolved against the MAIL and
     * never against now — the rule dateFrom() already follows, for the same
     * reason: a backfill re-reading this mail next year has to land on the day
     * it always did.
     *
     * Without a receivedAt there is nothing to be relative TO, and a refusal is
     * the only honest answer.
     */
    private function relativeDayIn(string $window, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        if (null === $receivedAt) {
            return null;
        }

        $text = mb_strtolower($window);
        $day = $receivedAt->setTimezone(new DateTimeZone('UTC'));

        if (1 === preg_match('~\b(today|heute)\b~', $text)) {
            return $this->noonOn($day);
        }

        if (true === str_contains($text, 'übermorgen')) {
            return $this->noonOn($day->modify('+2 days'));
        }

        // "morgen" is tomorrow, and "der Morgen" is a time of day. Only the
        // second is ever introduced by "am", so that is the single thing worth
        // telling apart: "Zustellung am Morgen" is a part of today and putting
        // it on tomorrow would move a parcel a day into the future.
        if (0 === preg_match('~\bam\s+morgen\b~', $text)
            && 1 === preg_match('~\b(tomorrow|morgen)\b~', $text)) {
            return $this->noonOn($day->modify('+1 day'));
        }

        return $this->nextWeekdayIn($text, $day);
    }

    /**
     * The soonest day on or after the mail that falls on the weekday it names.
     *
     * On or after, not strictly after: a mail sent Monday morning promising
     * "Arriving Monday" means the day it was sent, and pushing that a week out
     * would be the one reading nobody meant.
     *
     * The EARLIEST weekday word in the window wins rather than the first one
     * the table happens to list, so a sentence naming two days is read in the
     * order it was written.
     */
    private function nextWeekdayIn(string $text, DateTimeImmutable $day): ?DateTimeImmutable
    {
        $bestOffset = null;
        $bestWeekday = null;

        foreach (self::WEEKDAYS as $name => $iso) {
            if (1 !== preg_match('~\b' . $name . '\b~', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            if (null === $bestOffset || $m[0][1] < $bestOffset) {
                $bestOffset = $m[0][1];
                $bestWeekday = $iso;
            }
        }

        if (null === $bestWeekday) {
            return null;
        }

        return $this->noonOn($day->modify(sprintf('+%d days', ($bestWeekday - (int) $day->format('N') + 7) % 7)));
    }

    /** That calendar day at noon UTC — see noon() on why noon. */
    private function noonOn(DateTimeImmutable $day): DateTimeImmutable
    {
        return $this->noon((int) $day->format('d'), (int) $day->format('m'), (int) $day->format('Y'));
    }

    /**
     * A calendar day as a noon-UTC instant, with a missing year resolved
     * against the MAIL, never the clock — a backfill re-reading a December
     * mail a year later must land on the same day it always did.
     *
     * receivedAt's own year, unless that puts the date more than two months
     * behind the mail: a January delivery promised in December means next
     * January, while "delivered on the 3rd" in a mail from the 5th stays this
     * year. No year and no receivedAt is a refusal, not a guess.
     */
    private function dateFrom(int $day, int $month, ?int $year, ?DateTimeImmutable $receivedAt): ?DateTimeImmutable
    {
        if (null === $year) {
            if (null === $receivedAt) {
                return null;
            }

            $year = (int) $receivedAt->format('Y');

            if (false === checkdate($month, $day, $year)) {
                return null;
            }

            if ($this->noon($day, $month, $year) < $receivedAt->modify('-2 months')) {
                ++$year;
            }
        }

        if (false === checkdate($month, $day, $year)) {
            return null;
        }

        return $this->noon($day, $month, $year);
    }

    private function noon(int $day, int $month, int $year): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 12:00:00', $year, $month, $day),
            new DateTimeZone('UTC'),
        );
    }

    /**
     * Deep link into the carrier's own tracking page, for the carriers whose
     * URL shape is stable enough to build blind. Null is fine: a card without
     * a link still states the number.
     */
    private function trackingUrl(string $carrier, string $number): ?string
    {
        return match ($carrier) {
            'dhl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=' . $number,
            'ups'    => 'https://www.ups.com/track?tracknum=' . $number,
            'fedex'  => 'https://www.fedex.com/fedextrack/?trknbr=' . $number,
            'dpd'    => 'https://tracking.dpd.de/status/de_DE/parcel/' . $number,
            'gls'    => 'https://gls-group.eu/track/' . $number,
            'hermes' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation#' . $number,
            default  => null,
        };
    }

    /** The name the card wears; a sender we cannot name is shown as its domain. */
    private function carrierName(string $carrier, ?string $senderDomain): string
    {
        return self::CARRIER_NAMES[$carrier]
            ?? (null === $senderDomain ? 'Unknown' : ucfirst($senderDomain));
    }

    private function carrierForDomain(string $domain): ?string
    {
        foreach (self::CARRIER_DOMAINS as $known => $carrier) {
            if (true === $this->domainIs($domain, $known)) {
                return $carrier;
            }
        }

        return null;
    }

    private function isMerchantDomain(string $domain): bool
    {
        foreach (self::MERCHANT_DOMAINS as $known) {
            if (true === $this->domainIs($domain, $known)) {
                return true;
            }
        }

        return false;
    }

    /** Exact or subdomain-of — mail.amazon.de is amazon.de, notamazon.de is not. */
    private function domainIs(string $domain, string $known): bool
    {
        return $domain === $known || true === str_ends_with($domain, '.' . $known);
    }

    private function mentionsShipping(string $subject): bool
    {
        $subject = mb_strtolower($subject);

        foreach (self::SHIPPING_WORDS as $word) {
            if (true === str_contains($subject, $word)) {
                return true;
            }
        }

        return false;
    }

    private function senderDomain(Message $message): ?string
    {
        $address = mb_strtolower(trim((string) $message->fromAddress));
        $at = strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        $domain = trim(substr($address, $at + 1), '<> ');

        return '' === $domain ? null : $domain;
    }

    /** Lookup keys in the month table are lower case, no trailing dot. */
    private function monthKey(string $token): string
    {
        return mb_strtolower(rtrim(trim($token), '.'));
    }

    /** @return list<string> every match of the whole pattern, in reading order */
    private function allMatches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches);

        return $matches[0];
    }
}
