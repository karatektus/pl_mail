<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Mail;

use App\Domain\Enum\Mail\ComposeAction;
use PHPUnit\Framework\TestCase;

/**
 * What a stored action-bar order is read as.
 *
 * The bag is untyped JSON from any version, so the claim is that whatever is
 * in it draws every button exactly once: the ones it names where it put them,
 * the rest after them in the default order.
 */
final class ComposeActionTest extends TestCase
{
    /** The bar's order before it could be arranged. A test pins it because nothing else says it. */
    public function testTheDefaultOrderIsTheBarsOld(): void
    {
        self::assertSame(
            ['format', 'attach', 'link', 'emoji', 'image', 'encrypt', 'signature', 'template', 'assistant', 'service', 'discard'],
            array_map(static fn (ComposeAction $action): string => $action->value, ComposeAction::cases()),
        );
    }

    public function testNothingStoredIsTheDefault(): void
    {
        self::assertSame(ComposeAction::cases(), ComposeAction::ordered(null));
        self::assertSame(ComposeAction::cases(), ComposeAction::ordered('attach'));
        self::assertSame(ComposeAction::cases(), ComposeAction::ordered([]));
    }

    /** A button the stored order does not name — new in a later version — goes at the end. */
    public function testWhatAnOrderLeavesOutFollowsIt(): void
    {
        $order = ComposeAction::ordered(['discard', 'emoji']);

        self::assertSame([ComposeAction::Discard, ComposeAction::Emoji, ComposeAction::Format], array_slice($order, 0, 3));
        self::assertCount(count(ComposeAction::cases()), $order);
    }

    /** Written by another version, or by hand: what is not a button is passed over, a repeat counts once. */
    public function testWhatIsNotAButtonIsPassedOver(): void
    {
        $order = ComposeAction::ordered(['teleport', 42, 'image', 'image', null, 'link']);

        self::assertSame([ComposeAction::Image, ComposeAction::Link, ComposeAction::Format], array_slice($order, 0, 3));
        self::assertCount(count(ComposeAction::cases()), $order);
    }
}
