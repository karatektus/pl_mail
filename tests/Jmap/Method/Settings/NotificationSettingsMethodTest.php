<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Settings;

use App\Domain\DTO\Push\NotifyScope;
use App\Entity\User\User;
use App\Jmap\Method\Settings\NotificationSettingsGetMethod;
use App\Jmap\Method\Settings\NotificationSettingsSetMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * NotificationSettings/get and /set: the switches the phone apps apply to
 * their own pushes (#369), from the one place the web and every browser use.
 *
 * The claims: the list is always spelled out (Primary for somebody who never
 * chose, never null), a set replaces it and is what the web then shows, a key
 * that could never be a scope is refused with nothing applied, and the object
 * is the user's — an accountId is refused, as for Appearance.
 */
final class NotificationSettingsMethodTest extends JmapTestCase
{
    public function testItSpellsOutPrimaryForSomebodyWhoNeverChose(): void
    {
        $object = $this->get()['list'][0];

        self::assertSame(['id' => 'singleton', 'scopes' => [NotifyScope::PRIMARY], 'skipRead' => false], $object);
    }

    public function testASetIsWhatTheWebThenShows(): void
    {
        $result = $this->set(['scopes' => ['category:social', 'label:12', 'category:social'], 'skipRead' => true]);

        self::assertSame(['scopes' => ['category:social', 'label:12']], $result['updated']['singleton'], 'the duplicate, reported as dropped');
        self::assertNotSame($result['oldState'], $result['newState']);

        $this->em->clear();
        $user = $this->em->find(User::class, $this->user->id);
        self::assertInstanceOf(User::class, $user);

        self::assertSame(['category:social', 'label:12'], $user->notifyScopes);
        self::assertTrue($user->notifySkipsRead);
    }

    public function testAKeyThatCouldNeverBeAScopeIsRefusedWithNothingApplied(): void
    {
        $result = $this->set(['scopes' => ['category:social', 'everything'], 'skipRead' => true]);

        self::assertSame('invalidProperties', $result['notUpdated']['singleton']['type']);
        self::assertSame([NotifyScope::PRIMARY], $this->user->notifyScopes);
        self::assertFalse($this->user->notifySkipsRead, 'not half-applied');

        $result = $this->set(['skipRead' => 'yes']);

        self::assertSame('invalidProperties', $result['notUpdated']['singleton']['type'], 'a real boolean, not a string');
    }

    public function testItIsTheUsersNotAnAccounts(): void
    {
        $this->expectException(MethodException::class);

        self::getContainer()->get(NotificationSettingsGetMethod::class)->handle(['accountId' => $this->accountId()], $this->context());
    }

    public function testTheSessionAdvertisesItAtTheTopLevelOnly(): void
    {
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        self::assertContains(Capability::NOTIFICATIONS, Capability::SUPPORTED);
        self::assertSame([NotifyScope::PRIMARY], $session['capabilities'][Capability::NOTIFICATIONS]['defaultScopes']);

        foreach ($session['accounts'] as $account) {
            self::assertArrayNotHasKey(Capability::NOTIFICATIONS, $account['accountCapabilities']);
        }
    }

    /** @return array<string,mixed> */
    private function get(): array
    {
        return self::getContainer()->get(NotificationSettingsGetMethod::class)->handle(['ids' => null], $this->context());
    }

    /**
     * @param array<string,mixed> $patch
     *
     * @return array<string,mixed>
     */
    private function set(array $patch): array
    {
        return self::getContainer()->get(NotificationSettingsSetMethod::class)->handle(
            ['update' => ['singleton' => $patch]],
            $this->context(),
        );
    }
}
