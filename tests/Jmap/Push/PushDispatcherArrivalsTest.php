<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Push\PushDispatcher;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\User\UserRepository;
use App\Tests\Jmap\JmapTestCase;
use Psr\Log\NullLogger;

/**
 * The in-app browser/PWA device wants "new mail" and nothing else, so its row
 * is marked arrivals-only and is told only when mail actually arrived in one of
 * its user's accounts. Every other subscription keeps receiving every change.
 *
 * Each absence below has a presence companion in the same test, so an empty
 * send list cannot pass for a dispatcher that dropped everything.
 */
final class PushDispatcherArrivalsTest extends JmapTestCase
{
    public function testAnArrivalsOnlyBrowserIsSilentOnAnUpdateAndToldOnAnArrival(): void
    {
        $this->subscribe($this->arrivalsOnlyBrowser('pwa-device'));

        $webPush = new RecordingArrivalsSender(PushTransport::WebPush);

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: []);

        self::assertCount(0, $webPush->sent, 'an update is not new mail');

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: [$this->ownAccountId()]);

        self::assertCount(1, $webPush->sent, 'presence: mail arrived, so the PWA is told');
        self::assertSame('pwa-device', $webPush->sent[0]->deviceClientId);
    }

    public function testAJmapClientBrowserIsStillToldOfAnUpdate(): void
    {
        $this->subscribe(PushSubscription::webPush($this->user, 'a-jmap-client', 'https://push.example.test/c'));

        $webPush = new RecordingArrivalsSender(PushTransport::WebPush);

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: []);

        self::assertCount(1, $webPush->sent, 'a client that did not opt in still gets every StateChange');
        self::assertSame('a-jmap-client', $webPush->sent[0]->deviceClientId);
    }

    public function testAnFcmPhoneIsStillToldOfAnUpdate(): void
    {
        $this->subscribe(PushSubscription::fcm($this->user, 'a-phone', 'a-device-token'));

        $fcm = new RecordingArrivalsSender(PushTransport::Fcm);

        $this->dispatch($fcm, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: []);

        self::assertCount(1, $fcm->sent, 'the phone keeps every StateChange');
        self::assertSame('a-phone', $fcm->sent[0]->deviceClientId);
    }

    public function testArrivalsForAnotherUsersAccountDoNotNotifyThisUser(): void
    {
        $this->subscribe($this->arrivalsOnlyBrowser('pwa-device'));

        $otherAccountId = $this->seedOtherUsersAccount();
        $webPush        = new RecordingArrivalsSender(PushTransport::WebPush);

        $this->dispatch($webPush, [$otherAccountId], updated: [$otherAccountId], arrived: [$otherAccountId]);

        self::assertCount(0, $webPush->sent, 'mail for someone else is not this user\'s new mail');

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: [$this->ownAccountId()]);

        self::assertCount(1, $webPush->sent, 'presence: this user\'s own arrival still notifies the PWA');
    }

    /**
     * The case the test above cannot catch: this user's own mail changed in the
     * same drain in which somebody else's mail arrived. An arrival anywhere is
     * not an arrival in this user's accounts, and judging it that way would send
     * every user's PWA a "New mail" for every other user's mail.
     */
    public function testSomeoneElsesArrivalDoesNotNotifyAUserWhoseOwnMailOnlyChanged(): void
    {
        $this->subscribe($this->arrivalsOnlyBrowser('pwa-device'));

        $otherAccountId = $this->seedOtherUsersAccount();
        $webPush        = new RecordingArrivalsSender(PushTransport::WebPush);

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: [$otherAccountId]);

        self::assertCount(0, $webPush->sent, 'a stranger\'s mail arrived; this user\'s mail only changed');

        $this->dispatch($webPush, [$this->ownAccountId()], updated: [$this->ownAccountId()], arrived: [$this->ownAccountId()]);

        self::assertCount(1, $webPush->sent, 'presence: their own arrival notifies the PWA');
    }

    /**
     * @param list<int> $accountIds the accounts whose state changed
     * @param list<int> $updated    the accounts a change was recorded for; the
     *                              dispatcher receives the same ids for both
     * @param list<int> $arrived    the accounts mail arrived in
     */
    private function dispatch(PushSenderInterface $sender, array $accountIds, array $updated, array $arrived): void
    {
        $container = self::getContainer();

        $changed = [];

        foreach ($accountIds as $accountId) {
            if (true === in_array($accountId, $updated, true)) {
                $changed[$accountId] = ['Email' => '9'];
            }
        }

        new PushDispatcher(
            $container->get(PushSubscriptionRepository::class),
            $container->get(UserRepository::class),
            new PushSenderRegistry([$sender]),
            $container->get(StateChangeBuilder::class),
            new NullLogger(),
        )->dispatch($changed, $arrived);
    }

    /** This user's account id as the int the dispatcher is handed; JmapTestCase::accountId() is the JMAP string. */
    private function ownAccountId(): int
    {
        return (int) $this->account->id;
    }

    private function arrivalsOnlyBrowser(string $deviceClientId): PushSubscription
    {
        $subscription = PushSubscription::webPush($this->user, $deviceClientId, 'https://push.example.test/p');
        $subscription->markArrivalsOnly();

        return $subscription;
    }

    /** Verified, because an unverified subscription is deliverable to nothing. */
    private function subscribe(PushSubscription $subscription): void
    {
        $subscription->verify((string) $subscription->verificationCode);
        $this->em->persist($subscription);
        $this->em->flush();
    }

    /**
     * A second user with one account, so an arrival can belong to someone else.
     */
    private function seedOtherUsersAccount(): int
    {
        $user = new User();
        $user->email = 'push-arrivals-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Other';
        $user->nameLast = 'Owner';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Other Owner';
        $account->username = 'other-owner@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);
        $this->em->flush();

        return (int) $account->id;
    }
}
