<?php

declare(strict_types=1);

namespace App\Tests\Service\Insight;

use App\Domain\Enum\Insight\InsightKind;
use App\Entity\Insight\MailInsight;
use App\Entity\User\User;
use App\Repository\Insight\MailInsightRepository;
use App\Service\Insight\InsightPane;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The one sentence that decides whether the mail list wears a strip.
 *
 * Held against a stubbed repository rather than the schema, unlike its sibling
 * InsightHarvesterTest — and the difference is deliberate. That file tests
 * LANDING, where every promise is the kind that passes in a mock and fails on
 * a unique constraint. This tests a rule made entirely of a boolean, a list
 * and two instants; the query it reads from is the repository's own business,
 * and booting a kernel to reach it would buy nothing but a slower test of the
 * same three branches.
 *
 * What is worth pinning is that the three ways of returning nothing are three
 * and not one — off, empty, dismissed — and that "dismissed" is a position on
 * a moving list rather than an off-switch: a fact the user has never seen
 * brings the strip back, and only that.
 */
final class InsightPaneTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-21 09:00:00', new DateTimeZone('UTC'));
    }

    /** The off-switch short-circuits: a disabled strip must not even ask the database. */
    public function testASwitchedOffStripRendersNothingAndAsksNothing(): void
    {
        $user = $this->user();
        $user->setSetting(User::SETTING_INSIGHT_PANE_DISABLED, true);

        $repository = $this->createMock(MailInsightRepository::class);
        $repository->expects(self::never())->method('upcomingForUser');

        self::assertSame([], new InsightPane($repository)->rowsFor($user, $this->now));
        self::assertTrue(new InsightPane($repository)->isDisabledFor($user));
    }

    /** Absent means ON — the whole reason the key stores DISABLED. */
    public function testAUserWhoNeverTouchedTheSettingHasTheStrip(): void
    {
        $user = $this->user();
        $rows = [$this->insight('DHL parcel', $this->now->modify('-1 hour'))];

        $pane = $this->pane($rows);

        self::assertFalse($pane->isDisabledFor($user));
        self::assertSame($rows, $pane->rowsFor($user, $this->now));
    }

    /** Nothing to say is the common case, and it must be indistinguishable from off. */
    public function testNoInsightsRendersNothing(): void
    {
        self::assertSame([], $this->pane([])->rowsFor($this->user(), $this->now));
    }

    public function testADismissalHidesRowsThatAreOlderThanIt(): void
    {
        $user = $this->user();

        $insight = $this->insight('DHL parcel', $this->now->modify('+2 days'));
        $insight->restoreCreatedAt($this->now->modify('-2 hours'));

        $this->dismiss($user, $this->now->modify('-1 hour'));

        self::assertSame([], $this->pane([$insight])->rowsFor($user, $this->now));
    }

    /**
     * And comes back for one it has never shown. This is the difference
     * between "not now" and "never" that the timestamp exists to express.
     */
    public function testAnInsightNewerThanTheDismissalBringsTheStripBack(): void
    {
        $user = $this->user();

        $old = $this->insight('DHL parcel', $this->now->modify('+2 days'));
        $old->restoreCreatedAt($this->now->modify('-2 hours'));

        $fresh = $this->insight('Flight LH400', $this->now->modify('+3 days'));
        $fresh->restoreCreatedAt($this->now->modify('-1 minute'));

        $this->dismiss($user, $this->now->modify('-1 hour'));

        // Both rows, not just the new one: the strip is dismissed or it is
        // not, and half a strip would be a third state nothing can express.
        self::assertSame([$old, $fresh], $this->pane([$old, $fresh])->rowsFor($user, $this->now));
    }

    /**
     * A jsonb bag is free-form, so a value that is not a date is possible —
     * from an older format, a restore, a hand-edited row. It is read as "never
     * dismissed" rather than thrown, because a strip that comes back once is a
     * smaller failure than a strip that refuses to render.
     */
    public function testAMalformedStoredTimestampIsTreatedAsNoDismissal(): void
    {
        $user = $this->user();
        $user->setSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT, 'yesterday-ish');

        $insight = $this->insight('DHL parcel', $this->now->modify('+2 days'));
        $insight->restoreCreatedAt($this->now->modify('-2 days'));

        self::assertNull($this->pane([])->dismissedAt($user));
        self::assertSame([$insight], $this->pane([$insight])->rowsFor($user, $this->now));
    }

    /** An empty string is the same non-answer as an absent key, not a date at epoch. */
    public function testAnEmptyStoredTimestampIsTreatedAsNoDismissal(): void
    {
        $user = $this->user();
        $user->setSetting(User::SETTING_INSIGHT_PANE_DISMISSED_AT, '');

        self::assertNull($this->pane([])->dismissedAt($user));
    }

    /**
     * The cap is asked of the repository rather than applied to its answer —
     * a strip that fetched thirty rows to draw three would be paying for the
     * radar panel's query on every mailbox load.
     */
    public function testTheQueryIsAskedForAtMostMaxRows(): void
    {
        $user = $this->user();

        $repository = $this->createMock(MailInsightRepository::class);
        $repository
            ->expects(self::once())
            ->method('upcomingForUser')
            ->with($user, $this->now, self::anything(), self::anything(), InsightPane::MAX_ROWS)
            ->willReturn([]);

        new InsightPane($repository)->rowsFor($user, $this->now);
    }

    /** Three, and the constant is the number every reader must agree on. */
    public function testTheStripCarriesNoMoreThanMaxRows(): void
    {
        $rows = [];

        for ($i = 0; $i < InsightPane::MAX_ROWS; ++$i) {
            $rows[] = $this->insight('Parcel ' . $i, $this->now->modify(sprintf('+%d days', $i + 1)));
        }

        self::assertCount(InsightPane::MAX_ROWS, $this->pane($rows)->rowsFor($this->user(), $this->now));
        self::assertSame(3, InsightPane::MAX_ROWS);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A STUB and not a mock: everything below is asserted on the return value,
     * so the repository is a source of rows and never a thing under test. Only
     * the two cases that are genuinely about the call — that a disabled strip
     * does not make one, and that the cap travels into it — reach for a mock.
     *
     * @param list<MailInsight> $rows
     */
    private function pane(array $rows): InsightPane
    {
        $repository = $this->createStub(MailInsightRepository::class);
        $repository->method('upcomingForUser')->willReturn($rows);

        return new InsightPane($repository);
    }

    private function dismiss(User $user, DateTimeImmutable $at): void
    {
        $user->setSetting(
            User::SETTING_INSIGHT_PANE_DISMISSED_AT,
            $at->format(DateTimeImmutable::ATOM),
        );
    }

    private function user(): User
    {
        $user        = new User();
        $user->email = 'pane@example.test';

        return $user;
    }

    private function insight(string $title, DateTimeImmutable $happensAt): MailInsight
    {
        $insight            = new MailInsight();
        $insight->kind      = InsightKind::Parcel;
        $insight->title     = $title;
        $insight->payload   = [];
        $insight->happensAt = $happensAt;
        $insight->extractor = 'pane-test';
        $insight->dedupeKey = uniqid('pane-', true);

        // createdAt is stamped on persist, and nothing here persists. The
        // dismissal rule compares against it, so every row that takes part in
        // that comparison sets it explicitly rather than leaning on a
        // lifecycle callback this test never fires.
        $insight->restoreCreatedAt($happensAt->modify('-1 day'));

        return $insight;
    }
}
