<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Tests\Jmap\JmapTestCase;

/**
 * Arrivals are a second pending buffer beside the dirty set, and the two must
 * stay apart: a create is not an arrival, and an arrival is not a change that
 * a JMAP client needs to hear about by its own state token.
 *
 * Each test asserts the presence of the thing it means as well as the absence
 * of the other, so an empty result can only mean the buffer was empty.
 */
final class StateArrivalsTest extends JmapTestCase
{
    public function testAnArrivalIsRecordedOncePerAccountAndDrainingEmptiesIt(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId);
        $state->recordArrival($accountId);

        self::assertSame([$accountId], $state->drainArrivals(), 'the account arrived once');
        self::assertSame([], $state->drainArrivals(), 'a second drain finds the buffer empty');
    }

    public function testArrivalsAndDirtyChangesAreDrainedIndependently(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId);
        $state->recordUpdated($accountId, JmapObjectType::Email, '9');
        $this->em->flush();

        $dirty = $state->drainDirty();

        self::assertArrayHasKey($accountId, $dirty, 'the update is still drained as dirty');
        self::assertSame([$accountId], $state->drainArrivals(), 'the arrival is still drained as an arrival');
    }

    public function testAnUpdateIsNotAnArrival(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordUpdated($accountId, JmapObjectType::Email, '9');
        $this->em->flush();

        self::assertSame([], $state->drainArrivals(), 'an update alone records no arrival');
        self::assertArrayHasKey($accountId, $state->drainDirty(), 'presence: the update is dirty');
    }

    public function testAnArrivalIsPrimaryUnlessItIsSaidNotToBe(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId, primary: false);

        self::assertSame([$accountId], $state->drainArrivals(), 'it arrived');
        self::assertSame([], $state->drainPrimaryArrivals(), 'a Promotions arrival is not Primary mail');

        $state->recordArrival($accountId);

        self::assertSame([$accountId], $state->drainPrimaryArrivals(), 'presence: an arrival is Primary by default');
        self::assertSame([], $state->drainPrimaryArrivals(), 'draining empties it');
    }

    /** Released mail is news to the user who wants Primary only, and to nobody else. */
    public function testAReleasedArrivalIsOnlyEverPrimary(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordReleasedArrival($accountId);

        self::assertSame([], $state->drainArrivals(), 'everybody else was told when it arrived');
        self::assertSame([$accountId], $state->drainPrimaryArrivals(), 'presence: Primary-only is told now');
    }
}
