<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\ParcelExtractor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the parcel extractor reads, and what it refuses to read.
 *
 * Table-driven over inline fixture mails because the subject is a table: each
 * carrier's phrasing is one row, each deliberate refusal another, and the
 * interesting rows are the near-misses — a twelve-digit invoice number in a
 * newsletter, which is exactly the shape of a DHL Sendungsnummer and must
 * still not become a parcel.
 *
 * A plain TestCase and no container: the extractor is a pure function of the
 * Message, which is the property InsightDraft's design promises and this
 * suite enforces by construction.
 */
final class ParcelExtractorTest extends TestCase
{
    private ParcelExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ParcelExtractor();
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('parcel', ParcelExtractor::key());
        self::assertSame('fa-solid fa-box', $this->extractor->icon());
        self::assertSame(100, $this->extractor->priority());
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string}    $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}> $drafts
     */
    #[DataProvider('mails')]
    public function testItReadsParcelsAndRefusesTheRest(array $mail, bool $supports, array $drafts): void
    {
        $message = self::message($mail);

        self::assertSame($supports, $this->extractor->supports($message), 'the supports() gate');

        $found = $this->extractor->extract($message);

        self::assertCount(\count($drafts), $found);

        foreach ($drafts as $i => $expected) {
            self::assertSame(InsightKind::Parcel, $found[$i]->kind);
            self::assertSame($expected['title'], $found[$i]->title);
            self::assertSame($expected['dedupeKey'], $found[$i]->dedupeKey);
            self::assertSame($expected['payload'], $found[$i]->payload);
            self::assertSame($expected['happensAt'], $found[$i]->happensAt?->format('Y-m-d H:i'));
        }
    }

    /**
     * @return array<string, array{0: array<string, ?string>, 1: bool, 2: list<array<string, mixed>>}>
     */
    public static function mails(): array
    {
        return [
            'dhl german mail with an explicit eta' => [
                [
                    'from'     => 'noreply@dhl.de',
                    'fromName' => 'DHL Paket',
                    'subject'  => 'Ihre DHL Sendung ist unterwegs',
                    'body'     => "Guten Tag,\n\n"
                        . "Ihre Sendung ist auf dem Weg.\n\n"
                        . "Sendungsnummer: 00340434161094042557\n"
                        . "Voraussichtliche Zustellung am 24.12.2026\n\n"
                        . 'Den aktuellen Stand sehen Sie jederzeit in der Sendungsverfolgung.',
                ],
                true,
                [[
                    'title'     => 'DHL · 00340434161094042557',
                    'dedupeKey' => '00340434161094042557',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '00340434161094042557',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094042557',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => '2026-12-24 12:00',
                ]],
            ],

            'ups mail: the 1Z shape needs no context words' => [
                [
                    'from'     => 'pkginfo@ups.com',
                    'fromName' => 'UPS',
                    'subject'  => 'Your package has shipped',
                    'body'     => "Hello,\n\nYour package is on its way.\n\nTracking Number: 1Z999AA10123456784",
                ],
                true,
                [[
                    'title'     => 'UPS · 1Z999AA10123456784',
                    'dedupeKey' => '1Z999AA10123456784',
                    'payload'   => [
                        'carrier'        => 'ups',
                        'trackingNumber' => '1Z999AA10123456784',
                        'trackingUrl'    => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'delivered, said in german' => [
                [
                    'from'     => 'noreply@dhl.de',
                    'fromName' => 'DHL Paket',
                    'subject'  => 'Ihre Sendung wurde zugestellt',
                    'body'     => "Guten Tag,\n\nIhre Sendung wurde heute zugestellt.\n\nSendungsnummer: 123456789012",
                ],
                true,
                [[
                    'title'     => 'DHL · 123456789012',
                    'dedupeKey' => '123456789012',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '123456789012',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=123456789012',
                        'merchant'       => null,
                        'status'         => 'delivered',
                    ],
                    'happensAt' => null,
                ]],
            ],

            // The shop sends the mail, the carrier owns the number: carrier
            // comes from the shape's context, the shop survives as merchant.
            'amazon shipping mail carrying a dhl number' => [
                [
                    'from'     => 'versandbestaetigung@amazon.de',
                    'fromName' => 'Amazon.de',
                    'subject'  => 'Ihre Amazon.de Bestellung wurde versandt',
                    'body'     => "Hallo,\n\n"
                        . "Ihre Bestellung wurde versandt.\n\n"
                        . "Versand durch: DHL\n"
                        . 'Sendungsverfolgungsnummer: 00340434161094042557',
                ],
                true,
                [[
                    'title'     => 'DHL · 00340434161094042557',
                    'dedupeKey' => '00340434161094042557',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '00340434161094042557',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094042557',
                        'merchant'       => 'Amazon.de',
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            // Twelve digits in the shape of a Sendungsnummer, but the mail
            // never talks about shipping: neither gate opens.
            'newsletter with a twelve-digit invoice number' => [
                [
                    'from'     => 'news@example-shop.de',
                    'fromName' => 'Example Shop',
                    'subject'  => 'Unsere Angebote im November',
                    'body'     => "Liebe Kundin, lieber Kunde,\n\nim Anhang finden Sie die Rechnung 123456789012.\n\nViele Grüße",
                ],
                false,
                [],
            ],

            'shipping subject from an unknown shop, but no recognizable number' => [
                [
                    'from'     => 'shop@blumen-beispiel.de',
                    'fromName' => 'Blumen Beispiel',
                    'subject'  => 'Ihre Lieferung ist unterwegs',
                    'body'     => 'Ihre Bestellung Nr. 4711 verlässt heute unser Lager.',
                ],
                true,
                [],
            ],

            // The generic gate paying off: nobody has heard of the sender, but
            // the subject talks shipping and the S10 shape vouches for itself.
            'unknown sender with a universal s10 number' => [
                [
                    'from'     => 'service@paketshop-beispiel.de',
                    'fromName' => 'Paketshop',
                    'subject'  => 'Your delivery is on its way',
                    'body'     => "Good news!\n\nTrack your parcel RR123456789DE at any post office.",
                ],
                true,
                [[
                    'title'     => 'Deutsche Post · RR123456789DE',
                    'dedupeKey' => 'RR123456789DE',
                    'payload'   => [
                        'carrier'        => 'deutsche-post',
                        'trackingNumber' => 'RR123456789DE',
                        'trackingUrl'    => null,
                        'merchant'       => 'Paketshop',
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'a digest of three shipments stops at two drafts' => [
                [
                    'from'     => 'noreply@dhl.de',
                    'fromName' => 'DHL Paket',
                    'subject'  => 'Ihre Sendungen sind unterwegs',
                    'body'     => "Sendungsnummer: 00340434161094042557\n"
                        . "Sendungsnummer: 00340434161094049998\n"
                        . 'Sendungsnummer: 123456789012',
                ],
                true,
                [
                    [
                        'title'     => 'DHL · 00340434161094042557',
                        'dedupeKey' => '00340434161094042557',
                        'payload'   => [
                            'carrier'        => 'dhl',
                            'trackingNumber' => '00340434161094042557',
                            'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094042557',
                            'merchant'       => null,
                            'status'         => 'in_transit',
                        ],
                        'happensAt' => null,
                    ],
                    [
                        'title'     => 'DHL · 00340434161094049998',
                        'dedupeKey' => '00340434161094049998',
                        'payload'   => [
                            'carrier'        => 'dhl',
                            'trackingNumber' => '00340434161094049998',
                            'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094049998',
                            'merchant'       => null,
                            'status'         => 'in_transit',
                        ],
                        'happensAt' => null,
                    ],
                ],
            ],

            // The year the mail left out resolves against the mail: December
            // received, January promised means NEXT January.
            'a january eta in a december mail rolls to the next year' => [
                [
                    'from'       => 'noreply@dhl.de',
                    'fromName'   => 'DHL Paket',
                    'subject'    => 'Ihre Sendung ist unterwegs',
                    'body'       => "Sendungsnummer: 123456789012\nZustellung am 2. Januar",
                    'receivedAt' => '2026-12-28 09:00:00',
                ],
                true,
                [[
                    'title'     => 'DHL · 123456789012',
                    'dedupeKey' => '123456789012',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '123456789012',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=123456789012',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => '2027-01-02 12:00',
                ]],
            ],

            'an eta written with a month name and no year stays in the mail\'s year' => [
                [
                    'from'     => 'noreply@dhl.de',
                    'fromName' => 'DHL Paket',
                    'subject'  => 'Ihre Sendung ist unterwegs',
                    'body'     => "Sendungsnummer: 00340434161094049998\nVoraussichtliche Zustellung: 24. Dezember",
                ],
                true,
                [[
                    'title'     => 'DHL · 00340434161094049998',
                    'dedupeKey' => '00340434161094049998',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '00340434161094049998',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094049998',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => '2026-12-24 12:00',
                ]],
            ],

            'amazon marketing mail without a shipping subject' => [
                [
                    'from'     => 'store-news@amazon.de',
                    'fromName' => 'Amazon.de',
                    'subject'  => 'Angebote der Woche',
                    'body'     => 'Entdecken Sie unsere Deals.',
                ],
                false,
                [],
            ],
        ];
    }

    /**
     * The whole point of the dedupe key: shipped, out for delivery and
     * delivered are three mails and ONE parcel, so the key has to be the
     * number and nothing but the number — no status, no mail identity.
     */
    public function testTheDedupeKeyIsStableAcrossAParcelsMailSeries(): void
    {
        $shipped = self::message([
            'from'    => 'noreply@dhl.de',
            'subject' => 'Ihre Sendung ist unterwegs',
            'body'    => 'Sendungsnummer: 00340434161094042557',
        ]);

        $delivered = self::message([
            'from'       => 'noreply@dhl.de',
            'subject'    => 'Ihre Sendung wurde zugestellt',
            'body'       => 'Ihre Sendung wurde zugestellt. Sendungsnummer: 00340434161094042557',
            'receivedAt' => '2026-11-12 15:30:00',
        ]);

        [$first] = $this->extractor->extract($shipped);
        [$second] = $this->extractor->extract($delivered);

        self::assertSame($first->dedupeKey, $second->dedupeKey, 'one parcel, one key, however many mails');
        self::assertSame('in_transit', $first->payload['status']);
        self::assertSame('delivered', $second->payload['status'], 'the follow-up still refreshes the status');
    }

    /**
     * The same number stated twice — in the text and again inside a tracking
     * link — is one parcel, not two cards.
     */
    public function testANumberQuotedTwiceIsOneDraft(): void
    {
        $message = self::message([
            'from'    => 'noreply@dhl.de',
            'subject' => 'Ihre Sendung ist unterwegs',
            'body'    => "Sendungsnummer: 00340434161094042557\n"
                . 'Verfolgen: https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434161094042557',
        ]);

        self::assertCount(1, $this->extractor->extract($message));
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string} $mail
     */
    private static function message(array $mail): Message
    {
        $message = new Message();
        $message->fromAddress = $mail['from'] ?? null;
        $message->fromName = $mail['fromName'] ?? null;
        $message->subject = $mail['subject'] ?? null;
        $message->bodyText = $mail['body'] ?? null;
        $message->receivedAt = new DateTimeImmutable(
            $mail['receivedAt'] ?? '2026-11-10 08:00:00',
            new DateTimeZone('UTC'),
        );

        return $message;
    }
}
