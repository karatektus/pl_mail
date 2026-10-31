<?php

declare(strict_types=1);

namespace App\Tests\Jmap\State;

use App\Domain\Enum\Mail\MessageCategory;
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

    public function testAnArrivalRemembersItsTabAndHeldMailHasNone(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordArrival($accountId, MessageCategory::Promotions);
        $state->recordArrival($accountId, MessageCategory::Promotions);
        $state->recordArrival($accountId, MessageCategory::Social);

        self::assertSame([$accountId], $state->drainArrivals(), 'it arrived');
        self::assertEqualsCanonicalizing(
            [MessageCategory::Promotions, MessageCategory::Social],
            $state->drainArrivalCategories()[$accountId],
            'each tab once',
        );
        self::assertSame([], $state->drainArrivalCategories(), 'draining empties it');

        $state->recordArrival($accountId, null);

        self::assertSame([$accountId], $state->drainArrivals(), 'held mail still arrived');
        self::assertSame([], $state->drainArrivalCategories(), 'presence: it has no tab yet');

        $state->recordArrival($accountId);

        self::assertSame([MessageCategory::Primary], $state->drainArrivalCategories()[$accountId], 'Primary is the default');
    }

    /** Released mail is news to a user who chose tabs, and to nobody else. */
    public function testAReleasedArrivalHasATabAndIsNotAnArrivalForEverybody(): void
    {
        $state     = self::getContainer()->get(StateManager::class);
        $accountId = (int) $this->account->id;

        $state->recordReleasedArrival($accountId, MessageCategory::Updates);

        self::assertSame([], $state->drainArrivals(), 'everybody else was told when it arrived');
        self::assertSame([MessageCategory::Updates], $state->drainArrivalCategories()[$accountId], 'presence: users who chose tabs are told now');
    }
}
