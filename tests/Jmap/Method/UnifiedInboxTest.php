<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method;

use App\Jmap\Method\Mail\UnifiedInboxChangesMethod;
use App\Jmap\Method\Mail\UnifiedInboxGetMethod;
use App\Jmap\Session\SessionBuilder;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Jmap\JmapTestCase;

/**
 * The reader's unified inboxes over JMAP (#265): the same inboxes, in the same
 * order, over the same accounts as the web's sidebar, and a change to them
 * reported through every account's log.
 */
final class UnifiedInboxTest extends JmapTestCase
{
    public function testTheSessionAdvertisesTheCapabilityAndItsIcons(): void
    {
        $second  = $this->secondAccount();
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);
        $urn     = 'urn:plmail:params:jmap:unifiedinboxes';

        self::assertContains('briefcase', $session['capabilities'][$urn]['icons']);
        self::assertArrayHasKey($urn, $session['accounts'][$this->accountId()]['accountCapabilities']);
        self::assertArrayHasKey($urn, $session['accounts'][(string) $second->id]['accountCapabilities']);
    }

    public function testTheInboxesComeInOrderWithTheirAccountsAsAList(): void
    {
        $second  = $this->secondAccount();
        $inboxes = self::getContainer()->get(UnifiedInboxes::class);
        $work    = $inboxes->create($this->user, 'Work', 'briefcase');

        $inboxes->assign($this->user, $second, $work);

        $list = $this->get()['list'];

        self::assertSame([null, 'Work', null], array_column($list, 'name'));
        self::assertSame([false, false, true], array_column($list, 'isOther'));
        self::assertSame([0, 1, 2], array_column($list, 'sortOrder'));
        self::assertSame([$this->accountId()], $list[0]['accountIds']);
        self::assertSame([(string) $second->id], $list[1]['accountIds']);
        self::assertSame([], $list[2]['accountIds']);
        self::assertSame('briefcase', $list[1]['icon']);
    }

    public function testAskingForIdsAndPropertiesNarrowsTheAnswer(): void
    {
        $first  = self::getContainer()->get(UnifiedInboxes::class)->first($this->user);
        $result = $this->get(ids: [(string) $first->id, '999999999'], properties: ['icon']);

        self::assertSame([['id' => (string) $first->id, 'icon' => 'inbox']], $result['list']);
        self::assertSame(['999999999'], $result['notFound']);
    }

    public function testAChangeIsReportedThroughEveryAccountsChanges(): void
    {
        $second       = $this->secondAccount();
        $before       = $this->get()['state'];
        $beforeSecond = $this->get((string) $second->id)['state'];

        $work = self::getContainer()->get(UnifiedInboxes::class)->create($this->user, 'Work', 'briefcase');

        foreach ([[$this->accountId(), $before], [(string) $second->id, $beforeSecond]] as [$accountId, $since]) {
            $changes = self::getContainer()->get(UnifiedInboxChangesMethod::class)->handle(
                ['accountId' => $accountId, 'sinceState' => $since],
                $this->context(),
            );

            self::assertSame([(string) $work->id], $changes['created']);
            self::assertNotSame($since, $changes['newState']);
        }
    }

    /**
     * @param list<string>|null $ids
     * @param list<string>|null $properties
     *
     * @return array<string, mixed>
     */
    private function get(?string $accountId = null, ?array $ids = null, ?array $properties = null): array
    {
        return self::getContainer()->get(UnifiedInboxGetMethod::class)->handle(
            ['accountId' => $accountId ?? $this->accountId(), 'ids' => $ids, 'properties' => $properties],
            $this->context(),
        );
    }
}
