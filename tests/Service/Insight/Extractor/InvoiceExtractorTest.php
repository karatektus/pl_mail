<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\InvoiceExtractor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bill is a context AND a sum, and the sum has to survive its own notation.
 *
 * Two claims are pinned here. The first is the pair rule: a mail that talks
 * about invoices without naming money, and a mail full of prices that never
 * talks about payment, both make no card — the half-facts are the ones that
 * look right on a radar and mean nothing.
 *
 * The second is the arithmetic, and it has a table of its own below.
 * `1.234,56` and `1,234.56` are the same sum, and reading either with the
 * other's rule produces 1.23 — a plausible-looking card that is wrong by
 * three orders of magnitude. That row is the reason
 * testTheSumBeatsTheLineItemsInEitherNotation exists rather than a bare unit
 * test of a private method: what the parse is FOR is picking the total, and
 * a mis-parsed total loses that comparison to the postage.
 *
 * A plain TestCase and no container: the extractor is a pure function of the
 * Message, which is the property InsightDraft's design promises.
 */
final class InvoiceExtractorTest extends TestCase
{
    private InvoiceExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new InvoiceExtractor();
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('invoice', InvoiceExtractor::key());
        self::assertSame('fa-solid fa-file-invoice', $this->extractor->icon());
        self::assertSame(90, $this->extractor->priority());
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string} $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}>  $drafts
     */
    #[DataProvider('mails')]
    public function testItReadsBillsAndRefusesHalfOnes(array $mail, bool $supports, array $drafts): void
    {
        $message = self::message($mail);

        self::assertSame($supports, $this->extractor->supports($message), 'the supports() gate');

        $found = $this->extractor->extract($message);

        self::assertCount(\count($drafts), $found);

        foreach ($drafts as $i => $expected) {
            self::assertSame(InsightKind::Invoice, $found[$i]->kind);
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
            'german: a numbered bill with a total and a due date' => [
                [
                    'from'     => 'rechnung@stadtwerke-beispiel.de',
                    'fromName' => 'Stadtwerke Beispiel',
                    'subject'  => 'Ihre Rechnung für November 2026',
                    'body'     => "Guten Tag,\n\n"
                        . "Rechnungsnummer: RE-2026/0042\n"
                        . "Zwischensumme: 1.234,56 €\n"
                        . "Versandkosten: 9,99 €\n"
                        . "Gesamtbetrag: 1.244,55 €\n\n"
                        . 'Fällig am 05.12.2026.',
                ],
                true,
                [[
                    'title'     => 'Stadtwerke Beispiel · 1.244,55 €',
                    'dedupeKey' => 'RE-2026/0042',
                    'payload'   => [
                        'amount'        => '1.244,55',
                        'currency'      => 'EUR',
                        'invoiceNumber' => 'RE-2026/0042',
                        'issuer'        => 'Stadtwerke Beispiel',
                    ],
                    'happensAt' => '2026-12-05 12:00',
                ]],
            ],

            'english: dollars, and a due date written with a month name' => [
                [
                    'from'     => 'billing@acme-beispiel.com',
                    'fromName' => 'Acme Inc',
                    'subject'  => 'Invoice #4711 from Acme',
                    'body'     => "Hello,\n\n"
                        . "Invoice no. 4711\n"
                        . "Amount due: \$1,234.56\n"
                        . 'Due date: December 5, 2026',
                ],
                true,
                [[
                    'title'     => 'Acme Inc · $1,234.56',
                    'dedupeKey' => '4711',
                    'payload'   => [
                        'amount'        => '1,234.56',
                        'currency'      => 'USD',
                        'invoiceNumber' => '4711',
                        'issuer'        => 'Acme Inc',
                    ],
                    'happensAt' => '2026-12-05 12:00',
                ]],
            ],

            // Talks like a bill, names no money: the sum is behind a login,
            // and "you owe something" is not a fact worth a card.
            'an invoice notification with the amount behind a login' => [
                [
                    'from'     => 'billing@acme-beispiel.com',
                    'fromName' => 'Acme Inc',
                    'subject'  => 'Your invoice is ready',
                    'body'     => "Your latest invoice is available in your account.\n\n© 2026 Acme Inc",
                ],
                true,
                [],
            ],

            'numbers without a currency are not money' => [
                [
                    'from'     => 'info@acme-beispiel.de',
                    'fromName' => 'Acme',
                    'subject'  => 'Rechnung folgt',
                    'body'     => "Wir betreuen 12.000 Kunden an 3 Standorten.\nDie Rechnung folgt separat.",
                ],
                true,
                [],
            ],

            'a newsletter the gate turns away' => [
                [
                    'from'     => 'news@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'Unsere Angebote im November',
                    'body'     => 'Jetzt ab 19,99 € sichern.',
                ],
                false,
                [],
            ],

            // The date in a footer is a date, not a deadline — ParcelExtractor
            // refuses the same thing for the same reason.
            'a date in a footer is not a due date' => [
                [
                    'from'     => 'rechnung@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'Deine Rechnung',
                    'body'     => "Betrag: 99,00 €\n\nUnsere AGB sind gültig bis 31.12.2027.",
                ],
                true,
                [[
                    'title'     => 'Beispiel Shop · 99,00 €',
                    'dedupeKey' => 'beispiel-shop.de|99.00 EUR|-',
                    'payload'   => [
                        'amount'        => '99,00',
                        'currency'      => 'EUR',
                        'invoiceNumber' => null,
                        'issuer'        => 'Beispiel Shop',
                    ],
                    'happensAt' => null,
                ]],
            ],
        ];
    }

    /**
     * The decimal separator, from both ends, and through the thing it is for.
     *
     * Neither mail states a total word, so the largest amount wins — which is
     * only the right amount if the notation was read correctly. Parse
     * `1.234,56` with the English rule and it becomes 1.23, the shipping line
     * wins, and the card confidently shows the postage as the bill.
     */
    #[DataProvider('notations')]
    public function testTheSumBeatsTheLineItemsInEitherNotation(string $body, string $amount, string $currency, string $dedupeKey): void
    {
        [$draft] = $this->extractor->extract(self::message([
            'from'     => 'rechnung@beispiel-shop.de',
            'fromName' => 'Beispiel Shop',
            'subject'  => 'Deine Rechnung',
            'body'     => $body,
        ]));

        self::assertSame($amount, $draft->payload['amount'], 'the sum as the mail printed it');
        self::assertSame($currency, $draft->payload['currency']);
        self::assertSame($dedupeKey, $draft->dedupeKey, 'the key carries the parsed value, not the printed one');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function notations(): array
    {
        return [
            'german: dot groups, comma decimals' => [
                "Zwischensumme: 1.234,56 €\nVersandkosten: 9,99 €",
                '1.234,56',
                'EUR',
                'beispiel-shop.de|1234.56 EUR|-',
            ],
            'english: comma groups, dot decimals' => [
                "Subtotal: €1,234.56\nShipping: €9.99",
                '1,234.56',
                'EUR',
                'beispiel-shop.de|1234.56 EUR|-',
            ],
            'a plain two-decimal amount is not a grouped one' => [
                "Amount due: \$99.00\nShipping: \$4.50",
                '99.00',
                'USD',
                'beispiel-shop.de|99.00 USD|-',
            ],
            'a group with no decimals stays whole' => [
                "Zwischensumme: 1.234 €\nVersandkosten: 9,99 €",
                '1.234',
                'EUR',
                'beispiel-shop.de|1234.00 EUR|-',
            ],
        ];
    }

    /**
     * A total word beats size: a bill whose total is smaller than one of its
     * lines — a credit, a part payment — is still the amount owed, and the
     * word is the mail saying so.
     */
    public function testAStatedTotalOutranksTheLargestLine(): void
    {
        [$draft] = $this->extractor->extract(self::message([
            'from'     => 'rechnung@beispiel-shop.de',
            'fromName' => 'Beispiel Shop',
            'subject'  => 'Deine Rechnung',
            'body'     => "Warenwert: 1.500,00 €\nGutschrift: -1.400,00 €\nZu zahlen: 100,00 €",
        ]));

        self::assertSame('100,00', $draft->payload['amount']);
    }

    /**
     * The invoice number is the bill's identity, so the reminder for a bill
     * already on the radar lands on the card that is already there — however
     * differently the second mail phrases itself.
     */
    public function testTheNumberIsTheKeyAcrossABillAndItsReminder(): void
    {
        $bill = self::message([
            'from'    => 'rechnung@stadtwerke-beispiel.de',
            'subject' => 'Ihre Rechnung für November 2026',
            'body'    => "Rechnungsnummer: RE-2026/0042\nGesamtbetrag: 1.244,55 €\nFällig am 05.12.2026.",
        ]);

        $reminder = self::message([
            'from'       => 'rechnung@stadtwerke-beispiel.de',
            'subject'    => 'Zahlungserinnerung',
            'body'       => "Rechnung Nr. RE-2026/0042 ist noch offen.\nOffener Betrag: 1.244,55 €",
            'receivedAt' => '2026-12-12 09:00:00',
        ]);

        [$first] = $this->extractor->extract($bill);
        [$second] = $this->extractor->extract($reminder);

        self::assertSame($first->dedupeKey, $second->dedupeKey, 'one bill, one card, however many reminders');
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
