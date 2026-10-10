<?php

declare(strict_types=1);

namespace App\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Domain\Helper\ReadableBody;
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
        // France. Colissimo mails come from a notification domain of their
        // own, so that one is listed whole rather than laposte.fr, which also
        // sends login codes. GLS France and the Pickup relay network are left
        // out: neither states a number this extractor can read yet.
        'notif-colissimo-laposte.info' => 'colissimo',
        'colissimo.fr'                 => 'colissimo',
        'chronopost.fr'                => 'chronopost',
        'mondialrelay.fr'              => 'mondial-relay',
        'mondialrelay.com'             => 'mondial-relay',
        'colisprive.com'               => 'colis-prive',
        'dpd.fr'                       => 'dpd',
        'cainiao.com'                  => 'cainiao',
    ];

    /**
     * Shops that ship but also send marketing all day long, so the domain
     * alone admits nothing: the subject has to talk about a shipment too.
     *
     * @var list<string>
     */
    private const array MERCHANT_DOMAINS = ['amazon.de', 'amazon.com', 'amazon.fr'];

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
        // French. Stems again: "livraison", "livré", "livrée" and "expédiée"
        // are all caught, while "livre" (a book) is deliberately not.
        'colis', 'livraison', 'livré', 'expédi', 'suivi', 'relais',
    ];

    /**
     * The words of eta()'s list that are French, which is what licenses reading
     * "14/11" day first — see dateIn(). Matched against the word that was
     * found, so "estimated" is not taken for "estimé" by its first five
     * letters.
     */
    private const string FRENCH_ETA_WORD = '~^(?:pr[ée]vue?|sera livr[ée]|date de livraison|estim[ée]e?|au plus tard)$~iu';

    /**
     * Subjects that are about the service and not the parcel: the satisfaction
     * survey a carrier sends after delivery. It quotes the very number the card
     * is keyed on and names no stage, and the harvester lets the newest mail
     * overwrite a card's status — one that got through would turn "delivered"
     * back into "announced".
     *
     * "avis" alone is not here on purpose: an "avis de passage" is a missed
     * delivery, which is exactly what a card should state. And "expérience"
     * only with its possessive: this is asked of every mail before the sender
     * is, and a shop's subject quotes what was bought — "Shipped: The
     * Experience Machine" is a parcel, "Votre expérience avec …" is a survey.
     */
    private const string SURVEY_SUBJECT = '~donnez votre avis|votre avis nous|satisf|(?:votre|your) exp[ée]rience|sondage|suite à votre livraison~iu';

    /**
     * How a shop's order confirmation opens its subject: "Bestellt: …",
     * "Ordered: …". The first mail of the series the shipping words above
     * catch the rest of, and the one that already states the order number and
     * the day it is promised for.
     *
     * Anchored to the start, and asked of a merchant sender only. "Bestellt"
     * anywhere in a subject is every shop's marketing ("Jetzt bestellt, morgen
     * da"); as the first word, from the shop itself, it is the stage of one
     * order.
     */
    private const string ORDER_PLACED = '~^\s*(bestellt|ordered)\b~iu';

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
        'la-poste'      => 'La Poste',
        'colissimo'     => 'Colissimo',
        'chronopost'    => 'Chronopost',
        'mondial-relay' => 'Mondial Relay',
        'colis-prive'   => 'Colis Privé',
        'cainiao'       => 'Cainiao',
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
        'lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4,
        'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7,
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
        'janvier' => 1, 'janv' => 1,
        'février' => 2, 'fevrier' => 2, 'févr' => 2, 'fevr' => 2,
        'mars' => 3,
        'avril' => 4, 'avr' => 4,
        'juin' => 6,
        'juillet' => 7, 'juil' => 7,
        'août' => 8, 'aout' => 8,
        'septembre' => 9,
        'octobre' => 10,
        'novembre' => 11,
        'décembre' => 12, 'decembre' => 12,
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
        // Asked before the sender, so a carrier's own survey is refused too.
        if (1 === preg_match(self::SURVEY_SUBJECT, (string) $message->subject)) {
            return false;
        }

        $domain = $this->senderDomain($message);

        if (null !== $domain) {
            if (null !== $this->carrierForDomain($domain)) {
                return true;
            }

            // A shop domain narrows to shipping mail by subject; anything else
            // it sends is receipts and recommendations.
            if (true === $this->isMerchantDomain($domain)) {
                return true === $this->mentionsShipping((string) $message->subject)
                    || true === $this->announcesAnOrder((string) $message->subject);
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
        $body = ReadableBody::of($message);

        // Context words count wherever they appear — the number sits in the
        // body while "Sendungsverfolgung" is the subject often enough.
        $whole = $subject . "\n" . $body;

        $senderDomain = $this->senderDomain($message);
        $senderCarrier = null === $senderDomain ? null : $this->carrierForDomain($senderDomain);

        $found = $this->trackingNumbersIn($body, $message, $whole, $senderCarrier);

        // Some carriers put the number in the subject and a login wall in the
        // body; the subject is the fallback, not an addition, so a body that
        // yielded numbers is not second-guessed.
        if ([] === $found) {
            $found = $this->trackingNumbersIn($subject, $message, $whole, $senderCarrier);
        }

        // Colis Privé states the number nowhere in its text part — only in a
        // link of the html part, which the readable body leaves out.
        if ([] === $found && 'colis-prive' === $senderCarrier) {
            $found = $this->linkNumbersIn($message, 'colis-prive');
        }

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
        $root = match (true) {
            true === $this->domainIs($domain, 'amazon.com') => 'amazon.com',
            true === $this->domainIs($domain, 'amazon.fr')  => 'amazon.fr',
            default                                         => 'amazon.de',
        };

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
     * A competitor's NAME is a context word only when no other carrier sent
     * the mail: Chronopost's mail names DPD (the same group), and that must
     * not turn an unrelated fourteen-digit run into a DPD parcel. The generic
     * DHL words are left as they were — the sender overrules the carrier a
     * shape suggests, which is how a GLS mail's twelve digits become GLS's.
     *
     * @return list<array{number: string, carrier: string}>
     */
    private function trackingNumbersIn(string $text, Message $message, string $whole, ?string $senderCarrier): array
    {
        if ('' === $text) {
            return [];
        }

        $context = mb_strtolower($whole . "\n" . (string) $message->fromAddress);

        $dhlContext = 1 === preg_match('~sendungsnummer|sendungsverfolgung|tracking|piececode~i', $whole);
        $fedexContext = true === str_contains($context, 'fedex') && (null === $senderCarrier || 'fedex' === $senderCarrier);
        $dpdContext = true === str_contains($context, 'dpd') && (null === $senderCarrier || 'dpd' === $senderCarrier);

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
            $found = $this->remember($found, $number, $this->s10Carrier($number));
        }

        // Colissimo's own shape: a digit, a letter, eleven digits (6A12345678901).
        foreach ($this->allMatches('~\b\d[A-Z]\d{11}\b~', $text) as $number) {
            $found = $this->remember($found, $number, 'colissimo');
        }

        // Cainiao, AliExpress's carrier: CN, two letters, thirteen digits, two letters.
        foreach ($this->allMatches('~\bCN[A-Z]{2}\d{13}[A-Z]{2}\b~', $text) as $number) {
            $found = $this->remember($found, $number, 'cainiao');
        }

        // Mondial Relay's number is eight bare digits, the one shape that would
        // turn every promo code into a parcel. It is read only from Mondial
        // Relay itself, and only right after the word "colis".
        if ('mondial-relay' === $senderCarrier) {
            preg_match_all('~\bcolis\s+(?:n[°ºo]\s*)?(\d{8})\b~iu', $text, $matches);

            foreach ($matches[1] as $number) {
                $found = $this->remember($found, $number, 'mondial-relay');
            }
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
     * Numbers read out of the links of the html part, for the one sender whose
     * number only lives there: seventeen digits, bounded by non-digits.
     *
     * Out of the links and nothing else. The first version searched the whole
     * html part, where seventeen digits in a row are also a tracking pixel's
     * id, a timestamp in an image address and a style sheet's cache key — each
     * of which became a parcel of its own on the radar. An `href` is the one
     * place the number is put for a person to follow.
     *
     * @return list<array{number: string, carrier: string}>
     */
    private function linkNumbersIn(Message $message, string $carrier): array
    {
        $html = (string) ($message->bodyHtml ?? $message->bodyHtmlSafe);

        preg_match_all('~\bhref\s*=\s*(["\'])(.*?)\1~is', $html, $links);

        $found = [];

        foreach ($links[2] as $href) {
            preg_match_all('~(?<![0-9])[0-9]{17}(?![0-9])~', $href, $matches);

            foreach ($matches[0] as $number) {
                $found = $this->remember($found, $number, $carrier);
            }
        }

        return $found;
    }

    /**
     * The carrier an S10 number means when its sender does not say: the issuing
     * country is its last two letters. Deutsche Post stays the answer for every
     * country but France — the assumption this extractor started with.
     */
    private function s10Carrier(string $number): string
    {
        return 'FR' === substr($number, -2) ? 'la-poste' : 'deutsche-post';
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
     *
     * "Ordered" is a stage only a subject can state, for the reason above
     * turned round: the word is in the body of every mail of the series, as
     * the first step of the progress bar. And it has to be settled before the
     * body is asked at all — an order confirmation's body names every later
     * stage too, and would report a parcel out for delivery on the evening it
     * was ordered.
     */
    private function status(string $subject, string $whole): string
    {
        $stated = $this->statusIn($subject);

        if (null !== $stated) {
            return $stated;
        }

        if (true === $this->announcesAnOrder($subject)) {
            return 'ordered';
        }

        return $this->statusIn($whole) ?? 'announced';
    }

    private function announcesAnOrder(string $subject): bool
    {
        return 1 === preg_match(self::ORDER_PLACED, $subject);
    }

    /** The stage this text names, or null when it names none. */
    private function statusIn(string $text): ?string
    {
        // Typographic apostrophes (it is "aujourd’hui" in half the French mail)
        // fold into the plain one, so each phrase below is written once.
        $text = str_replace('’', "'", mb_strtolower($text));

        // French: only whole phrases. "livré" alone is in "sera livré" too, the
        // same trap as "zugestellt" inside "wird zugestellt".
        foreach ([
            'out for delivery', 'wird zugestellt', 'in zustellung',
            'en cours de livraison', "sera livré aujourd'hui", 'sera livré ce jour',
            "livraison prévue aujourd'hui", "livraison prévue pour aujourd'hui",
            'pris en charge par votre livreur',
        ] as $phrase) {
            if (true === str_contains($text, $phrase)) {
                return 'out_for_delivery';
            }
        }

        // "n'a pas été livré" and "n'est pas livré" contain the phrases below
        // and say the opposite; a failed attempt must not read as delivered.
        $negated = 1 === preg_match('~\bpas (encore )?(été|est) livr~u', $text);

        foreach (['delivered', 'zugestellt', 'été livré', 'est livré', 'livraison réussie', 'livré :', 'livrée :'] as $phrase) {
            if (false === $negated && true === str_contains($text, $phrase)) {
                return 'delivered';
            }
        }

        // Waiting at a relay point or a locker: the commonest last stage of a
        // French parcel, and neither "in transit" nor "delivered". "colis" has
        // to be near, because "est disponible" alone is also an app-store line
        // in the footer of half the carriers' mail.
        if (1 === preg_match('~\bcolis\b[^.!?\n]{0,30}\b(est disponible|vous attend)\b~u', $text)
            || 1 === preg_match('~\barrivé (dans|en|au) (votre )?(relais|point|locker|casier)~u', $text)) {
            return 'ready_for_pickup';
        }

        foreach ([
            'shipped', 'dispatched', 'versandt', 'versendet', 'unterwegs', 'on its way',
            'expédié', 'en chemin', 'en route', 'pris en charge', "en cours d'acheminement",
            'entre nos mains', 'entre de bonnes mains', 'arrive bientôt',
        ] as $phrase) {
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
            '~voraussichtlich|estimated|expected|arriving|zustellung am|zustellung:|liefertermin'
            . '|pr[ée]vue?\b|sera livr[ée]|date de livraison|estim[ée]e?\b|au plus tard~iu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        foreach ($matches[0] as [$word, $offset]) {
            $window = substr($text, $offset, strlen($word) + self::ETA_WINDOW);

            // "Voraussichtliche Übergabe an den Versanddienstleister" is the
            // day the shop hands the parcel over, not the day it arrives. It
            // is announced with the same word and followed by a date, and on a
            // card it would read as a delivery promised for a day on which the
            // parcel had not left the warehouse.
            if (1 === preg_match('~übergabe|handover|handed over~iu', $window)) {
                continue;
            }

            $date = $this->dateIn($window, $receivedAt, 1 === preg_match(self::FRENCH_ETA_WORD, $word));

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
    private function dateIn(string $window, ?DateTimeImmutable $receivedAt, bool $dayFirstSlashes): ?DateTimeImmutable
    {
        // 24.12.2026 — the German convention, day first, full year required.
        if (1 === preg_match('~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[1], (int) $m[2], (int) $m[3], $receivedAt);
        }

        // 2026-12-24 — the one form no locale reads two ways.
        if (1 === preg_match('~\b(\d{4})-(\d{1,2})-(\d{1,2})\b~', $window, $m)) {
            return $this->dateFrom((int) $m[3], (int) $m[2], (int) $m[1], $receivedAt);
        }

        // 14/11 and 14/11/2026 — day first, as in France. The year is optional
        // here and not in the dotted form: a slash date in a delivery mail
        // is written for a reader who knows what year it is.
        //
        // Only after a French word for the promise. Two digits, a slash and
        // two digits are a date in French mail and several other things
        // elsewhere: "expected 3/4" in an American one is the fourth of March,
        // and "estimated … our 24/7 support" is no date at all, yet both read
        // as a delivery day here before this asked which language was
        // speaking. A slash pair that is not a day of a month is passed over
        // rather than returned as "no date", so a month name further along
        // the same window is still found.
        if (true === $dayFirstSlashes
            && 1 === preg_match('~\b(\d{1,2})/(\d{1,2})(?:/(\d{4}))?\b~', $window, $m)) {
            $date = $this->dateFrom((int) $m[1], (int) $m[2], '' === ($m[3] ?? '') ? null : (int) $m[3], $receivedAt);

            if (null !== $date) {
                return $date;
            }
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

        if (1 === preg_match("~\b(today|heute|aujourd['’]hui|ce jour)\b~u", $text)) {
            return $this->noonOn($day);
        }

        // Before "demain" below: "après-demain" contains it.
        if (true === str_contains($text, 'übermorgen')
            || 1 === preg_match('~\bapr[èe]s-demain\b~u', $text)) {
            return $this->noonOn($day->modify('+2 days'));
        }

        // "morgen" is tomorrow, and "der Morgen" is a time of day. Only the
        // second is ever introduced by "am", so that is the single thing worth
        // telling apart: "Zustellung am Morgen" is a part of today and putting
        // it on tomorrow would move a parcel a day into the future.
        if (0 === preg_match('~\bam\s+morgen\b~', $text)
            && 1 === preg_match('~\b(tomorrow|morgen|demain)\b~', $text)) {
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
            // La Poste's own tracking page, which Colissimo parcels and the
            // S10 numbers it issues both resolve on. Chronopost has its own.
            'colissimo', 'la-poste' => 'https://www.laposte.fr/outils/suivre-vos-envois?code=' . $number,
            'chronopost' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=' . $number,
            // Mondial Relay's page also asks for a postcode, and Colis Privé's
            // and Cainiao's links are not known: no link beats a wrong one.
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
