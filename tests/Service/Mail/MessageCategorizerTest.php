<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Service\Mail\MessageCategorizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where every incoming message ends up.
 *
 * Worth pinning because it is silent both ways: a message in the wrong tab is
 * not an error anybody sees, it is mail that appears not to have arrived. And
 * the rules are a cascade, so the interesting cases are not "does Promotions
 * work" but the places where two rules both match and only the order decides —
 * a mailing list carrying List-Unsubscribe, a known correspondent sending from
 * a no-reply address.
 */
final class MessageCategorizerTest extends TestCase
{
    private MessageCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new MessageCategorizer();
    }

    /**
     * @param array<string,mixed> $headers
     * @param list<string>|null   $gmailLabelIds
     */
    #[DataProvider('cascade')]
    public function testCategorises(
        MessageCategory $expected,
        array $headers,
        string $from,
        ?array $gmailLabelIds = null,
    ): void {
        $message = $this->message($headers, $from, $gmailLabelIds);

        self::assertSame($expected, $this->categorizer->categorize($message, []));
    }

    /**
     * @return iterable<string, array{MessageCategory, array<string,mixed>, string, 2?: list<string>|null}>
     */
    public static function cascade(): iterable
    {
        yield 'nothing marks it' => [MessageCategory::Primary, [], 'kim@example.test'];

        // Gmail decides for its own accounts, whatever the headers say — the
        // labels are the classification, not a hint about it.
        yield 'gmail label wins over headers' => [
            MessageCategory::Promotions,
            ['List-Post' => '<mailto:list@x.test>'],
            'list@x.test',
            ['INBOX', 'CATEGORY_PROMOTIONS'],
        ];
        yield 'gmail with no category label is personal' => [
            MessageCategory::Primary,
            [],
            'kim@example.test',
            ['INBOX', 'UNREAD'],
        ];

        // The ordering that matters: discussion lists carry List-Unsubscribe
        // too, so testing Promotions first would file every mailing list as an
        // advert.
        yield 'a list is a forum, not a promotion' => [
            MessageCategory::Forums,
            ['List-Post' => '<mailto:list@x.test>', 'List-Unsubscribe' => '<https://x.test/u>'],
            'list@x.test',
        ];
        yield 'mailman' => [MessageCategory::Forums, ['X-Mailman-Version' => '2.1.39'], 'list@x.test'];

        yield 'list-unsubscribe alone is a promotion' => [
            MessageCategory::Promotions,
            ['List-Unsubscribe' => '<https://shop.test/u>'],
            'news@shop.test',
        ];
        yield 'precedence bulk' => [MessageCategory::Promotions, ['Precedence' => 'bulk'], 'news@shop.test'];

        yield 'social by domain' => [MessageCategory::Social, [], 'notify@linkedin.com'];
        // Subdomains count: the mail actually comes from mailer.facebookmail.com
        // and friends, never from the bare domain.
        yield 'social by subdomain' => [MessageCategory::Social, [], 'x@mailer.facebookmail.com'];
        // …but only as a suffix on a dot. "notlinkedin.com" is somebody else.
        yield 'a domain that merely ends in one' => [
            MessageCategory::Primary,
            [],
            'x@notlinkedin.com',
        ];

        yield 'no-reply sender' => [MessageCategory::Updates, [], 'no-reply@bank.test'];
        yield 'donotreply sender' => [MessageCategory::Updates, [], 'donotreply@bank.test'];
        yield 'auto-submitted' => [MessageCategory::Updates, ['Auto-Submitted' => 'auto-generated'], 'x@bank.test'];
        // "no" is the value RFC 3834 gives ordinary mail, so it must not count.
        yield 'auto-submitted: no is not an update' => [
            MessageCategory::Primary,
            ['Auto-Submitted' => 'no'],
            'x@bank.test',
        ];

        // Headers are stored exactly as the server sent them, and providers
        // disagree about capitalisation. Reading them case-sensitively would
        // make categorisation depend on which server delivered the mail.
        yield 'headers are matched case-insensitively' => [
            MessageCategory::Promotions,
            ['LIST-UNSUBSCRIBE' => '<https://shop.test/u>'],
            'news@shop.test',
        ];

        // A repeated header arrives as an array, and the value that matters can
        // be in either entry.
        yield 'repeated headers are all searched' => [
            MessageCategory::Updates,
            ['X-Auto-Response-Suppress' => ['', 'OOF']],
            'x@bank.test',
        ];
    }

    /**
     * The one rule that overrides the cascade rather than sitting in it: bulk
     * headers do not decide where mail from somebody you write to belongs.
     */
    public function testAKnownCorrespondentGoesToPrimaryWhateverTheHeadersSay(): void
    {
        $message = $this->message(
            ['List-Unsubscribe' => '<https://x.test/u>', 'Precedence' => 'bulk'],
            'Kim@Example.test',
        );

        self::assertSame(
            MessageCategory::Primary,
            // Normalised set, as ContactRepository hands it over — the address
            // on the message is not lower-cased in the wild.
            $this->categorizer->categorize($message, ['kim@example.test' => true]),
        );
    }

    /**
     * explain() must not be a second implementation of the cascade. It is the
     * one that runs, and categorize() is defined in terms of it — this is what
     * says so, since the two drifting apart would show up as a message filed in
     * one tab and explained as another.
     *
     * @param array<string,mixed> $headers
     * @param list<string>|null   $gmailLabelIds
     */
    #[DataProvider('cascade')]
    public function testExplainAgreesWithTheDecision(
        MessageCategory $expected,
        array $headers,
        string $from,
        ?array $gmailLabelIds = null,
    ): void {
        $message = $this->message($headers, $from, $gmailLabelIds);

        self::assertSame($expected, $this->categorizer->explain($message, [])['category']);
    }

    /**
     * @return iterable<string, array{string, string|null, array<string,mixed>, string, 3?: list<string>|null}>
     */
    public static function reasons(): iterable
    {
        yield 'forum names the header' => ['forum', 'list-post', ['List-Post' => '<mailto:l@x.test>'], 'l@x.test'];
        yield 'promotion names the header' => [
            'promotion', 'list-unsubscribe', ['List-Unsubscribe' => '<https://x.test/u>'], 'n@shop.test',
        ];
        yield 'social names the domain' => ['social', 'linkedin.com', [], 'notify@linkedin.com'];
        yield 'update names the local part' => ['update', 'no-reply@', [], 'no-reply@bank.test'];
        yield 'gmail names the label' => [
            'gmail', 'CATEGORY_UPDATES', [], 'x@y.test', ['INBOX', 'CATEGORY_UPDATES'],
        ];
        // Primary is the absence of every signal, so there is nothing to name.
        yield 'default has no signal' => ['default', null, [], 'kim@example.test'];
    }

    /**
     * @param array<string,mixed> $headers
     * @param list<string>|null   $gmailLabelIds
     */
    #[DataProvider('reasons')]
    public function testExplainNamesWhatDecided(
        string $reason,
        ?string $signal,
        array $headers,
        string $from,
        ?array $gmailLabelIds = null,
    ): void {
        $explanation = $this->categorizer->explain($this->message($headers, $from, $gmailLabelIds), []);

        self::assertSame($reason, $explanation['reason']);
        self::assertSame($signal, $explanation['signal']);
    }

    public function testExplainNamesTheCorrespondent(): void
    {
        $explanation = $this->categorizer->explain(
            $this->message([], 'kim@example.test'),
            ['kim@example.test' => true],
        );

        self::assertSame('correspondent', $explanation['reason']);
        self::assertSame('kim@example.test', $explanation['signal']);
    }

    /**
     * @param array<string,mixed> $headers
     * @param list<string>|null   $gmailLabelIds
     */
    private function message(array $headers, string $from, ?array $gmailLabelIds = null): Message
    {
        $message = new Message();
        $message->headers = $headers;
        $message->fromAddress = $from;
        $message->gmailLabelIds = $gmailLabelIds;

        return $message;
    }
}
