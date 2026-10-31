<?php

declare(strict_types=1);

namespace App\Tests\Service\Rule;

use App\Domain\Filter\FilterAstValidator;
use App\Entity\Mail\Message;
use App\Service\Rule\RuleDraftFromMessage;
use PHPUnit\Framework\TestCase;

/**
 * What "Create a filter from this message" puts in the editor.
 *
 * The draft is never saved by this class, so what matters is that it names
 * the sender the way a stored rule may — the whole address, through the same
 * validator — and that a message with no usable sender opens a blank editor
 * rather than a broken one.
 */
final class RuleDraftFromMessageTest extends TestCase
{
    public function testTheSenderBecomesTheConditionAndTheNameTheRuleName(): void
    {
        $rule = $this->draft('Cyber Lettre <CyberVeille@CyberVeille.eu>', '"Cyber Lettre"');

        self::assertSame(
            ['operator' => 'AND', 'conditions' => [['fromAddress' => 'cyberveille@cyberveille.eu']]],
            $rule->conditions,
        );
        self::assertSame('Cyber Lettre', $rule->name);
        self::assertSame([], $rule->actions, 'what the filter does is the reader\'s choice, not a guess');
        self::assertNull($rule->id);
        self::assertNull($rule->account, 'every account, as a rule written from scratch starts');
    }

    public function testWithoutADisplayNameTheAddressNamesTheRule(): void
    {
        self::assertSame('news@example.org', $this->draft('news@example.org', null)->name);
    }

    public function testAMessageWithNoUsableSenderOpensABlankEditor(): void
    {
        foreach ([null, '', 'undisclosed-recipients', '@example.org'] as $from) {
            $rule = $this->draft($from, 'Somebody');

            self::assertSame([], $rule->conditions, sprintf('"%s" became a condition', (string) $from));
            self::assertEmpty($rule->name);
        }
    }

    private function draft(?string $from, ?string $name): \App\Entity\Rule\MailRule
    {
        $message              = new Message();
        $message->fromAddress = $from;
        $message->fromName    = $name;

        return new RuleDraftFromMessage(new FilterAstValidator())->draft($message);
    }
}
