<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\DTO\Push\NotifyScope;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Notifications → "Notify for" and "Only notify for unread mail"
 * (#369), and the answer the service worker reads the second back through.
 *
 * What the worker does with the answer is JavaScript and is not tested here.
 * What is worth pinning is the server's half: Primary alone is on for somebody
 * who never chose, a box per tab and per label, the default stored back as
 * the absent key, a save that keeps what the page does not draw, a post
 * without a token refused — and an endpoint that asks about RECENT unread mail
 * under a switch that is on, so old mail and unwanted tabs do not keep a
 * notification alive.
 */
final class NotificationPreferenceTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testPrimaryAloneIsTickedForSomebodyWhoNeverChose(): void
    {
        self::assertSame([NotifyScope::PRIMARY], $this->user->notifyScopes);
        self::assertFalse($this->user->notifySkipsRead);

        $page = $this->client->request('GET', '/settings/notifications');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [NotifyScope::PRIMARY],
            $page->filter('input[name="scopes[]"]:checked')->each(static fn ($box): string => (string) $box->attr('value')),
        );
        self::assertSame('0', $page->filter('input[name="skipRead"]:checked')->attr('value'));
    }

    /** A box for each tab the reader has and each label they made — not for Sent. */
    public function testThePageOffersEveryTabAndEveryLabel(): void
    {
        $work = $this->seedLabel('Work');
        $this->seedLabel('Sent', LabelRole::Sent);

        $offered = $this->client->request('GET', '/settings/notifications')
            ->filter('input[name="scopes[]"]')
            ->each(static fn ($box): string => (string) $box->attr('value'));

        foreach (MessageCategory::cases() as $category) {
            self::assertContains(NotifyScope::category($category), $offered);
        }

        self::assertContains('label:' . $work->id, $offered, 'presence: a label of their own');
        self::assertCount(count(MessageCategory::cases()) + 1, $offered, 'and nothing for Sent or the Inbox');
    }

    public function testTickingTabsAndLabelsIsStoredAndTheDefaultIsTheAbsentKey(): void
    {
        $work = $this->seedLabel('Work');

        $this->post(['scopesSent' => '1', 'scopes' => [NotifyScope::PRIMARY, 'category:social', 'label:' . $work->id]]);

        self::assertResponseRedirects('/settings/notifications');
        self::assertSame([NotifyScope::PRIMARY, 'category:social', 'label:' . $work->id], $this->reread()->notifyScopes);

        // A post that does not mention the boxes leaves them alone.
        $this->post(['skipRead' => '1']);
        self::assertTrue($this->reread()->notifiesFor('category:social'));

        // Primary alone again is the absent key, not a stored list.
        $this->post(['scopesSent' => '1', 'scopes' => [NotifyScope::PRIMARY, 'bogus', 'label:999999']]);

        self::assertSame([NotifyScope::PRIMARY], $this->reread()->notifyScopes, 'not offered on the page, so not taken from it');
        self::assertNull($this->reread()->getSetting(User::SETTING_NOTIFY_SCOPES));
    }

    /** Nothing ticked is a choice, the one the phone can make too. */
    public function testUntickingEverythingMeansNoMailNotifies(): void
    {
        $this->post(['scopesSent' => '1']);

        self::assertSame([], $this->reread()->notifyScopes);
        self::assertFalse($this->reread()->notifiesFor(NotifyScope::PRIMARY));
    }

    /**
     * A switch the page has no box for — a label since deleted, a tab the phone
     * knows about — survives a save on the web that never mentioned it.
     */
    public function testASaveKeepsWhatThePageDoesNotDraw(): void
    {
        $this->user->notifyScopes = [NotifyScope::PRIMARY, 'label:999999'];
        $this->em->flush();

        $this->post(['scopesSent' => '1', 'scopes' => ['category:updates']]);

        self::assertEqualsCanonicalizing(['label:999999', 'category:updates'], $this->reread()->notifyScopes);
    }

    public function testSkipReadSwitchesOnAndOffAgain(): void
    {
        $this->post(['skipRead' => '1']);

        self::assertTrue($this->reread()->notifySkipsRead);

        $this->post(['skipRead' => '0']);

        // Off is the absent key, not a stored false.
        self::assertFalse($this->reread()->notifySkipsRead);
        self::assertNull($this->reread()->getSetting(User::SETTING_NOTIFY_SKIP_READ));
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->client->request('POST', '/settings/notifications', ['skipRead' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->notifySkipsRead);
    }

    /**
     * The endpoint answers "is the mail a push was about still unread": recent
     * unread mail under a switch that is on. Off, it does not even look.
     */
    public function testTheEndpointReportsRecentUnreadMailUnderASwitchThatIsOn(): void
    {
        $this->arrived('Just now', minutesAgo: 1);

        self::assertFalse($this->inboxState()['hasUnread'], 'the check is off, so nothing is looked up');

        $this->post(['skipRead' => '1']);

        $state = $this->inboxState();

        self::assertTrue($state['skipRead']);
        self::assertTrue($state['hasUnread'], 'presence: Primary mail from a minute ago is still unread');
        self::assertGreaterThan(0, $state['delay']);
    }

    /** Read meanwhile — on the phone, say — and the notification is dropped. */
    public function testMailReadMeanwhileIsNotUnread(): void
    {
        $this->post(['skipRead' => '1']);

        $message         = $this->arrived('Read on the phone', minutesAgo: 1);
        $message->seenAt = new DateTimeImmutable();
        $this->em->flush();

        self::assertFalse($this->inboxState()['hasUnread']);
    }

    /**
     * Unread mail in a tab nobody asked to hear about, or from before the push,
     * says nothing about the mail the push announced.
     */
    public function testUnwantedTabsAndOldMailDoNotKeepItAlive(): void
    {
        $this->post(['skipRead' => '1']);

        $this->arrived('A promotion', minutesAgo: 1, category: MessageCategory::Promotions);
        $this->arrived('Last week', minutesAgo: 60 * 24 * 7);

        self::assertFalse($this->inboxState()['hasUnread'], 'a promotion, and a week-old Primary message');

        $this->arrived('Primary, just now', minutesAgo: 1);

        self::assertTrue($this->inboxState()['hasUnread'], 'presence: the mail the push was about');
    }

    public function testTheEndpointIsNotForSomebodySignedOut(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->restart();

        $this->client->request('GET', '/settings/push/inbox-state');

        self::assertResponseRedirects();
    }

    /**
     * An unread message that reached the Inbox $minutesAgo — stored then, as
     * the endpoint asks by when plMail stored it, and dated then too.
     */
    private function arrived(string $subject, int $minutesAgo, MessageCategory $category = MessageCategory::Primary): Message
    {
        $thread  = $this->thread($subject, $category, sprintf('-%d minutes', $minutesAgo), unread: 1);
        $message = $thread->messages->first();
        self::assertInstanceOf(Message::class, $message);

        $message->category = $category;
        $message->addLabel($this->inbox);
        $this->em->flush();

        $this->connection->executeStatement(
            'UPDATE message SET created_at = :at WHERE id = :id',
            ['at' => new DateTimeImmutable(sprintf('-%d minutes', $minutesAgo))->format('Y-m-d H:i:s'), 'id' => $message->id],
        );
        $this->em->refresh($message);

        return $message;
    }

    /** @return array{skipRead: bool, hasUnread: bool, delay: int} */
    private function inboxState(): array
    {
        $this->client->request('GET', '/settings/push/inbox-state');

        self::assertResponseIsSuccessful();

        $state = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->refetch();

        return $state;
    }

    /** @param array<string, string|list<string>> $fields */
    private function post(array $fields): void
    {
        $page  = $this->client->request('GET', '/settings/notifications');
        $token = (string) $page->filter('form[action="/settings/notifications"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/settings/notifications', $fields + ['_token' => $token]);

        $this->refetch();
    }

    /**
     * A request runs in this kernel and may clear the entity manager, so the
     * fixtures are fetched again before a test adds mail after one.
     */
    private function refetch(): void
    {
        $this->user    = $this->reread();
        $this->account = $this->em->find(Account::class, $this->account->id) ?? $this->account;
        $this->inbox   = $this->em->find(Label::class, $this->inbox->id) ?? $this->inbox;
    }

    private function reread(): User
    {
        $id = $this->user->id;

        $this->em->clear();

        $user = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
