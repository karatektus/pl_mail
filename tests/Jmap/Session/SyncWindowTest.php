<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * The Session publishes how much mail an account actually holds.
 *
 * The question this answers is "why is a mail I know exists not in search?".
 * From the phone the two possible answers look identical — an empty result —
 * and they want opposite reactions: *the server only keeps the newest N, raise
 * the limit* versus *it is still being fetched, wait*. `sync.message_limit` and
 * `sync.backfill_target` are what tell them apart, and before this they were
 * invisible outside the web settings pane.
 *
 * The numbers are asserted against the account settings rather than against
 * literals, because the claim is that they *track* the setting: a published
 * number that has drifted from the sync engine's actual cap is worse than no
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

    /** A fresh account has no cap and has never completed a backfill. */
    public function testAnUntouchedAccountPublishesAnUncappedUnfinishedWindow(): void
    {
        $sync = $this->syncWindow($this->accountId());

        self::assertSame(0, $sync['syncLimit']);
        self::assertNull($sync['backfillTarget']);
        self::assertTrue($sync['backfillPending']);
    }

    public function testTheNumbersTrackTheAccountSettings(): void
    {
        $this->account->syncLimit = 2000;
        $this->account->backfillTarget = 500;
        $this->em->flush();

        $sync = $this->syncWindow($this->accountId());

        self::assertSame(2000, $sync['syncLimit']);
        self::assertSame(500, $sync['backfillTarget']);
        self::assertTrue($sync['backfillPending'], '1500 messages of the cap are still to fetch');
    }

    /**
     * backfillPending is derived from the same two numbers the sync engine
     * decides on, so the Session cannot tell a client the fetch is finished
     * while the engine keeps walking back.
     */
    public function testTheWindowIsClosedOnceTheBackfillHasReachedTheCap(): void
    {
        $this->account->syncLimit = 2000;
        $this->account->backfillTarget = 2000;
        $this->em->flush();

        self::assertFalse($this->syncWindow($this->accountId())['backfillPending']);
        self::assertSame($this->account->needsBackfill(), $this->syncWindow($this->accountId())['backfillPending']);
    }

    /** 0 is "the whole mailbox", and nothing can widen that. */
    public function testACompletedUncappedBackfillIsNotPending(): void
    {
        $this->account->backfillTarget = 0;
        $this->em->flush();

        $sync = $this->syncWindow($this->accountId());

        self::assertSame(0, $sync['backfillTarget']);
        self::assertFalse($sync['backfillPending']);
    }

    /**
     * Graph cannot honour the cap (its delta query is neither newest-first nor
     * resumable before the last page), so the setting is not offered for a
     * Microsoft account. Publishing a stored number anyway would have a client
     * explain a gap that does not exist — the mailbox is complete.
     */
    public function testAMicrosoftAccountPublishesNoCapWhateverIsStored(): void
    {
        $this->account->authType = AuthType::OAuth2->value;
        $this->account->oauthProvider = MailProvider::Microsoft->value;
        $this->account->syncLimit = 5000;
        $this->em->flush();

        self::assertFalse($this->account->supportsSyncLimit());
        self::assertSame(0, $this->syncWindow($this->accountId())['syncLimit']);
    }

    /** The window is per account: two accounts, two answers. */
    public function testEachAccountCarriesItsOwnWindow(): void
    {
        $second = $this->secondAccount();
        $this->account->syncLimit = 1000;
        $second->syncLimit = 25000;
        $this->em->flush();

        self::assertSame(1000, $this->syncWindow($this->accountId())['syncLimit']);
        self::assertSame(25000, $this->syncWindow((string) $second->id)['syncLimit']);
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
