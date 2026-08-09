<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * The Session publishes how much of an account the server has actually got.
 *
 * The question this answers is "why is a mail I know exists not in search?".
 * From the phone the two possible answers look identical — an empty result —
 * and they want opposite reactions: *the server has not fetched it yet, wait*
 * versus *this device is behind, refresh*. `sync.backfill_target` is what tells
 * them apart, and before this it was invisible outside the web settings pane.
 *
 * There is no retention setting to publish any more; the server intends to hold
 * everything, so an unfinished backfill is the only honest gap left. The
 * numbers are asserted against the account settings rather than against
 * literals, because the claim is that they *track* the sync engine: a published
 * number that has drifted from what the engine believes is worse than no
 * number, since a client will explain a gap that is not there.
 */
final class SyncWindowTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    /** A fresh account has never completed a backfill. */
    public function testAnUntouchedAccountPublishesAnUnfinishedBackfill(): void
    {
        $sync = $this->syncWindow($this->accountId());

        self::assertNull($sync['backfillTarget']);
        self::assertTrue($sync['backfillPending']);
        self::assertArrayNotHasKey('syncLimit', $sync, 'the retired cap is not published');
    }

    /** 0 is "the whole mailbox", which is the only settled value there is. */
    public function testACompletedBackfillIsNotPending(): void
    {
        $this->account->backfillTarget = 0;
        $this->em->flush();

        $sync = $this->syncWindow($this->accountId());

        self::assertSame(0, $sync['backfillTarget']);
        self::assertFalse($sync['backfillPending']);
    }

    /**
     * An account the retired sync cap stopped short. The number it settled on
     * is still published — a client showing "1500 messages in so far" is
     * telling the truth — but it must not read as finished, or the mail below
     * it is never explained and never asked for.
     */
    public function testAnAccountLeftCappedByTheOldLimitIsStillPending(): void
    {
        $this->account->backfillTarget = 1500;
        $this->em->flush();

        $sync = $this->syncWindow($this->accountId());

        self::assertSame(1500, $sync['backfillTarget']);
        self::assertTrue($sync['backfillPending']);
    }

    /**
     * backfillPending is derived from the same number the sync engine decides
     * on, so the Session cannot tell a client the fetch is finished while the
     * engine keeps walking back.
     */
    public function testThePublishedFlagCannotDisagreeWithTheSyncEngine(): void
    {
        $this->account->backfillTarget = 2000;
        $this->em->flush();

        self::assertSame(
            $this->account->needsBackfill(),
            $this->syncWindow($this->accountId())['backfillPending'],
        );
    }

    /** The state is per account: two accounts, two answers. */
    public function testEachAccountCarriesItsOwnBackfillState(): void
    {
        $second = $this->secondAccount();
        $this->account->backfillTarget = 0;
        $second->backfillTarget = 1000;
        $this->em->flush();

        self::assertFalse($this->syncWindow($this->accountId())['backfillPending']);
        self::assertTrue($this->syncWindow((string) $second->id)['backfillPending']);
    }

    /**
     * The capability has no methods, so it is easy to leave out of SUPPORTED —
     * and a client that lists a capability it depends on in "using", which is
     * the obvious thing to do, would then have its whole request refused with
     * unknownCapability rather than merely losing the extension.
     */
    public function testTheUrnIsAdvertisedAndAClientMayDeclareIt(): void
    {
        $session = $this->sessions->build($this->user);

        self::assertArrayHasKey(Capability::SYNC, $session['capabilities']);
        self::assertContains(Capability::SYNC, Capability::SUPPORTED);
    }

    /**
     * @return array<string,mixed>
     */
    private function syncWindow(string $accountId): array
    {
        $session = $this->sessions->build($this->user);

        return $session['accounts'][$accountId]['accountCapabilities'][Capability::SYNC];
    }
}
