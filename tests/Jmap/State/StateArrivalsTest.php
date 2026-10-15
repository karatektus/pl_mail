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
}
