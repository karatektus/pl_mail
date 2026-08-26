<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiSettings;
use App\Entity\Mail\Message;
use App\Service\Mail\MessageCategorizer;
use PHPUnit\Framework\TestCase;

/**
 * Where a model's opinion is allowed to count, which is almost nowhere.
 *
 * The design is that the deterministic cascade decides and the model breaks
 * ties — so the tests worth having are the ones that prove it CANNOT overrule a
 * rule. A model that quietly moved mail a documented header had placed would
 * make a tab's contents depend on which model happened to be installed, with
 * nothing on the row to say so, and it would do it unattended on every message
 * that arrives.
 *
 * The categoriser itself never calls anything: it reads a verdict stored
 * asynchronously. That is what keeps an HTTP round trip out of every list
 * render and every details panel.
 */
final class MailClassificationTest extends TestCase
{
    private MessageCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new MessageCategorizer();
    }

    /** The one place it counts: nothing else recognised the message. */
    public function testAVerdictIsUsedWhenNoRuleRecognisedTheMessage(): void
    {
        $mail = $this->message(['Subject' => 'hello'], 'someone@example.test');
        $mail->aiCategory = MessageCategory::Promotions;

        $explanation = $this->categorizer->explain($mail, []);

        self::assertSame(MessageCategory::Promotions, $explanation['category']);
        self::assertSame('ai', $explanation['reason']);
    }

    /** Without one, the same message is Primary by default, as before. */
    public function testWithoutAVerdictNothingChanges(): void
    {
        $explanation = $this->categorizer->explain($this->message([], 'someone@example.test'), []);

        self::assertSame(MessageCategory::Primary, $explanation['category']);
        self::assertSame('default', $explanation['reason']);
    }

    /**
     * A header that matched outranks the model, always. This is the assertion
     * the whole design exists to make true.
     */
    public function testAMatchedHeaderOutranksTheModel(): void
    {
        $mail = $this->message(['List-Unsubscribe' => '<https://shop.test/u>'], 'news@shop.test');
        $mail->aiCategory = MessageCategory::Primary;

        $explanation = $this->categorizer->explain($mail, []);

        self::assertSame(MessageCategory::Promotions, $explanation['category']);
        self::assertSame('promotion', $explanation['reason'], 'a documented rule must not be silently overruled');
    }

    /** So does somebody the user has actually written to. */
    public function testTheCorrespondentOverrideOutranksTheModel(): void
    {
        $mail = $this->message([], 'kim@example.test');
        $mail->aiCategory = MessageCategory::Promotions;

        $explanation = $this->categorizer->explain($mail, ['kim@example.test' => true]);

        self::assertSame(MessageCategory::Primary, $explanation['category']);
        self::assertSame('correspondent', $explanation['reason']);
    }

    /** And so does the provider, where there is one. */
    public function testGmailsOwnLabelOutranksTheModel(): void
    {
        $mail = $this->message([], 'x@y.test', ['INBOX', 'CATEGORY_UPDATES']);
        $mail->aiCategory = MessageCategory::Forums;

        $explanation = $this->categorizer->explain($mail, []);

        self::assertSame('gmail', $explanation['reason']);
        self::assertSame(MessageCategory::Updates, $explanation['category']);
    }

    /**
     * Switching the feature off has to be enough on its own. Nothing clears the
     * column, so the cascade must simply stop reading it — which it does,
     * because it only reads it where it would otherwise have said "default",
     * and that branch is reached identically whether the feature is on or off.
     *
     * This is the test that says a stale verdict from a model somebody removed
     * months ago is harmless as long as the rules recognise the mail.
     */
    public function testAStaleVerdictCannotResurrectItselfPastARule(): void
    {
        $mail = $this->message(['List-Post' => '<mailto:list@x.test>'], 'list@x.test');
        $mail->aiCategory = MessageCategory::Social;

        self::assertSame('forum', $this->categorizer->explain($mail, [])['reason']);
    }

    /**
     * @param array<string,mixed> $headers
     * @param list<string>|null   $gmailLabelIds
     */
    private function message(array $headers, string $from, ?array $gmailLabelIds = null): Message
    {
        $message                = new Message();
        $message->headers       = $headers;
        $message->fromAddress   = $from;
        $message->gmailLabelIds = $gmailLabelIds;

        return $message;
    }
}
