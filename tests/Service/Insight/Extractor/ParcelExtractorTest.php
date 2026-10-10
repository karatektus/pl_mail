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
        $this->assertReads($mail, $supports, $drafts);
    }

    /**
     * The French carriers and shops, a table of their own: the shapes and the
     * phrasing differ from the German ones above. Every number in it is made
     * up — each only has to be the SHAPE a real one has.
     *
     * @param array<string, ?string>                                                                           $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}> $drafts
     */
    #[DataProvider('frenchMails')]
    public function testItReadsFrenchParcels(array $mail, bool $supports, array $drafts): void
    {
        $this->assertReads($mail, $supports, $drafts);
    }

    /**
     * @param array<string, ?string>                                                                           $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}> $drafts
     */
    private function assertReads(array $mail, bool $supports, array $drafts): void
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

            // ── Reported as missed, 2026-08-21 ─────────────────────────────
            // Both mails below were filed through "Report a missed insight"
            // against a build that read neither, and each failed for its own
            // reason: the first passed the gate and found no tracking number
            // because Amazon states none, the second never reached extract()
            // at all because "Dispatched" was not a shipping word. They are
            // kept verbatim enough to still be the mails that were reported.
            // ── Reported as missed, 2026-09-24 ─────────────────────────────
            // A shop's shipping notice with no plain-text part at all. The
            // subject opened the gate and extract() was handed an empty body:
            // the tracking number is the text of a link in a table cell. The
            // markup is the reported mail's, cut down to the rows that matter —
            // including the handover date, which is announced with
            // "Voraussichtliche" and is not when the parcel arrives.
            'html-only shop notice: the tracking number is in a table cell' => [
                [
                    'from'       => 'noreply@email.siemens-home.bsh-group.com',
                    'fromName'   => 'Siemens Hausgeräte',
                    'subject'    => 'Deine Lieferung trifft in Kürze ein 2111836566-0',
                    'html'       => '<html><head><title>Deine Lieferung</title>'
                        . '<style type="text/css">.es-m-p20r { padding-right:20px!important } h1 { font-size:40px }</style></head>'
                        . '<body><!--[if gte mso 9]><xml><o:PixelsPerInch>96</o:PixelsPerInch></xml><![endif]-->'
                        . '<p>Deine Bestellnummer: 2111836566-0 <br/></p>'
                        . '<table><tr><td><p>Voraussichtliche &Uuml;bergabe an den Versanddienstleister</p></td>'
                        . '<td><p>24.9.2026</p></td></tr>'
                        . '<tr><td><p>Status</p></td><td><p>In Lieferung</p></td></tr>'
                        . '<tr><td><p>Trackingnummer</p></td><td><p>'
                        . '<a href="http://nolp.dhl.de/nextt-online-public/set_identcodes.do?lang=de&idc=00340434156079686499&rfn=&extendedSearch=true">'
                        . '00340434156079686499</a></p></td></tr>'
                        . '<tr><td><p>Lieferadresse</p></td><td><p>01702990375</p></td></tr></table>'
                        . '</body></html>',
                    'receivedAt' => '2026-09-24 14:01:58',
                ],
                true,
                [[
                    'title'     => 'DHL · 00340434156079686499',
                    'dedupeKey' => '00340434156079686499',
                    'payload'   => [
                        'carrier'        => 'dhl',
                        'trackingNumber' => '00340434156079686499',
                        'trackingUrl'    => 'https://www.dhl.de/de/privatkunden/dhl-sendungsverfolgung.html?piececode=00340434156079686499',
                        'merchant'       => 'Siemens Hausgeräte',
                        'status'         => 'announced',
                    ],
                    'happensAt' => null,
                ]],
            ],
            // ── Reported as missed, 2026-10-08 ─────────────────────────────
            // The first mail of an order, and refused at the gate: "Bestellt"
            // was not a shipping word. Its body is the progress bar with every
            // stage on it, "In Zustellung" included, so the stage has to come
            // from the subject or the card says the parcel is at the door.
            'amazon order confirmation: ordered, and promised for a weekday' => [
                [
                    'from'       => 'bestellbestaetigung@amazon.de',
                    'fromName'   => 'Amazon.de',
                    'subject'    => 'Bestellt: „Goldblatt Trapezklingen...“ und 1 mehr Artikel',
                    'body'       => "Meine Bestellungen\n\n"
                        . "    Vielen Dank für deine Bestellung!\n"
                        . "Bestellt\n\nVersendet\n\nIn Zustellung\n\nZugestellt\n\n"
                        . "Zustellung: Freitag\n\n"
                        . "Lea – Königstein Im Taunus\n\n"
                        . "Bestellnr.\n303-9228807-3382718\n\n"
                        . "Bestellung ansehen oder ändern\n"
                        . 'https://www.amazon.de/your-orders/order-details?orderID=303-9228807-3382718&ref_=p_btn_fed_veo',
                    'receivedAt' => '2026-10-07 20:05:43',
                ],
                true,
                [[
                    'title'     => 'Amazon · 303-9228807-3382718',
                    // The key the dispatch mail for the same order will carry,
                    // so that mail moves this card on instead of adding one.
                    'dedupeKey' => '303-9228807-3382718#0',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '303-9228807-3382718',
                        'shipmentId'     => null,
                        'trackingUrl'    => 'https://www.amazon.de/gp/your-account/order-details?orderID=303-9228807-3382718',
                        'merchant'       => 'Amazon.de',
                        'status'         => 'ordered',
                    ],
                    // Wednesday's "Freitag" is the Friday after it.
                    'happensAt' => '2026-10-09 12:00',
                ]],
            ],
            'amazon marketing that merely says bestellt is still refused' => [
                [
                    'from'     => 'store-news@amazon.de',
                    'fromName' => 'Amazon.de',
                    'subject'  => 'Heute bestellt, morgen da: Angebote für dich',
                    'body'     => 'Angebote, die zu deinen letzten Einkäufen passen.',
                ],
                false,
                [],
            ],
            'amazon out for delivery: an order number is the only identity stated' => [
                [
                    'from'       => 'shipment-tracking@amazon.de',
                    'fromName'   => 'Amazon.de',
                    'subject'    => 'Out for delivery: ‘Fokky Resistance Bands Set...’',
                    'body'       => "Your Orders\n\n"
                        . "    Your package is out for delivery!\n"
                        . "Ordered\n\nDispatched\n\nOut for delivery\n\nDelivered\n\n"
                        . "Arriving today 12:30 pm - 3:30 pm\n\n"
                        . "Paul – Haibach, Germany\n\n"
                        . "Order #\n303-1114330-8516368\n\n"
                        . "Track package\n"
                        . 'https://www.amazon.de/progress-tracker/package?_encoding=UTF8&orderId=303-1114330-8516368&packageIndex=0&shipmentId=TcDh28yqB&vt=NOTIFICATIONS',
                    'receivedAt' => '2026-08-21 08:37:18',
                ],
                true,
                [[
                    'title'     => 'Amazon · 303-1114330-8516368',
                    'dedupeKey' => '303-1114330-8516368#0',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '303-1114330-8516368',
                        'shipmentId'     => 'TcDh28yqB',
                        // The id is what makes the tracker resolve at all —
                        // without it Amazon answers with an apology and
                        // bounces to the order. The campaign parameters that
                        // sat beside it in the mail are still dropped.
                        'trackingUrl'    => 'https://www.amazon.de/progress-tracker/package?orderId=303-1114330-8516368&packageIndex=0&shipmentId=TcDh28yqB',
                        'merchant'       => 'Amazon.de',
                        'status'         => 'out_for_delivery',
                    ],
                    // "Arriving today", resolved against the mail and not the
                    // clock — the day it was received, at noon.
                    'happensAt' => '2026-08-21 12:00',
                ]],
            ],

            'amazon dispatched: the subject beats a progress bar that lists every stage' => [
                [
                    'from'       => 'versandbestaetigung@amazon.de',
                    'fromName'   => 'Amazon.de',
                    'subject'    => 'Dispatched: ‘Martermühle I Kaffee...’',
                    'body'       => "Your Orders\n\n"
                        . "    Your package was dispatched!\n"
                        . "Ordered\n\nDispatched\n\nOut for delivery\n\nDelivered\n\n"
                        . "Arriving Monday\n\n"
                        . "Paul – Haibach, Germany\n\n"
                        . "Order #\n303-5155019-3892367\n\n"
                        . "Track package\n"
                        . "https://www.amazon.de/progress-tracker/package?_encoding=UTF8&orderId=303-5155019-3892367&packageIndex=0&shipmentId=TVvVxMyXB&vt=NOTIFICATIONS\n\n"
                        . "* Martermühle I Kaffee Aßlinger Mischung\n  Quantity: 1\n  35.2 EUR\n\nTotal\n31.679999999999996 EUR",
                    'receivedAt' => '2026-08-20 20:00:01',
                ],
                true,
                [[
                    'title'     => 'Amazon · 303-5155019-3892367',
                    'dedupeKey' => '303-5155019-3892367#0',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '303-5155019-3892367',
                        'shipmentId'     => 'TVvVxMyXB',
                        'trackingUrl'    => 'https://www.amazon.de/progress-tracker/package?orderId=303-5155019-3892367&packageIndex=0&shipmentId=TVvVxMyXB',
                        'merchant'       => 'Amazon.de',
                        // in_transit, NOT delivered: the body's progress bar
                        // spells out all four stages in every mail of the
                        // series, so only the subject knows which one this is.
                        'status'         => 'in_transit',
                    ],
                    // Thursday's "Arriving Monday" is the Monday after it.
                    'happensAt' => '2026-08-24 12:00',
                ]],
            ],

            'a second package of the same order is a second parcel' => [
                [
                    'from'       => 'shipment-tracking@amazon.de',
                    'fromName'   => 'Amazon.de',
                    'subject'    => 'Dispatched: ‘Second box’',
                    'body'       => "Order #\n303-1114330-8516368\n\n"
                        . 'https://www.amazon.de/progress-tracker/package?orderId=303-1114330-8516368&packageIndex=1&vt=NOTIFICATIONS',
                    'receivedAt' => '2026-08-21 08:37:18',
                ],
                true,
                [[
                    'title'     => 'Amazon · 303-1114330-8516368',
                    // The order alone would collapse this onto the card the
                    // first box already owns, and the two would overwrite each
                    // other on every status change.
                    'dedupeKey' => '303-1114330-8516368#1',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '303-1114330-8516368',
                        // This mail's link carries no shipmentId, so there is
                        // no tracker to reach and the button goes to the order
                        // — a page that always resolves.
                        'shipmentId'     => null,
                        'trackingUrl'    => 'https://www.amazon.de/gp/your-account/order-details?orderID=303-1114330-8516368',
                        'merchant'       => 'Amazon.de',
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'an amazon mail with a real tracking number is read as that parcel, not as an order' => [
                [
                    'from'       => 'shipment-tracking@amazon.de',
                    'fromName'   => 'Amazon.de',
                    'subject'    => 'Dispatched: ‘A thing’',
                    'body'       => "Order #\n303-1114330-8516368\n\nTracking Number: 1Z999AA10123456784",
                    'receivedAt' => '2026-08-21 08:37:18',
                ],
                true,
                [[
                    'title'     => 'UPS · 1Z999AA10123456784',
                    'dedupeKey' => '1Z999AA10123456784',
                    'payload'   => [
                        'carrier'        => 'ups',
                        'trackingNumber' => '1Z999AA10123456784',
                        'trackingUrl'    => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
                        'merchant'       => 'Amazon.de',
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'an order number from a sender who is not a shop stays an order number' => [
                [
                    'from'     => 'noreply@random-shop.example',
                    'fromName' => 'Some Shop',
                    'subject'  => 'Your delivery is on its way',
                    'body'     => "Order #\n303-1114330-8516368\n\nThanks for shopping with us.",
                ],
                true,
                [],
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

            'gls: a twelve-digit number under DHL-looking context words is still the sender\'s parcel' => [
                [
                    'from'     => 'noreply@gls-pakete.de',
                    'fromName' => 'GLS',
                    'subject'  => 'Ihre Sendung ist unterwegs',
                    'body'     => 'Sendungsnummer: 123456789012',
                ],
                true,
                [[
                    // "Sendungsnummer" makes the shape read as DHL's; the
                    // sender, who knows itself better, turns it into GLS's.
                    'title'     => 'GLS · 123456789012',
                    'dedupeKey' => '123456789012',
                    'payload'   => [
                        'carrier'        => 'gls',
                        'trackingNumber' => '123456789012',
                        'trackingUrl'    => 'https://gls-group.eu/track/123456789012',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
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
     * Receipt time is the default of message(): Tuesday 2026-11-10 08:00 UTC.
     * Every date below is derived from that by hand, in the row's comment.
     *
     * @return array<string, array{0: array<string, ?string>, 1: bool, 2: list<array<string, mixed>>}>
     */
    public static function frenchMails(): array
    {
        $laPoste = 'https://www.laposte.fr/outils/suivre-vos-envois?code=';

        return [
            'colissimo: "arrive" says nothing, the body says delivered today' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis arrive !',
                    'body'     => "Bonjour,\n\nVotre colis 6A12345678901 sera livré aujourd'hui à l'adresse indiquée.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'out_for_delivery',
                    ],
                    // "aujourd'hui" is the day of the mail, at noon.
                    'happensAt' => '2026-11-10 12:00',
                ]],
            ],

            'colissimo: delivered, and no eta because nothing promises one' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis a bien été livré !',
                    'body'     => "Votre colis 6A12345678901 a bien été livré.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'delivered',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'colissimo: waiting at the pickup point is not delivered, and a pickup deadline is not an eta' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis vous attend !',
                    'body'     => "Votre colis 6A12345678901 est disponible dans votre point de retrait.\n"
                        . "À retirer avant le 14/11/2026.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'ready_for_pickup',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'colissimo: a future "sera livré" is a promise, never a delivery, and a slash date is day first' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis est en chemin',
                    'body'     => "Votre colis 6A12345678901 sera livré le : 14/11\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    // 14/11 is the 14th of November, in the mail's own year.
                    'happensAt' => '2026-11-14 12:00',
                ]],
            ],

            'colissimo: a failed attempt says "pas été livré" and must not read as delivered' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => "Nous n'avons pas pu vous remettre votre colis.",
                    'body'     => "Votre colis 6A12345678901 n'a pas été livré.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        // No stage of its own for a failed attempt yet — the
                        // honest answer is the neutral one, not "delivered".
                        'status'         => 'announced',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'colissimo: après-demain is two days on, not one' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis est en chemin',
                    'body'     => "Votre colis 6A12345678901 sera livré après-demain.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    // Tuesday the 10th + 2 days.
                    'happensAt' => '2026-11-12 12:00',
                ]],
            ],

            'colissimo: "sous un jour ouvré" promises no date' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis est en chemin',
                    'body'     => "Votre colis 6A12345678901 sera livré sous un jour ouvré.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'chronopost: arrived at the relay point is ready for pickup, whatever the word for it' => [
                [
                    'from'     => 'no-reply@chronopost.fr',
                    'fromName' => 'Chronopost',
                    'subject'  => 'Votre colis XW123456789FR est arrivé dans votre relais',
                    'body'     => "Numéro de suivi : XW123456789FR\n",
                ],
                true,
                [[
                    'title'     => 'Chronopost · XW123456789FR',
                    'dedupeKey' => 'XW123456789FR',
                    'payload'   => [
                        'carrier'        => 'chronopost',
                        'trackingNumber' => 'XW123456789FR',
                        'trackingUrl'    => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=XW123456789FR',
                        'merchant'       => null,
                        'status'         => 'ready_for_pickup',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'colissimo: a typographic apostrophe reads like the plain one' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Votre colis arrive !',
                    'body'     => "Votre colis 6A12345678901 sera livré aujourd’hui.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'out_for_delivery',
                    ],
                    'happensAt' => '2026-11-10 12:00',
                ]],
            ],

            'colissimo: an app-store line saying "est disponible" is not a parcel waiting at a relay' => [
                [
                    'from'     => 'noreply@notif-colissimo-laposte.info',
                    'fromName' => 'Colissimo',
                    'subject'  => 'Information sur votre colis',
                    'body'     => "Votre colis 6A12345678901 est entre nos mains.\n"
                        . "L'application La Poste est disponible sur l'App Store.\n",
                ],
                true,
                [[
                    'title'     => 'Colissimo · 6A12345678901',
                    'dedupeKey' => '6A12345678901',
                    'payload'   => [
                        'carrier'        => 'colissimo',
                        'trackingNumber' => '6A12345678901',
                        'trackingUrl'    => $laPoste . '6A12345678901',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'chronopost: the s10 shape, overruled to the carrier that sent it' => [
                [
                    'from'     => 'no-reply@chronopost.fr',
                    'fromName' => 'Chronopost',
                    'subject'  => 'Votre colis est en cours de livraison XW123456789FR',
                    'body'     => "Votre colis AMAZON est en cours de livraison.\n"
                        . "Numéro de suivi : XW123456789FR\n"
                        . "Livraison prévue aujourd'hui entre 9:00 et 12:00.\n",
                ],
                true,
                [[
                    'title'     => 'Chronopost · XW123456789FR',
                    'dedupeKey' => 'XW123456789FR',
                    'payload'   => [
                        'carrier'        => 'chronopost',
                        'trackingNumber' => 'XW123456789FR',
                        'trackingUrl'    => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=XW123456789FR',
                        'merchant'       => null,
                        'status'         => 'out_for_delivery',
                    ],
                    'happensAt' => '2026-11-10 12:00',
                ]],
            ],

            'chronopost: its mail names DPD, and that must not turn a fourteen-digit run into a second parcel' => [
                [
                    'from'     => 'no-reply@chronopost.fr',
                    'fromName' => 'Chronopost',
                    'subject'  => 'Votre colis est en chemin XW123456789FR',
                    'body'     => "Un partenaire du groupe DPD prend votre colis en charge.\n"
                        . "Numéro de suivi : XW123456789FR\n"
                        . "Référence interne : 01234567890123\n",
                ],
                true,
                [[
                    'title'     => 'Chronopost · XW123456789FR',
                    'dedupeKey' => 'XW123456789FR',
                    'payload'   => [
                        'carrier'        => 'chronopost',
                        'trackingNumber' => 'XW123456789FR',
                        'trackingUrl'    => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=XW123456789FR',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'chronopost: a mention of FedEx does not turn a fifteen-digit run into a second parcel' => [
                [
                    'from'     => 'no-reply@chronopost.fr',
                    'fromName' => 'Chronopost',
                    'subject'  => 'Votre colis est en chemin XW123456789FR',
                    'body'     => "Livraison en partenariat avec FedEx.\n"
                        . "Numéro de suivi : XW123456789FR\n"
                        . "Référence interne : 123456789012345\n",
                ],
                true,
                [[
                    'title'     => 'Chronopost · XW123456789FR',
                    'dedupeKey' => 'XW123456789FR',
                    'payload'   => [
                        'carrier'        => 'chronopost',
                        'trackingNumber' => 'XW123456789FR',
                        'trackingUrl'    => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=XW123456789FR',
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'dpd france: fourteen digits, and "sera livré le" is a promise that must not read delivered' => [
                [
                    'from'     => 'predict@information.dpd.fr',
                    'fromName' => 'DPD France',
                    'subject'  => 'Livraison de votre colis BOUTIQUE',
                    'body'     => "Votre colis 10012345678901 sera livré le 14.11.2026.\n",
                ],
                true,
                [[
                    'title'     => 'DPD · 10012345678901',
                    'dedupeKey' => '10012345678901',
                    'payload'   => [
                        'carrier'        => 'dpd',
                        'trackingNumber' => '10012345678901',
                        'trackingUrl'    => 'https://tracking.dpd.de/status/de_DE/parcel/10012345678901',
                        'merchant'       => null,
                        'status'         => 'announced',
                    ],
                    'happensAt' => '2026-11-14 12:00',
                ]],
            ],

            'mondial relay: eight digits after the word colis, from Mondial Relay itself' => [
                [
                    'from'     => 'noreply@mondialrelay.fr',
                    'fromName' => 'Mondial Relay',
                    'subject'  => 'Votre colis 12345678 est disponible !',
                    'body'     => "Bonjour,\n\nVotre colis 12345678 est disponible dans votre Point Relais®.\n",
                ],
                true,
                [[
                    'title'     => 'Mondial Relay · 12345678',
                    'dedupeKey' => '12345678',
                    'payload'   => [
                        'carrier'        => 'mondial-relay',
                        'trackingNumber' => '12345678',
                        // Its tracking page asks for a postcode as well, so no
                        // link can be built from the number alone.
                        'trackingUrl'    => null,
                        'merchant'       => null,
                        'status'         => 'ready_for_pickup',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'mondial relay marketing: an eight-digit promo code is not a parcel' => [
                [
                    'from'     => 'news@e-mail.mondialrelay.fr',
                    'fromName' => 'Mondial Relay',
                    'subject'  => 'Vos colis en Point Relais®',
                    'body'     => "Votre premier envoi à -50 % avec le code 12345678.\n",
                ],
                true,
                [],
            ],

            'eight digits after "colis" from a shop that is not Mondial Relay stay an order number' => [
                [
                    'from'     => 'service@boutique-exemple.fr',
                    'fromName' => 'Boutique',
                    'subject'  => 'Votre colis 12345678 est expédié',
                    'body'     => "Votre colis 12345678 est expédié.\n",
                ],
                true,
                [],
            ],

            'colis privé: the number is only in a link of the html part' => [
                [
                    'from'     => 'notification@notification.colisprive.com',
                    'fromName' => 'Colis Privé',
                    'subject'  => 'Votre colis est en route !',
                    'body'     => "Votre colis est en route.\nSuivez-le depuis votre espace.\n",
                    'html'     => '<p>Votre colis est en route.</p>'
                        . '<a href="https://suivi.example.test/colis?c=12345678901234567&amp;l=fr">Suivre</a>',
                ],
                true,
                [[
                    'title'     => 'Colis Privé · 12345678901234567',
                    'dedupeKey' => '12345678901234567',
                    'payload'   => [
                        'carrier'        => 'colis-prive',
                        'trackingNumber' => '12345678901234567',
                        'trackingUrl'    => null,
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'colis privé: "en cours de livraison" alone is out for delivery' => [
                [
                    'from'     => 'notification@notification.colisprive.com',
                    'fromName' => 'Colis Privé',
                    'subject'  => 'Votre colis est en cours de livraison',
                    'body'     => "Bonjour,\n\nVotre colis arrive.\n",
                    'html'     => '<a href="https://suivi.example.test/colis?c=12345678901234567">Suivre</a>',
                ],
                true,
                [[
                    'title'     => 'Colis Privé · 12345678901234567',
                    'dedupeKey' => '12345678901234567',
                    'payload'   => [
                        'carrier'        => 'colis-prive',
                        'trackingNumber' => '12345678901234567',
                        'trackingUrl'    => null,
                        'merchant'       => null,
                        'status'         => 'out_for_delivery',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'the same link from a shop that is not Colis Privé is not a parcel' => [
                [
                    'from'     => 'newsletter@boutique-exemple.fr',
                    'fromName' => 'Boutique',
                    'subject'  => 'Votre colis est en route !',
                    'body'     => "Votre colis est en route.\n",
                    'html'     => '<p>Votre colis est en route.</p>'
                        . '<a href="https://suivi.example.test/colis?c=12345678901234567&amp;l=fr">Suivre</a>',
                ],
                true,
                [],
            ],

            'cainiao: four letters, thirteen digits, two letters' => [
                [
                    'from'     => 'noreply@eu-service.cainiao.com',
                    'fromName' => 'Cainiao',
                    'subject'  => 'Colis plateforme e-commerce CNFR1234567890123HD – Ma livraison est en cours',
                    'body'     => "Votre colis CNFR1234567890123HD est en route.\n",
                ],
                true,
                [[
                    'title'     => 'Cainiao · CNFR1234567890123HD',
                    'dedupeKey' => 'CNFR1234567890123HD',
                    'payload'   => [
                        'carrier'        => 'cainiao',
                        'trackingNumber' => 'CNFR1234567890123HD',
                        'trackingUrl'    => null,
                        'merchant'       => null,
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'a french s10 number from a shop is La Poste, not Deutsche Post' => [
                [
                    'from'     => 'contact@boutique-exemple.fr',
                    'fromName' => 'Boutique',
                    'subject'  => 'Votre commande est expédiée',
                    'body'     => "Suivi de votre colis : CB123456789FR\n",
                ],
                true,
                [[
                    'title'     => 'La Poste · CB123456789FR',
                    'dedupeKey' => 'CB123456789FR',
                    'payload'   => [
                        'carrier'        => 'la-poste',
                        'trackingNumber' => 'CB123456789FR',
                        'trackingUrl'    => $laPoste . 'CB123456789FR',
                        'merchant'       => 'Boutique',
                        'status'         => 'in_transit',
                    ],
                    'happensAt' => null,
                ]],
            ],

            'amazon.fr dispatched: the order number, a link to amazon.fr, and a promised day in words' => [
                [
                    'from'     => 'expedition-commande@amazon.fr',
                    'fromName' => 'Amazon.fr',
                    'subject'  => 'Votre commande Amazon.fr (#406-1234567-7654321) a été expédiée.',
                    'body'     => "Bonjour,\n\nVotre commande 406-1234567-7654321 a été expédiée.\n"
                        . "Arrivée prévue : vendredi 20 novembre\n\nSuivre le colis\n"
                        . 'https://www.amazon.fr/progress-tracker/package?_encoding=UTF8&orderId=406-1234567-7654321&packageIndex=0&shipmentId=AbCd1234&vt=NOTIFICATIONS',
                ],
                true,
                [[
                    'title'     => 'Amazon · 406-1234567-7654321',
                    'dedupeKey' => '406-1234567-7654321#0',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '406-1234567-7654321',
                        'shipmentId'     => 'AbCd1234',
                        'trackingUrl'    => 'https://www.amazon.fr/progress-tracker/package?orderId=406-1234567-7654321&packageIndex=0&shipmentId=AbCd1234',
                        'merchant'       => 'Amazon.fr',
                        'status'         => 'in_transit',
                    ],
                    // "20 novembre", no year: the mail's own, 2026. The weekday
                    // beside it would say the 13th (the next Friday after
                    // Tuesday the 10th) — the date written out has to win.
                    'happensAt' => '2026-11-20 12:00',
                ]],
            ],

            'amazon.fr delivered: "Livré :" opens the subject, and no shipment id sends the button to the order' => [
                [
                    'from'     => 'order-update@amazon.fr',
                    'fromName' => 'Amazon.fr',
                    'subject'  => 'Livré : Votre commande Amazon.fr n° 406-1234567-7654321',
                    'body'     => "Votre colis a été livré.\nNuméro de commande 406-1234567-7654321\n",
                ],
                true,
                [[
                    'title'     => 'Amazon · 406-1234567-7654321',
                    'dedupeKey' => '406-1234567-7654321#0',
                    'payload'   => [
                        'carrier'        => 'amazon',
                        'trackingNumber' => null,
                        'orderNumber'    => '406-1234567-7654321',
                        'shipmentId'     => null,
                        'trackingUrl'    => 'https://www.amazon.fr/gp/your-account/order-details?orderID=406-1234567-7654321',
                        'merchant'       => 'Amazon.fr',
                        'status'         => 'delivered',
                    ],
                    'happensAt' => null,
                ]],
            ],
        ];
    }

    /**
     * After the parcel is delivered the carrier asks how it went, quoting the
     * very number the card is keyed on. The harvester lets the NEWEST mail
     * overwrite a card's status, and a survey names no stage — so one that got
     * through would turn "delivered" back into "announced". Each row pairs the
     * refusal with a mail of the same sender that must still be accepted.
     *
     * @param array<string, ?string> $survey
     * @param array<string, ?string> $parcel
     */
    #[DataProvider('mailsThatAreAboutTheServiceNotTheParcel')]
    public function testItRefusesSurveysAndCancellationsButNotTheParcelMailOfTheSameSender(array $survey, array $parcel): void
    {
        self::assertFalse($this->extractor->supports(self::message($survey)), 'the refused mail');
        self::assertTrue($this->extractor->supports(self::message($parcel)), 'the accepted companion');
    }

    /**
     * @return array<string, array{0: array<string, ?string>, 1: array<string, ?string>}>
     */
    public static function mailsThatAreAboutTheServiceNotTheParcel(): array
    {
        return [
            'chronopost' => [
                [
                    'from'    => 'no-reply@chronopost.fr',
                    'subject' => 'Suite à votre livraison Chronopost',
                    'body'    => 'Numéro de suivi : XW123456789FR',
                ],
                [
                    'from'    => 'no-reply@chronopost.fr',
                    'subject' => 'Votre colis est en chemin XW123456789FR',
                    'body'    => 'Numéro de suivi : XW123456789FR',
                ],
            ],
            'dpd' => [
                [
                    'from'    => 'noreply@voc.dpd.fr',
                    'subject' => 'Quel est votre niveau de satisfaction concernant votre livraison du 03/02/2026 ?',
                    'body'    => 'Colis 10012345678901',
                ],
                [
                    'from'    => 'predict@information.dpd.fr',
                    'subject' => 'Livraison de votre colis BOUTIQUE',
                    'body'    => 'Colis 10012345678901',
                ],
            ],
            'mondial relay' => [
                [
                    'from'    => 'noreply@mondialrelay.fr',
                    'subject' => 'Votre experience avec Mondial Relay nous intéresse.',
                    'body'    => 'Votre colis 12345678',
                ],
                [
                    'from'    => 'noreply@mondialrelay.fr',
                    'subject' => 'Votre colis 12345678 a été pris en charge',
                    'body'    => 'Votre colis 12345678',
                ],
            ],
            'gls' => [
                [
                    'from'    => 'noreply@gls-france.com',
                    'subject' => 'Donnez votre avis sur votre livraison',
                    'body'    => 'Merci',
                ],
                [
                    'from'    => 'noreply@gls-france.com',
                    'subject' => 'GLS France | Votre colis arrive bientôt',
                    'body'    => 'Merci',
                ],
            ],
            // "avis" is only a survey in "votre avis nous intéresse"; an "avis
            // de passage" is the notice of a missed delivery, which a card
            // should state.
            'colissimo: a survey, but not an avis de passage' => [
                [
                    'from'    => 'noreply@notif-colissimo-laposte.info',
                    'subject' => 'Votre avis nous intéresse !',
                    'body'    => 'Colis 6A12345678901',
                ],
                [
                    'from'    => 'noreply@notif-colissimo-laposte.info',
                    'subject' => 'Votre avis de passage',
                    'body'    => 'Colis 6A12345678901',
                ],
            ],
            'amazon.fr cancellation' => [
                [
                    'from'    => 'commande@amazon.fr',
                    'subject' => 'Votre commande a été annulée',
                    'body'    => 'Votre commande 406-1234567-7654321 a été annulée.',
                ],
                [
                    'from'    => 'commande@amazon.fr',
                    'subject' => 'Votre commande Amazon.fr (#406-1234567-7654321) a été expédiée.',
                    'body'    => 'Votre commande 406-1234567-7654321 a été expédiée.',
                ],
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
        $message->bodyHtml = $mail['html'] ?? null;
        $message->receivedAt = new DateTimeImmutable(
            $mail['receivedAt'] ?? '2026-11-10 08:00:00',
            new DateTimeZone('UTC'),
        );

        return $message;
    }
}
