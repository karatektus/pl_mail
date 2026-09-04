<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Embeddable\CategorySorting;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Handler\ClassifyMailHandler;
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
    /**
     * The local cascade, asked directly on a mailbox where Gmail is deciding.
     *
     * Gmail's CATEGORY_* labels are authoritative while they arrive, which
     * means the rules below them never run on that mailbox and nobody can see
     * what they would have said. They take over the instant the labels stop —
     * an account moved off Gmailify, a mailbox migrated to plain IMAP — having
     * never been checked against real mail. So the message view offers both
     * answers, and this is the switch that produces the second one.
     */
    public function testTheLocalRulesCanBeAskedPastGmailsAnswer(): void
    {
        // Gmail files it under Updates; the headers say bulk mail.
        $message = $this->message(
            ['List-Unsubscribe' => '<https://shop.test/u>'],
            'newsletter@shop.test',
            ['INBOX', 'CATEGORY_UPDATES'],
        );

        $gmail = $this->categorizer->explain($message, []);

        self::assertSame('gmail', $gmail['reason']);
        self::assertSame(MessageCategory::Updates, $gmail['category']);

        $local = $this->categorizer->explain($message, [], ignoreProviderLabels: true);

        self::assertSame('list-unsubscribe', $local['signal'], 'the header the local rules read');
        self::assertSame(MessageCategory::Promotions, $local['category']);
    }

    /**
     * The correspondent override still outranks everything once Gmail is out
     * of the way — the local answer has to be the WHOLE local cascade, not
     * just its header half.
     */
    public function testTheLocalAnswerStillHonoursTheCorrespondentOverride(): void
    {
        $message = $this->message(
            ['List-Unsubscribe' => '<https://shop.test/u>'],
            'kim@example.test',
            ['INBOX', 'CATEGORY_PROMOTIONS'],
        );

        $local = $this->categorizer->explain(
            $message,
            ['kim@example.test' => true],
            ignoreProviderLabels: true,
        );

        self::assertSame(MessageCategory::Primary, $local['category']);
        self::assertSame('correspondent', $local['reason']);
    }

    /**
     * On a mailbox that never had Gmail labels the switch changes nothing —
     * there was no provider answer to step past.
     */
    public function testTheSwitchIsANoOpWithoutProviderLabels(): void
    {
        $message = $this->message(['List-Post' => '<mailto:l@x.test>'], 'l@x.test');

        self::assertEquals(
            $this->categorizer->explain($message, []),
            $this->categorizer->explain($message, [], ignoreProviderLabels: true),
        );
    }

    /**
     * The model's verdict can be asked past, and that is what makes the two
     * answers comparable.
     *
     * The details panel puts "the rules" beside "the model" on every message,
     * and it can only do that if the rules can be asked a question the model is
     * not already part of the answer to. Without the switch the tie-break would
     * hand back the model's category and call it a rule.
     */
    public function testTheRulesCanBeAskedPastTheModelsVerdict(): void
    {
        // Nothing in it any rule recognises, so the tie-break is reached — the
        // only place the stored verdict is ever read.
        $message = $this->message([], 'someone@example.test');
        $message->aiCategory = MessageCategory::Promotions;

        $withModel = $this->categorizer->explain($message, []);

        self::assertSame('ai', $withModel['reason']);
        self::assertSame(MessageCategory::Promotions, $withModel['category']);

        $rulesOnly = $this->categorizer->explain($message, [], ignoreAi: true);

        self::assertSame('default', $rulesOnly['reason']);
        self::assertSame(MessageCategory::Primary, $rulesOnly['category']);
    }

    /**
     * And a message a rule DID recognise keeps that rule's answer, whatever the
     * model said about it.
     *
     * This is what makes asking the model about every message safe rather than
     * merely informative: the verdict is stored beside the decision and read
     * only where the rules gave up, so a second opinion on record is not a
     * second decider. See ClassifyMailHandler::worthAsking().
     */
    public function testAStoredVerdictNeverOverrulesARuleThatMatched(): void
    {
        $message = $this->message(['List-Post' => '<mailto:l@x.test>'], 'l@x.test');
        $message->aiCategory = MessageCategory::Promotions;

        $explained = $this->categorizer->explain($message, []);

        self::assertSame('forum', $explained['reason']);
        self::assertSame(MessageCategory::Forums, $explained['category']);
    }

    /**
     * The preference decides, and the two halves of it are independent.
     *
     * This is the whole feature in one fixture: mail Gmail files under Updates,
     * whose headers say Promotions, and whose stored model verdict says Social.
     * Every combination of the two settings picks a different one of those
     * three, which is exactly what makes them worth being settings.
     */
    #[DataProvider('preferences')]
    public function testThePreferenceDecidesWhatSortsTheMail(
        string $source,
        bool $overrideProvider,
        MessageCategory $expected,
        string $because,
    ): void {
        $message = $this->message(
            ['List-Unsubscribe' => '<https://shop.test/u>'],
            'newsletter@shop.test',
            ['INBOX', 'CATEGORY_UPDATES'],
        );

        $message->aiCategory = MessageCategory::Social;

        $sorting                   = new CategorySorting();
        $sorting->source           = $source;
        $sorting->overrideProvider = $overrideProvider;

        self::assertSame($expected, $this->categorizer->categorize($message, [], $sorting), $because);
    }

    /** @return iterable<string, array{string, bool, MessageCategory, string}> */
    public static function preferences(): iterable
    {
        yield 'rules, provider wins' => [
            'rules', false, MessageCategory::Updates,
            "Gmail already sorted it and nothing was asked to disagree",
        ];

        yield 'assistant, provider wins' => [
            'assistant', false, MessageCategory::Updates,
            'choosing the assistant does not on its own overrule the provider',
        ];

        yield 'rules, plMail decides' => [
            'rules', true, MessageCategory::Promotions,
            'the header cascade reads List-Unsubscribe and the model is never asked',
        ];

        yield 'assistant, plMail decides' => [
            'assistant', true, MessageCategory::Social,
            'the model outranks the cascade, which is what choosing it means',
        ];
    }

    /**
     * The model does not outrank somebody you have written to.
     *
     * The correspondent rule is not a classification: it says this is a person
     * the reader corresponds with, which is a fact about them rather than about
     * the mail. A model above it would put a colleague in Promotions on one bad
     * guess, and that is the only mistake in this cascade anybody would notice.
     */
    public function testTheAssistantDoesNotOutrankACorrespondent(): void
    {
        $message = $this->message(['List-Unsubscribe' => '<https://shop.test/u>'], 'kim@example.test');

        $message->aiCategory = MessageCategory::Promotions;

        $sorting                   = new CategorySorting();
        $sorting->source           = 'assistant';
        $sorting->overrideProvider = true;

        self::assertSame(
            MessageCategory::Primary,
            $this->categorizer->categorize($message, ['kim@example.test' => true], $sorting),
        );
    }

    /**
     * No preference is the shipped behaviour, unchanged.
     *
     * Every caller that has no user — the Gmail enrichment path re-deriving a
     * category for a row it just wrote — comes through here, and it must not be
     * silently handed somebody else's settings.
     */
    public function testWithoutAPreferenceNothingChanges(): void
    {
        $message = $this->message(['List-Unsubscribe' => '<https://shop.test/u>'], 'newsletter@shop.test');

        $message->aiCategory = MessageCategory::Social;

        self::assertSame(
            MessageCategory::Promotions,
            $this->categorizer->categorize($message, []),
            'the cascade answers and the verdict stays where it always sat, below it',
        );
    }

    /**
     * The bulk headers reach the model, and their VALUES do not.
     *
     * This is the line that fixed the bug the whole feature was reported for.
     * ClassifyMailHandler used to send a sender, a subject and a body with every
     * header stripped — and a marketing mail with no plain-text part is sent as
     * literally "(no plain text part)", so the model was left with a human
     * sender name and a personal subject and nothing else at all. Measured
     * against the model on a live installation, qwen3:30b-a3b-instruct: that
     * input comes back `primary` under both the old prompt and a rewritten one,
     * and `promotions` under both once this line is present. The prompt was
     * never what was wrong; the evidence was missing.
     *
     * Names only. The unsubscribe URL is a tracking link with an account
     * identifier in it and there is nothing to learn from sending it that its
     * existence has not already said.
     */
    public function testTheModelIsToldWhichBulkHeadersAreThere(): void
    {
        // bulkLine() rather than describe(): it is the whole of the change,
        // and it is static, where describe() would need the handler's five
        // collaborators built to answer a question about a header map.
        $line = new \ReflectionMethod(ClassifyMailHandler::class, 'bulkLine');

        $bulk = $this->message(
            ['List-Unsubscribe' => '<https://shop.test/u?id=secret>', 'Precedence' => 'bulk'],
            'newsletter@shop.test',
        );

        $described = (string) $line->invoke(null, $bulk);

        self::assertStringContainsString('Bulk headers: list-unsubscribe, precedence', $described);
        self::assertStringNotContainsString('secret', $described, 'the value is a tracking link, not evidence');

        // And ordinary correspondence carries no such line, so its absence is
        // as informative as its presence.
        self::assertSame('', (string) $line->invoke(null, $this->message([], 'kim@example.test')));
    }

    private function message(array $headers, string $from, ?array $gmailLabelIds = null): Message
    {
        $message = new Message();
        $message->headers = $headers;
        $message->fromAddress = $from;
        $message->gmailLabelIds = $gmailLabelIds;

        return $message;
    }
}
