<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\SubscriptionExtractor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A renewal is a phrase, never a word, and never a subject line on its own.
 *
 * "Abo", "trial" and "plan" are in every upsell mail a streaming service
 * sends; the fact is the sentence that says the charge happens by itself.
 * The refusal rows below are that claim: a mail that mentions a subscription
 * and states nothing makes no card, and the word "about" does not open a gate
 * that "abo" would.
 *
 * The second claim is the dedupe key. "Your trial ends in 3 days" and "your
 * trial ends tomorrow" are two mails about one moment, and the key is the
 * sender and the DATE precisely so the second one refreshes the first card
 * instead of standing beside it.
 *
 * A plain TestCase and no container: the extractor is a pure function of the
 * Message, which is the property InsightDraft's design promises.
 */
final class SubscriptionExtractorTest extends TestCase
{
    private SubscriptionExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SubscriptionExtractor();
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('subscription', SubscriptionExtractor::key());
        self::assertSame('fa-solid fa-arrows-rotate', $this->extractor->icon());
        self::assertSame(70, $this->extractor->priority());
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string} $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}>  $drafts
     */
    #[DataProvider('mails')]
    public function testItReadsRenewalsAndTrialsAndRefusesMarketing(array $mail, bool $supports, array $drafts): void
    {
        $message = self::message($mail);

        self::assertSame($supports, $this->extractor->supports($message), 'the supports() gate');

        $found = $this->extractor->extract($message);

        self::assertCount(\count($drafts), $found);

        foreach ($drafts as $i => $expected) {
            self::assertSame(InsightKind::Subscription, $found[$i]->kind);
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
            'german: a trial that ends on a stated day, with the price after it' => [
                [
                    'from'     => 'info@streamdienst-beispiel.de',
                    'fromName' => 'Streamdienst',
                    'subject'  => 'Deine Testphase läuft bald aus',
                    'body'     => "Hallo,\n\ndeine Testphase endet am 03.09.2027.\n\nDanach zahlst du 17,99 € pro Monat.",
                ],
                true,
                [[
                    'title'     => 'Streamdienst',
                    'dedupeKey' => 'streamdienst-beispiel.de|2027-09-03',
                    'payload'   => [
                        'service'  => 'Streamdienst',
                        'kind'     => 'trial_end',
                        'amount'   => '17,99',
                        'currency' => 'EUR',
                    ],
                    'happensAt' => '2027-09-03 12:00',
                ]],
            ],

            'english: a subscription that renews itself on a named day' => [
                [
                    'from'     => 'no-reply@musik-beispiel.com',
                    'fromName' => 'Musik Beispiel',
                    'subject'  => 'Your subscription',
                    'body'     => "Hello,\n\nYour subscription renews on September 3, 2027 for \$10.99.",
                ],
                true,
                [[
                    'title'     => 'Musik Beispiel',
                    'dedupeKey' => 'musik-beispiel.com|2027-09-03',
                    'payload'   => [
                        'service'  => 'Musik Beispiel',
                        'kind'     => 'renewal',
                        'amount'   => '10.99',
                        'currency' => 'USD',
                    ],
                    'happensAt' => '2027-09-03 12:00',
                ]],
            ],

            // A stated renewal with no date is still a fact; the flavour
            // stands in for the date in the key, and the card is undated
            // rather than dated by guesswork.
            'a renewal with no date and no price' => [
                [
                    'from'     => 'no-reply@werkzeug-beispiel.de',
                    'fromName' => 'Werkzeug Beispiel',
                    'subject'  => 'Dein Abo',
                    'body'     => 'Dein Abo verlängert sich automatisch, solange du nicht kündigst.',
                ],
                true,
                [[
                    'title'     => 'Werkzeug Beispiel',
                    'dedupeKey' => 'werkzeug-beispiel.de|renewal',
                    'payload'   => [
                        'service'  => 'Werkzeug Beispiel',
                        'kind'     => 'renewal',
                        'amount'   => null,
                        'currency' => null,
                    ],
                    'happensAt' => null,
                ]],
            ],

            // Passes the gate, states nothing: "Abo" in a subject is an
            // advertisement, not a renewal.
            'an upsell mail that only mentions a subscription' => [
                [
                    'from'     => 'news@streamdienst-beispiel.de',
                    'fromName' => 'Streamdienst',
                    'subject'  => 'Hol dir jetzt das Jahres-Abo',
                    'body'     => "Spare 20 % mit dem Jahresabo!\n\nAb 9,99 € pro Monat.",
                ],
                true,
                [],
            ],

            // The reason the gate is a word-boundary match and not a
            // substring one: "abo" lives inside "about".
            'a mail about anything at all' => [
                [
                    'from'     => 'news@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'All about our new autumn range',
                    'body'     => 'Read all about it.',
                ],
                false,
                [],
            ],

            // A date in a footer is not a renewal date — the window around the
            // phrase is what refuses it.
            'a renewal phrase far from the only date in the mail' => [
                [
                    'from'     => 'no-reply@werkzeug-beispiel.de',
                    'fromName' => 'Werkzeug Beispiel',
                    'subject'  => 'Dein Abo',
                    'body'     => "Dein Abo verlängert sich automatisch.\n\n"
                        . "Du kannst jederzeit kündigen. Alle Vorteile findest du in deinem Konto,\n"
                        . "und unsere Hilfeseiten beantworten die häufigsten Fragen rund um Zahlung,\n"
                        . "Rechnungen und Kündigung ohne Wartezeit am Telefon.\n\n"
                        . 'Unsere AGB in der Fassung vom 01.01.2026 gelten unverändert weiter.',
                ],
                true,
                [[
                    'title'     => 'Werkzeug Beispiel',
                    'dedupeKey' => 'werkzeug-beispiel.de|renewal',
                    'payload'   => [
                        'service'  => 'Werkzeug Beispiel',
                        'kind'     => 'renewal',
                        'amount'   => null,
                        'currency' => null,
                    ],
                    'happensAt' => null,
                ]],
            ],
        ];
    }

    /**
     * The whole point of the key: a service reminds three times about one
     * renewal, in three different words, and the radar shows one card.
     */
    public function testTheKeyJoinsEveryReminderAboutOneMoment(): void
    {
        $early = self::message([
            'from'    => 'info@streamdienst-beispiel.de',
            'subject' => 'Deine Testphase',
            'body'    => 'Deine Testphase endet in 3 Tagen, am 03.09.2027.',
        ]);

        $late = self::message([
            'from'       => 'info@streamdienst-beispiel.de',
            'subject'    => 'Deine Testphase endet morgen',
            'body'       => 'Deine Testphase endet morgen, am 03.09.2027.',
            'receivedAt' => '2027-09-02 07:00:00',
        ]);

        [$first] = $this->extractor->extract($early);
        [$second] = $this->extractor->extract($late);

        self::assertSame($first->dedupeKey, $second->dedupeKey, 'one moment, one card, however many reminders');
    }

    /**
     * A mail that states both is one moment, and the trial ending is the half
     * the user can still act on — so that is the flavour the card wears.
     */
    public function testATrialEndingOutranksTheRenewalItTurnsInto(): void
    {
        [$draft] = $this->extractor->extract(self::message([
            'from'     => 'info@streamdienst-beispiel.de',
            'fromName' => 'Streamdienst',
            'subject'  => 'Deine Testphase',
            'body'     => 'Deine Testphase endet am 03.09.2027 und dein Abo verlängert sich automatisch.',
        ]));

        self::assertSame('trial_end', $draft->payload['kind']);
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
