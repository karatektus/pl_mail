<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\FlightExtractor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the flight extractor reads, and what it refuses to read.
 *
 * The rows that matter most are the near-misses: a licence plate with the
 * exact shape of a designator but no airline behind it, and a capitalized
 * six-letter word that is not a booking code because no word announced it.
 * Both shapes flood ordinary prose, and the allowlist plus the context window
 * are the whole difference between a flight card and an invented trip.
 *
 * A plain TestCase and no container — the extractor is a pure function of the
 * Message, by design.
 */
final class FlightExtractorTest extends TestCase
{
    private FlightExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FlightExtractor();
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('flight', FlightExtractor::key());
        self::assertSame('fa-solid fa-plane', $this->extractor->icon());
        self::assertSame(100, $this->extractor->priority());
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string}    $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}> $drafts
     */
    #[DataProvider('mails')]
    public function testItReadsFlightsAndRefusesTheRest(array $mail, bool $supports, array $drafts): void
    {
        $message = self::message($mail);

        self::assertSame($supports, $this->extractor->supports($message), 'the supports() gate');

        $found = $this->extractor->extract($message);

        self::assertCount(\count($drafts), $found);

        foreach ($drafts as $i => $expected) {
            self::assertSame(InsightKind::Flight, $found[$i]->kind);
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
            'lufthansa confirmation with designator, pnr, route and date' => [
                [
                    'from'     => 'noreply@lufthansa.com',
                    'fromName' => 'Lufthansa',
                    'subject'  => 'Ihre Buchungsbestätigung',
                    'body'     => "Guten Tag,\n\n"
                        . "vielen Dank für Ihre Buchung.\n\n"
                        . "Ihr Flug LH 1234 am 24.12.2026 um 09:15 Uhr\n"
                        . "Strecke: FRA–JFK\n"
                        . "Buchungscode: K7X9QZ\n\n"
                        . 'Gute Reise!',
                ],
                true,
                [[
                    'title'     => 'LH 1234 · FRA–JFK',
                    'dedupeKey' => 'LH1234@2026-12-24',
                    'payload'   => [
                        'flightNumber'  => 'LH1234',
                        'pnr'           => 'K7X9QZ',
                        'from'          => 'FRA',
                        'to'            => 'JFK',
                        'departsAtText' => '24.12.2026 09:15',
                    ],
                    'happensAt' => '2026-12-24 09:15',
                ]],
            ],

            // Two legs, one booking: each leg takes the date, time and route
            // NEAREST to its own designator, and the shared PNR covers both.
            'two-leg itinerary from an agent becomes two drafts' => [
                [
                    'from'     => 'booking@opodo.de',
                    'fromName' => 'Opodo',
                    'subject'  => 'Your booking confirmation',
                    'body'     => "Thank you for booking with Opodo.\n\n"
                        . "Booking reference: AB12CD\n\n"
                        . "Outbound: EW 9463 on 2027-03-14 at 07:25, CGN–PMI\n"
                        . 'Return: EW 9464 on 2027-03-21 at 11:40, PMI–CGN',
                ],
                true,
                [
                    [
                        'title'     => 'EW 9463 · CGN–PMI',
                        'dedupeKey' => 'EW9463@2027-03-14',
                        'payload'   => [
                            'flightNumber'  => 'EW9463',
                            'pnr'           => 'AB12CD',
                            'from'          => 'CGN',
                            'to'            => 'PMI',
                            'departsAtText' => '2027-03-14 07:25',
                        ],
                        'happensAt' => '2027-03-14 07:25',
                    ],
                    [
                        'title'     => 'EW 9464 · PMI–CGN',
                        'dedupeKey' => 'EW9464@2027-03-21',
                        'payload'   => [
                            'flightNumber'  => 'EW9464',
                            'pnr'           => 'AB12CD',
                            'from'          => 'PMI',
                            'to'            => 'CGN',
                            'departsAtText' => '2027-03-21 11:40',
                        ],
                        'happensAt' => '2027-03-21 11:40',
                    ],
                ],
            ],

            // "SUMMER" is six uppercase characters and NOT a booking code:
            // nothing announced it. The card still carries the flight, with
            // the pnr honestly null.
            'a capitalized six-letter word is not a pnr' => [
                [
                    'from'     => 'service@nordwind-beispiel.de',
                    'fromName' => 'Nordwind',
                    'subject'  => 'Your flight is confirmed',
                    'body'     => "Your flight UA 89 is confirmed.\n\n"
                        . "Use promo code SUMMER for lounge access.\n"
                        . 'Boarding begins 45 minutes before departure.',
                ],
                true,
                [[
                    'title'     => 'UA 89',
                    'dedupeKey' => 'UA89',
                    'payload'   => [
                        'flightNumber'  => 'UA89',
                        'pnr'           => null,
                        'from'          => null,
                        'to'            => null,
                        'departsAtText' => null,
                    ],
                    'happensAt' => null,
                ]],
            ],

            // The subject opens the gate, but "ZZ 123" is a licence plate:
            // the shape matches and the allowlist says no airline, so the
            // subject-only case emits nothing.
            'subject hint alone, and no allowlisted designator' => [
                [
                    'from'     => 'anna@example.com',
                    'fromName' => 'Anna',
                    'subject'  => 'About your flight next week',
                    'body'     => "So excited! My new car plate is ZZ 123.\nSee you at 10:30.",
                ],
                true,
                [],
            ],

            'ordinary mail is not a flight' => [
                [
                    'from'     => 'newsletter@example.com',
                    'fromName' => 'Weekly',
                    'subject'  => 'Weekly digest',
                    'body'     => 'Nothing about aviation here.',
                ],
                false,
                [],
            ],

            'a four-leg listing stops at three drafts' => [
                [
                    'from'     => 'noreply@lufthansa.com',
                    'fromName' => 'Lufthansa',
                    'subject'  => 'Ihr Reiseplan',
                    'body'     => 'Ihre Flüge: LH 400, LH 401, LH 402, LH 403',
                ],
                true,
                [
                    [
                        'title'     => 'LH 400',
                        'dedupeKey' => 'LH400',
                        'payload'   => [
                            'flightNumber'  => 'LH400',
                            'pnr'           => null,
                            'from'          => null,
                            'to'            => null,
                            'departsAtText' => null,
                        ],
                        'happensAt' => null,
                    ],
                    [
                        'title'     => 'LH 401',
                        'dedupeKey' => 'LH401',
                        'payload'   => [
                            'flightNumber'  => 'LH401',
                            'pnr'           => null,
                            'from'          => null,
                            'to'            => null,
                            'departsAtText' => null,
                        ],
                        'happensAt' => null,
                    ],
                    [
                        'title'     => 'LH 402',
                        'dedupeKey' => 'LH402',
                        'payload'   => [
                            'flightNumber'  => 'LH402',
                            'pnr'           => null,
                            'from'          => null,
                            'to'            => null,
                            'departsAtText' => null,
                        ],
                        'happensAt' => null,
                    ],
                ],
            ],
        ];
    }

    /**
     * Booking confirmation, then check-in reminder: two mails, one flight on
     * one day, one dedupe key — designator plus date, nothing of the mail.
     */
    public function testTheDedupeKeyIsStableAcrossAFlightsMailSeries(): void
    {
        $booking = self::message([
            'from'    => 'noreply@lufthansa.com',
            'subject' => 'Ihre Buchungsbestätigung',
            'body'    => "Ihr Flug LH 1234 am 24.12.2026 um 09:15 Uhr\nStrecke: FRA–JFK\nBuchungscode: K7X9QZ",
        ]);

        $checkIn = self::message([
            'from'       => 'noreply@lufthansa.com',
            'subject'    => 'Ihre Bordkarte wartet',
            'body'       => 'Online Check-in für Ihren Flug LH 1234 am 24.12.2026 ist geöffnet.',
            'receivedAt' => '2026-12-23 10:00:00',
        ]);

        [$first] = $this->extractor->extract($booking);
        [$second] = $this->extractor->extract($checkIn);

        self::assertSame('LH1234@2026-12-24', $first->dedupeKey);
        self::assertSame($first->dedupeKey, $second->dedupeKey, 'one flight on one day, however many mails');
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
