<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Push;

use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\PushTransport;
use App\Domain\Interface\PushSenderInterface;
use App\Entity\Mail\Account;
use App\Entity\User\PushSubscription;
use App\Entity\User\User;
use App\Jmap\Protocol\StateChangeBuilder;
use App\Jmap\Push\PushDispatcher;
use App\Jmap\Push\PushSenderRegistry;
use App\Repository\User\PushSubscription\PushSubscriptionRepository;
use App\Repository\User\User\UserRepository;
use App\Tests\Jmap\JmapTestCase;
use Psr\Log\NullLogger;

/**
 * The in-app browser/PWA device wants "new mail" and nothing else, so its row
 * is marked arrivals-only and is told only when mail actually arrived in one of
 * its user's accounts, under a switch that user has on (#369). Every other
 * subscription keeps receiving every change.
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
     * Only Primary notifies for a user who never chose (#369): a promotion
     * arriving says nothing to their browser.
     */
    public function testByDefaultOnlyPrimaryMailWakesTheBrowser(): void
    {
        $this->subscribe($this->arrivalsOnlyBrowser('pwa-device'));

        $webPush = new RecordingArrivalsSender(PushTransport::WebPush);
        $own     = $this->ownAccountId();

        $this->dispatch($webPush, [$own], updated: [$own], arrived: [$own], scopes: ['category:promotions']);

        self::assertCount(0, $webPush->sent, 'a promotion, and nobody asked to hear about those');

        $this->dispatch($webPush, [$own], updated: [$own], arrived: [$own], scopes: ['category:promotions', NotifyScope::PRIMARY]);

        self::assertCount(1, $webPush->sent, 'presence: one of the arrivals is Primary mail');
    }

    /** A user who switched a label on is told of mail under it, tab or no tab. */
    public function testASwitchedOnLabelWakesTheBrowser(): void
    {
        $this->subscribe($this->arrivalsOnlyBrowser('pwa-device'));
        $this->user->notifyScopes = ['label:42'];
        $this->em->flush();

        $webPush = new RecordingArrivalsSender(PushTransport::WebPush);
        $own     = $this->ownAccountId();

        $this->dispatch($webPush, [$own], updated: [$own], arrived: [$own], scopes: [NotifyScope::PRIMARY, 'label:7']);

        self::assertCount(0, $webPush->sent, 'Primary is off for them, and label 7 is not theirs to hear about');

        $this->dispatch($webPush, [$own], updated: [$own], arrived: [$own], scopes: ['label:42']);

        self::assertCount(1, $webPush->sent, 'presence: archived mail under Work, which they switched on');
    }

    /**
     * The switches narrow the browser and nothing else: the phone's pushes also
     * drive its sync, so it is told of every change and applies the switches
     * itself.
     */
    public function testAnFcmPhoneIsToldWhateverTheSwitches(): void
    {
        $this->subscribe(PushSubscription::fcm($this->user, 'a-phone', 'a-device-token'));
        $this->user->notifyScopes = [];
        $this->em->flush();

        $fcm = new RecordingArrivalsSender(PushTransport::Fcm);
        $own = $this->ownAccountId();

        $this->dispatch($fcm, [$own], updated: [$own], arrived: [$own], scopes: ['category:promotions']);

        self::assertCount(1, $fcm->sent, 'the phone keeps every StateChange');
    }

    /**
     * @param list<int>    $accountIds the accounts whose state changed
     * @param list<int>    $updated    the accounts a change was recorded for; the
     *                                 dispatcher receives the same ids for both
     * @param list<int>    $arrived    the accounts mail arrived in
     * @param list<string> $scopes     what it arrived under, in each of them
     */
    private function dispatch(PushSenderInterface $sender, array $accountIds, array $updated, array $arrived, array $scopes = [NotifyScope::PRIMARY]): void
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
        )->dispatch($changed, array_fill_keys($arrived, $scopes));
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
