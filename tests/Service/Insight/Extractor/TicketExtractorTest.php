<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\TicketExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the ticket extractor reads out of shop confirmations, and what it
 * refuses.
 *
 * The rows that matter most are the refusal — a ticket mail without a date
 * makes no card, which is the guard that keeps the loose subject-phrase gate
 * from flooding the board with marketing — and the reminder pair below the
 * table, which proves that identity is (event, day) rather than the mail:
 * two shops, two subjects, one concert, one dedupe key.
 *
 * A plain TestCase and Messages built in memory; an extractor is a pure
 * function of a Message and is tested as one.
 */
final class TicketExtractorTest extends TestCase
{
    private TicketExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TicketExtractor();
    }

    /**
     * @param array{title: string, happensAt: string, payload: array<string, mixed>}|null $expected
     */
    #[DataProvider('confirmations')]
    public function testItReadsTheEventOrRefuses(Message $message, ?array $expected): void
    {
        self::assertTrue($this->extractor->supports($message), 'every fixture here passes the gate');

        $drafts = $this->extractor->extract($message);

        if (null === $expected) {
            self::assertSame([], $drafts, 'no date means no card, whatever the sender');

            return;
        }

        self::assertCount(1, $drafts, 'one confirmation is one event is one draft');

        $draft = $drafts[0];

        self::assertSame(InsightKind::Ticket, $draft->kind);
        self::assertSame($expected['title'], $draft->title);
        self::assertNotNull($draft->happensAt, 'a ticket card without a date must not exist');
        self::assertSame($expected['happensAt'], $draft->happensAt->format('Y-m-d H:i'));
        self::assertSame($expected['payload'], $draft->payload);
    }

    /**
     * @return array<string, array{Message, array{title: string, happensAt: string, payload: array<string, mixed>}|null}>
     */
    public static function confirmations(): array
    {
        return [
            'a German Eventbrite confirmation, dotted date with a time beside it' => [
                self::mail(
                    subject: 'Deine Tickets für Rock im Park 2027',
                    body: <<<'MAIL'
                        Hallo Paul,

                        deine Bestellung ist bestätigt. Bestellnummer: 8123471

                        Rock im Park 2027
                        Datum: 05.06.2027, 18:30
                        Ort: Zeppelinfeld, Nürnberg

                        Zeig deine Tickets einfach auf dem Handy vor.
                        MAIL,
                    from: 'bestellung@order.eventbrite.de',
                ),
                [
                    'title' => 'Rock im Park 2027',
                    'happensAt' => '2027-06-05 18:30',
                    'payload' => [
                        'eventName' => 'Rock im Park 2027',
                        'venue' => 'Zeppelinfeld, Nürnberg',
                        'dateText' => '05.06.2027, 18:30',
                    ],
                ],
            ],
            'an English Eventim confirmation, written-out month' => [
                self::mail(
                    subject: 'Your tickets for The Cure',
                    body: <<<'MAIL'
                        Order confirmed.

                        The Cure
                        December 24, 2026, 20:00
                        Venue: Mercedes-Benz Arena, Berlin

                        Your e-tickets are attached to this email.
                        MAIL,
                    from: 'tickets@eventim.de',
                ),
                [
                    'title' => 'The Cure',
                    'happensAt' => '2026-12-24 20:00',
                    'payload' => [
                        'eventName' => 'The Cure',
                        'venue' => 'Mercedes-Benz Arena, Berlin',
                        'dateText' => 'December 24, 2026, 20:00',
                    ],
                ],
            ],
            'a small venue passes the phrase gate; no venue line stays null' => [
                self::mail(
                    subject: 'Ticketbestätigung: Jazzabend im Keller',
                    body: <<<'MAIL'
                        Vielen Dank für deine Bestellung!

                        Jazzabend im Keller
                        12.09.2026, Einlass 19:30

                        Kulturkeller Bonn
                        MAIL,
                    from: 'kasse@kulturkeller-bonn.de',
                ),
                [
                    'title' => 'Jazzabend im Keller',
                    'happensAt' => '2026-09-12 19:30',
                    'payload' => [
                        'eventName' => 'Jazzabend im Keller',
                        'venue' => null,
                        'dateText' => '12.09.2026, 19:30',
                    ],
                ],
            ],
            'a ticket mail that states no date makes no card' => [
                self::mail(
                    subject: 'Deine Tickets für Beispielband',
                    body: <<<'MAIL'
                        Deine Tickets sind da! Du findest sie in deinem Account.

                        Viel Spaß!
                        MAIL,
                    from: 'noreply@ticketmaster.de',
                ),
                null,
            ],
        ];
    }

    public function testTheReminderLandsOnTheConfirmationsCard(): void
    {
        // Two shops, two subject shapes, two date spellings — one concert.
        $confirmation = self::mail(
            subject: 'Deine Tickets für Rock im Park 2027',
            body: "Datum: 05.06.2027, 18:30\nOrt: Zeppelinfeld, Nürnberg",
            from: 'bestellung@eventbrite.de',
        );

        $reminder = self::mail(
            subject: 'Ihre Tickets für Rock im Park 2027',
            body: "Es geht los!\n\nRock im Park 2027 am 5. Juni 2027.\nZeigen Sie Ihre Tickets am Einlass vor.",
            from: 'noreply@reservix.de',
        );

        $first = $this->extractor->extract($confirmation)[0];
        $second = $this->extractor->extract($reminder)[0];

        self::assertSame($first->dedupeKey, $second->dedupeKey, 'same event, same day, one card');
        self::assertSame(
            '2027-06-05 19:00',
            $second->happensAt?->format('Y-m-d H:i'),
            'a date with no stated time defaults to the evening',
        );
    }

    public function testItIgnoresMailFromAnywhereElse(): void
    {
        $message = self::mail(
            subject: 'Weekly digest',
            body: 'Nothing ticket-shaped in here, 24.12.2026 or not.',
            from: 'news@example.com',
        );

        self::assertFalse($this->extractor->supports($message));
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('ticket', TicketExtractor::key());
        self::assertSame('fa-solid fa-ticket', $this->extractor->icon());
        self::assertSame(80, $this->extractor->priority());
    }

    private static function mail(string $subject, string $body, string $from): Message
    {
        $message = new Message();
        $message->subject = $subject;
        $message->bodyText = $body;
        $message->fromAddress = $from;

        return $message;
    }
}
