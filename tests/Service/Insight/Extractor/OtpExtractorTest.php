<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight\Extractor;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Mail\Message;
use App\Service\Insight\Extractor\OtpExtractor;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The context word is not optional, and this suite is mostly about that.
 *
 * A six-digit run is the most common shape in a mailbox: order numbers,
 * customer numbers, invoice numbers and years all wear it. The happy paths
 * below are two rows of the table; the rest are the near-misses, each one a
 * mail where a naive regex would hand the user a number to type into a login
 * form. Every one of them must come back empty, because a wrong code is not
 * a smaller version of a right one — it is a lockout.
 *
 * The expiry rows pin the other design decision: happensAt on this kind is
 * the moment the code DIES, so a stated lifetime becomes an absolute instant
 * and an unstated one stays null rather than becoming a default.
 *
 * A plain TestCase and no container: the extractor is a pure function of the
 * Message, which is the property InsightDraft's design promises.
 */
final class OtpExtractorTest extends TestCase
{
    private OtpExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new OtpExtractor();
    }

    public function testItsRegistryIdentity(): void
    {
        self::assertSame('otp', OtpExtractor::key());
        self::assertSame('fa-solid fa-key', $this->extractor->icon());
        self::assertSame(130, $this->extractor->priority());
    }

    /**
     * @param array{from?: ?string, fromName?: ?string, subject?: ?string, body?: ?string, receivedAt?: string} $mail
     * @param list<array{title: string, dedupeKey: string, payload: array<string, mixed>, happensAt: ?string}>  $drafts
     */
    #[DataProvider('mails')]
    public function testItReadsCodesAndRefusesEveryOtherNumber(array $mail, bool $supports, array $drafts): void
    {
        $message = self::message($mail);

        self::assertSame($supports, $this->extractor->supports($message), 'the supports() gate');

        $found = $this->extractor->extract($message);

        self::assertCount(\count($drafts), $found);

        foreach ($drafts as $i => $expected) {
            self::assertSame(InsightKind::Otp, $found[$i]->kind);
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
            'english: the code follows the context word' => [
                [
                    'from'     => 'noreply@github.com',
                    'fromName' => 'GitHub',
                    'subject'  => '[GitHub] Your verification code',
                    'body'     => "Hi,\n\nYour verification code is 738291.\n\nThis code expires in 15 minutes.",
                ],
                true,
                [[
                    'title'     => 'GitHub · 738291',
                    'dedupeKey' => 'github.com|738291',
                    'payload'   => [
                        'code'             => '738291',
                        'issuer'           => 'GitHub',
                        'expiresInMinutes' => 15,
                    ],
                    'happensAt' => '2026-11-10 08:15',
                ]],
            ],

            // German puts the code in FRONT of the word as often as behind it,
            // which is why the window reaches both ways.
            'german: the code stands in front of the context word' => [
                [
                    'from'     => 'service@beispielbank.de',
                    'fromName' => 'Beispielbank',
                    'subject'  => 'Dein Bestätigungscode',
                    'body'     => "Hallo,\n\n394857 ist dein Bestätigungscode. Er ist 10 Minuten gültig.\n\n© 2026 Beispielbank",
                ],
                true,
                [[
                    'title'     => 'Beispielbank · 394857',
                    'dedupeKey' => 'beispielbank.de|394857',
                    'payload'   => [
                        'code'             => '394857',
                        'issuer'           => 'Beispielbank',
                        'expiresInMinutes' => 10,
                    ],
                    'happensAt' => '2026-11-10 08:10',
                ]],
            ],

            'a mixed alphanumeric code, with the expiry written as a wall clock' => [
                [
                    'from'     => 'no-reply@beispiel.de',
                    'fromName' => 'Beispiel',
                    'subject'  => 'Dein Sicherheitscode',
                    'body'     => "Sicherheitscode: A1B2C3\n\nDer Code ist gültig bis 09:30 Uhr.",
                ],
                true,
                [[
                    'title'     => 'Beispiel · A1B2C3',
                    'dedupeKey' => 'beispiel.de|A1B2C3',
                    'payload'   => [
                        'code'             => 'A1B2C3',
                        'issuer'           => 'Beispiel',
                        'expiresInMinutes' => null,
                    ],
                    'happensAt' => '2026-11-10 09:30',
                ]],
            ],

            // The whole subject of this extractor: the number is right, the
            // shape is right, and there is no word promising a code anywhere.
            'a bare six-digit run with no context word' => [
                [
                    'from'     => 'shop@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'Anmeldung bei Beispiel Shop',
                    'body'     => "Hallo,\n\nwir melden uns zu deiner Anfrage 483920.\n\nViele Grüße",
                ],
                true,
                [],
            ],

            'an order number quoted next to the code word' => [
                [
                    'from'     => 'shop@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'Dein Anmeldecode',
                    'body'     => "Anmeldecode angefordert.\n\nDeine Bestellnummer lautet 483920.\nKundennummer: 771234",
                ],
                true,
                [],
            ],

            'a year is never a code' => [
                [
                    'from'     => 'no-reply@beispiel.de',
                    'fromName' => 'Beispiel',
                    'subject'  => 'Your verification code',
                    'body'     => "Your verification code was requested.\n\n© 2026 Beispiel GmbH",
                ],
                true,
                [],
            ],

            'an amount standing where a code would' => [
                [
                    'from'     => 'no-reply@beispiel.de',
                    'fromName' => 'Beispiel',
                    'subject'  => 'Bestätigungscode',
                    'body'     => 'Für den Bestätigungscode per SMS berechnen wir 1234 €.',
                ],
                true,
                [],
            ],

            'a four-digit PIN talked ABOUT rather than stated' => [
                [
                    'from'     => 'service@beispielbank.de',
                    'fromName' => 'Beispielbank',
                    'subject'  => 'Sicherheitshinweis',
                    'body'     => 'Nenne niemals deinen Sicherheitscode oder die vierstellige PIN deiner Karte.',
                ],
                true,
                [],
            ],

            'a newsletter the gate turns away' => [
                [
                    'from'     => 'news@beispiel-shop.de',
                    'fromName' => 'Beispiel Shop',
                    'subject'  => 'Unsere Angebote im November',
                    'body'     => "Liebe Kundin, lieber Kunde,\n\nim Anhang findest du die Rechnung 123456.",
                ],
                false,
                [],
            ],

            'a code with no stated lifetime keeps no expiry' => [
                [
                    'from'     => 'no-reply@beispiel.de',
                    'fromName' => 'Beispiel',
                    'subject'  => 'Your one-time code',
                    'body'     => 'Your one-time code is 246810.',
                ],
                true,
                [[
                    'title'     => 'Beispiel · 246810',
                    'dedupeKey' => 'beispiel.de|246810',
                    'payload'   => [
                        'code'             => '246810',
                        'issuer'           => 'Beispiel',
                        'expiresInMinutes' => null,
                    ],
                    'happensAt' => null,
                ]],
            ],
        ];
    }

    /**
     * A stated lifetime is an expiry, and an expiry is an absolute instant on
     * the card rather than a duration the renderer has to add to something.
     */
    public function testAStatedLifetimeBecomesTheMomentTheCodeDies(): void
    {
        [$draft] = $this->extractor->extract(self::message([
            'from'       => 'no-reply@beispiel.de',
            'fromName'   => 'Beispiel',
            'subject'    => 'Your security code',
            'body'       => 'Your security code is 135790 and is valid for 2 hours.',
            'receivedAt' => '2026-11-10 23:40:00',
        ]));

        self::assertSame(120, $draft->payload['expiresInMinutes'], 'hours are folded into minutes');
        self::assertSame('2026-11-11 01:40', $draft->happensAt?->format('Y-m-d H:i'));
    }

    /**
     * A wall-clock expiry already behind the mail is tomorrow's: a code sent
     * at 23:55 and good until 00:10 is five past midnight, not a code that
     * expired the day before it was issued.
     */
    public function testAWallClockExpiryBeforeTheMailRollsToTheNextDay(): void
    {
        [$draft] = $this->extractor->extract(self::message([
            'from'       => 'no-reply@beispiel.de',
            'fromName'   => 'Beispiel',
            'subject'    => 'Dein Einmalcode',
            'body'       => "Einmalcode: 864209\nGültig bis 00:10 Uhr.",
            'receivedAt' => '2026-11-10 23:55:00',
        ]));

        self::assertSame('2026-11-11 00:10', $draft->happensAt?->format('Y-m-d H:i'));
    }

    /**
     * The dedupe key is issuer plus code, so a mail restating the code it
     * already sent lands on the existing card — while a code the service
     * genuinely re-issued is a new secret and gets a card of its own, because
     * the old one is dead the moment the new one exists.
     */
    public function testTheKeyJoinsARestatedCodeAndSeparatesAReissuedOne(): void
    {
        $sent = self::message([
            'from'    => 'no-reply@beispiel.de',
            'subject' => 'Dein Bestätigungscode',
            'body'    => 'Dein Bestätigungscode: 738291',
        ]);

        $restated = self::message([
            'from'    => 'no-reply@beispiel.de',
            'subject' => 'Dein Bestätigungscode',
            'body'    => 'Wir haben dir den Bestätigungscode 738291 geschickt.',
        ]);

        $reissued = self::message([
            'from'    => 'no-reply@beispiel.de',
            'subject' => 'Dein Bestätigungscode',
            'body'    => 'Dein neuer Bestätigungscode: 100200',
        ]);

        [$first] = $this->extractor->extract($sent);
        [$second] = $this->extractor->extract($restated);
        [$third] = $this->extractor->extract($reissued);

        self::assertSame($first->dedupeKey, $second->dedupeKey, 'one secret, one card, however many mails');
        self::assertNotSame($first->dedupeKey, $third->dedupeKey, 'a re-sent code is a new fact');
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
