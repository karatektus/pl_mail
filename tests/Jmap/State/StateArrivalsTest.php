<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Domain\DTO\Push\NotifyScope;
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

        $state->recordArrival($accountId, [NotifyScope::PRIMARY]);
        $state->recordArrival($accountId, [NotifyScope::PRIMARY]);

        self::assertSame([$accountId => [NotifyScope::PRIMARY]], $state->drainArrivals(), 'the account arrived once');
        self::assertSame([], $state->drainArrivals(), 'a second drain finds the buffer empty');
    }

    public function testArrivalsAndDirtyChangesAreDrainedIndependently(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId, [NotifyScope::PRIMARY]);
        $state->recordUpdated($accountId, JmapObjectType::Email, '9');
        $this->em->flush();

        $dirty = $state->drainDirty();

        self::assertArrayHasKey($accountId, $dirty, 'the update is still drained as dirty');
        self::assertSame([$accountId => [NotifyScope::PRIMARY]], $state->drainArrivals(), 'the arrival is still drained as an arrival');
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

    /**
     * An arrival is kept with the scopes it fell under (#369), each once per
     * account, and one that fell under none is no arrival at all.
     */
    public function testAnArrivalKeepsItsScopesOnceEach(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId, ['category:promotions']);
        $state->recordArrival($accountId, ['category:promotions', 'label:7']);

        self::assertSame([$accountId => ['category:promotions', 'label:7']], $state->drainArrivals(), 'each scope once');

        $state->recordArrival($accountId, []);

        self::assertSame([], $state->drainArrivals(), 'mail under no scope, held mail say, is nobody\'s arrival');
    }
}
