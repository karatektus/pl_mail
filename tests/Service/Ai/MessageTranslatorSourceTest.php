<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Mail\Message;
use App\Service\Ai\MessageTranslator;
use PHPUnit\Framework\TestCase;

/**
 * What of a message is sent for translation (#713), and when nothing can be.
 */
final class MessageTranslatorSourceTest extends TestCase
{
    public function testAShortMessageIsSentWhole(): void
    {
        $source = MessageTranslator::sourceOf($this->message('שלום, אנא שלחו את החשבונית עד יום חמישי.'));

        self::assertSame('שלום, אנא שלחו את החשבונית עד יום חמישי.', $source->text);
        self::assertFalse($source->isPartial);
        self::assertFalse($source->leavesOutQuote);
        self::assertSame(hash('sha256', $source->text), $source->hash());
    }

    /** Cut at a line break near the budget, never past it, and said to be cut. */
    public function testALongMessageIsCutAtALineBreakAndSaidToBePartial(): void
    {
        $paragraph = str_repeat('Ein Satz über die Rechnung. ', 9) . "\n";
        $source    = MessageTranslator::sourceOf($this->message(str_repeat($paragraph, 60)));

        self::assertTrue($source->isPartial);
        self::assertLessThanOrEqual(MessageTranslator::TEXT_BUDGET, mb_strlen($source->text));
        self::assertGreaterThan((int) (MessageTranslator::TEXT_BUDGET * 0.8), mb_strlen($source->text));
        self::assertStringEndsWith('Rechnung.', $source->text);
    }

    public function testAForwardWithNothingOfItsOwnIsSentWhole(): void
    {
        $body   = "---------- Forwarded message ---------\nFrom: Anna <anna@example.test>\nSubject: Rechnung\n\nBitte bis Donnerstag zahlen.";
        $source = MessageTranslator::sourceOf($this->message($body));

        self::assertStringContainsString('Bitte bis Donnerstag zahlen.', $source->text);
        self::assertFalse($source->leavesOutQuote);
    }

    /** A body only a browser holds is never offered: there is nothing to send. */
    public function testAMessageTheServerCannotReadHasNoReadableBody(): void
    {
        $encrypted = $this->message('');
        $encrypted->crypto = MessageCrypto::PgpEncrypted;

        $decrypted = $this->message('Klartext');
        $decrypted->crypto          = MessageCrypto::PgpEncrypted;
        $decrypted->plaintextSource = PlaintextSource::Client;

        $sealed = $this->message('Entwurf');
        $sealed->sealedDraft = true;

        self::assertFalse(MessageTranslator::hasReadableBody($encrypted));
        self::assertTrue(MessageTranslator::hasReadableBody($decrypted));
        self::assertFalse(MessageTranslator::hasReadableBody($sealed));
        self::assertTrue(MessageTranslator::hasReadableBody($this->message('Hallo')));
    }

    private function message(string $body): Message
    {
        $message           = new Message();
        $message->bodyText = $body;

        return $message;
    }
}
