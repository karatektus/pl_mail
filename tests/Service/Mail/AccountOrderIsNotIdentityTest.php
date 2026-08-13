<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Service\Mail\AccountCreator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Arranging a list is not the same act as choosing who you are.
 *
 * The report: `pluetzner@outlook.com` was first and carried the Primary badge,
 * Compose opened with From set to it, and dragging `test@pluetzner.de` above it
 * by the handle moved the badge — and the From address — to the account that
 * was dragged. Nothing in the UI said that reordering would do that. The same
 * drag also swapped the two accounts' identity colours, in the sidebar and on
 * every message row at once.
 *
 * The cause was one line: resequence() wrote `isPrimary = 0 === $index`
 * alongside sortOrder, and the dot filter read sortOrder directly.
 *
 * The model chosen is separation. A person dragging a row to tidy a list is
 * arranging a list; they are not changing their return address, and they are
 * certainly not repainting a mark whose whole purpose is to be recognisable.
 * So sortOrder means display position and nothing else, isPrimary is set
 * explicitly by a button that says what it does, and colorIndex is handed out
 * once at creation and never moves.
 *
 * These are the invariants that make that true. They are unit tests on the
 * service rather than controller tests, because the coupling lived in the
 * service and would come back there.
 */
final class AccountOrderIsNotIdentityTest extends TestCase
{
    private AccountCreator $creator;

    protected function setUp(): void
    {
        // create() is not under test here and is the only method that needs the
        // collaborators; resequence/ensurePrimary/makePrimary are pure.
        $this->creator = (new ReflectionClass(AccountCreator::class))->newInstanceWithoutConstructor();
    }

    // ── order does not decide the sender ──────────────────────────────────

    public function testReorderingDoesNotMoveThePrimaryFlag(): void
    {
        $outlook = $this->account('pluetzner@outlook.com', primary: true);
        $test    = $this->account('test@pluetzner.de');

        // The reported drag: the second account pulled above the first.
        $this->creator->resequence([$test, $outlook]);

        self::assertSame(0, $test->sortOrder, 'the dragged account is now first');
        self::assertSame(1, $outlook->sortOrder);

        self::assertFalse($test->isPrimary, 'a drag must not hand over the From address');
        self::assertTrue($outlook->isPrimary, 'the primary is still the account that was chosen');
    }

    public function testReorderingDoesNotRepaintTheAccountDots(): void
    {
        $outlook = $this->account('pluetzner@outlook.com', colorIndex: 0);
        $test    = $this->account('test@pluetzner.de', colorIndex: 1);

        $this->creator->resequence([$test, $outlook]);

        self::assertSame(0, $outlook->colorIndex, 'an account keeps the colour the user has learned');
        self::assertSame(1, $test->colorIndex);
    }

    /** Dragging back was reported to restore it, which it now trivially does. */
    public function testDraggingBackAndForthChangesNothingButPositions(): void
    {
        $outlook = $this->account('pluetzner@outlook.com', primary: true, colorIndex: 0);
        $test    = $this->account('test@pluetzner.de', colorIndex: 1);

        $this->creator->resequence([$test, $outlook]);
        $this->creator->resequence([$outlook, $test]);

        self::assertTrue($outlook->isPrimary);
        self::assertFalse($test->isPrimary);
        self::assertSame([0, 1], [$outlook->colorIndex, $test->colorIndex]);
        self::assertSame([0, 1], [$outlook->sortOrder, $test->sortOrder]);
    }

    // ── the sender is chosen explicitly ───────────────────────────────────

    public function testMakePrimaryMovesTheFlagAndLeavesExactlyOne(): void
    {
        $first  = $this->account('a@example.test', primary: true);
        $second = $this->account('b@example.test');
        $third  = $this->account('c@example.test');

        $this->creator->makePrimary($second, [$first, $second, $third]);

        self::assertFalse($first->isPrimary);
        self::assertTrue($second->isPrimary);
        self::assertFalse($third->isPrimary);
    }

    public function testMakePrimaryLeavesTheOrderAlone(): void
    {
        $first  = $this->account('a@example.test', sortOrder: 0, primary: true);
        $second = $this->account('b@example.test', sortOrder: 1);

        $this->creator->makePrimary($second, [$first, $second]);

        self::assertSame([0, 1], [$first->sortOrder, $second->sortOrder], 'choosing a sender must not resort the list');
    }

    // ── exactly one primary, however the list changed ─────────────────────

    /**
     * The invariant used to be free — position 0 always existed and was always
     * one row. Now that the flag is chosen, losing it has to be caught.
     */
    public function testDeletingThePrimaryPromotesTheFirstRemainingAccount(): void
    {
        $survivor = $this->account('b@example.test');
        $last     = $this->account('c@example.test');

        // The primary is simply not in the list any more.
        $this->creator->resequence([$survivor, $last]);
        $this->creator->ensurePrimary([$survivor, $last]);

        self::assertTrue($survivor->isPrimary, 'a user is never left without a sending account');
        self::assertFalse($last->isPrimary);
    }

    public function testTheFirstAccountAddedIsPrimaryWithoutBeingToldTo(): void
    {
        $only = $this->account('a@example.test');

        $this->creator->ensurePrimary([$only]);

        self::assertTrue($only->isPrimary);
    }

    public function testAnExistingPrimaryIsNeverStolenByEnsurePrimary(): void
    {
        $first  = $this->account('a@example.test');
        $second = $this->account('b@example.test', primary: true);

        $this->creator->ensurePrimary([$first, $second]);

        self::assertFalse($first->isPrimary, 'being first is not being primary any more');
        self::assertTrue($second->isPrimary);
    }

    /**
     * Two primaries is a state the old derivation could not produce and an
     * import can. findOneBy() would then pick between them arbitrarily, so the
     * From address would differ between two loads of the same window.
     */
    public function testADriftedListIsRepairedDownToOnePrimary(): void
    {
        $first  = $this->account('a@example.test', primary: true);
        $second = $this->account('b@example.test', primary: true);

        $this->creator->ensurePrimary([$first, $second]);

        self::assertTrue($first->isPrimary);
        self::assertFalse($second->isPrimary);
    }

    public function testAnEmptyListIsNotAnError(): void
    {
        $this->creator->ensurePrimary([]);

        $this->expectNotToPerformAssertions();
    }

    private function account(
        string $email,
        int $sortOrder = 0,
        bool $primary = false,
        int $colorIndex = 0,
    ): Account {
        $account             = new Account();
        $account->email      = $email;
        $account->username   = $email;
        $account->sortOrder  = $sortOrder;
        $account->isPrimary  = $primary;
        $account->colorIndex = $colorIndex;

        return $account;
    }
}
