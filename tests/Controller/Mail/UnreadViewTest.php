<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Unread view: every unread conversation, whatever it is filed under, in
 * one list — and a sidebar row nobody gets until they switch it on.
 */
final class UnreadViewTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Fixtures live in a transaction this test rolls back, and a rebooted
        // kernel takes the connection holding it with them.
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

    /**
     * Archived mail is in it — that is the difference from the Inbox's own
     * unread filter. What has been put out of the way is not: the bin, spam,
     * and a snooze, which is a request not to be shown the mail yet.
     */
    public function testItListsUnreadMailFromEveryFolderButTheOnesPutAway(): void
    {
        $inboxed  = $this->thread('Unread in the inbox', unread: 1);
        $archived = $this->filedUnder($this->thread('Unread and archived', unread: 1), LabelRole::Archive);
        $read     = $this->thread('Already read');
        $binned   = $this->filedUnder($this->thread('Unread in the bin', unread: 1), LabelRole::Trash);
        $spam     = $this->filedUnder($this->thread('Unread spam', unread: 1), LabelRole::Spam);
        $snoozed  = $this->filedUnder($this->thread('Unread and snoozed', unread: 1), LabelRole::Snoozed);

        $page = $this->client->request('GET', '/mail/unread');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $listed = static fn (MessageThread $thread): int => $page->filter('#thread_' . $thread->id)->count();

        self::assertSame(1, $listed($inboxed));
        self::assertSame(1, $listed($archived), 'across folders, not the Inbox alone');
        self::assertSame(0, $listed($read));
        self::assertSame(0, $listed($binned));
        self::assertSame(0, $listed($spam));
        self::assertSame(0, $listed($snoozed), 'a snooze is a request not to be shown it');
    }

    public function testTheRowIsOptInAndCountsTheList(): void
    {
        $this->thread('One', unread: 1);
        $this->filedUnder($this->thread('Two', unread: 1), LabelRole::Archive);

        // Opening label settings is what creates the label — hidden, so the
        // row stays away until it is switched on.
        $this->client->request('GET', '/settings?section=labels');

        $unread = $this->unreadLabel();

        self::assertNotNull($unread, 'label settings lists it, so it has to exist');
        self::assertFalse($unread->isVisible);
        self::assertCount(0, $unread->bindings, 'bound to no account, so no JMAP client sees it');

        self::assertSame(0, $this->client->request('GET', '/mail/inbox')->filter('#sidebar a[href="/mail/unread"]')->count());

        // Read again: the request cleared the entity manager on its way out,
        // and a change to the detached copy would flush nothing.
        $unread = $this->unreadLabel();
        $unread->isVisible = true;
        $this->em->flush();

        $sidebar = $this->client->request('GET', '/mail/inbox')->filter('#sidebar');

        self::assertSame(1, $sidebar->filter('a[href="/mail/unread"]')->count());
        self::assertSame('2', trim($sidebar->filter('[data-count-key="unread"]')->text()));
    }

    private function unreadLabel(): ?Label
    {
        return $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => LabelRole::Unread]);
    }

    private function filedUnder(MessageThread $thread, LabelRole $role): MessageThread
    {
        $label = $this->em->getRepository(Label::class)->findOneBy(['usr' => $this->user, 'role' => $role])
            ?? $this->seedLabel($role->displayName(), $role);

        $thread->removeLabel($this->inbox);
        $thread->addLabel($label);

        $this->em->flush();

        return $thread;
    }
}
