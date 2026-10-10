<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A selection in Spam can be deleted for good, and nothing else can.
 *
 * The row's own button has always done this one conversation at a time
 * (/status/{type}/{id}/purge). A ticked selection could only be trashed, which
 * for spam is a detour: the mail lands in the bin and has to be deleted a second
 * time from there.
 *
 * The claim worth pinning is the guard, not the deletion. The route destroys
 * mail with no undo, so it has to refuse everything the single-row route
 * refuses — mail that is not already in the bin or in Spam, somebody else's mail
 * — and refuse the WHOLE request when one thread in it is not allowed, rather
 * than delete the part that was. A selection is edited in a browser before it is
 * posted, and "everything except the one it should not have touched" is still a
 * deletion nobody asked for.
 *
 * Against the real container and database, as BulkStarTest is: what matters is
 * what is left in the tables once the request has run.
 */
final class BulkPurgeTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Label $spam;

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
        $this->spam    = $this->seedLabel('Spam', LabelRole::Spam);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testASelectionInSpamIsDeletedForGoodAndTheRestOfSpamIsNot(): void
    {
        $first  = $this->spamThread('Spam one');
        $second = $this->spamThread('Spam two');
        $kept   = $this->spamThread('Spam not ticked');

        $firstId   = $first->id;
        $secondId  = $second->id;
        $keptId    = $kept->id;
        $messageId = $first->messages->first()->id;

        $this->post(['ids' => [$firstId, $secondId]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Read back rather than off the instances held here: the request
        // cleared the entity manager on its way out.
        $this->em->clear();

        self::assertNull($this->em->find(MessageThread::class, $firstId), 'the ticked conversation is gone');
        self::assertNull($this->em->find(MessageThread::class, $secondId), 'and so is the other one');
        self::assertNull($this->em->find(Message::class, $messageId), 'with its messages, not only the thread row');
        self::assertNotNull(
            $this->em->find(MessageThread::class, $keptId),
            'while spam that was not ticked is untouched — the presence companion to the two assertions above',
        );
    }

    /**
     * The rows are taken out of the list by the ids the request named.
     *
     * Not by the thread objects: once they are purged Doctrine has cleared their
     * ids, and a stream built from them would address `thread_` and remove
     * nothing, leaving every deleted row on screen until the list next reloads.
     */
    public function testTheAnswerRemovesEachRowByItsId(): void
    {
        $first  = $this->spamThread('Spam one');
        $second = $this->spamThread('Spam two');

        $ids = [$first->id, $second->id];

        $this->post(['ids' => $ids]);

        $body = (string) $this->client->getResponse()->getContent();

        foreach ($ids as $id) {
            self::assertStringContainsString(
                sprintf('<turbo-stream action="remove" target="thread_%d">', $id),
                $body,
            );
        }
    }

    public function testASelectionHoldingMailThatIsNotDiscardedIsRefusedAsAWhole(): void
    {
        $spam  = $this->spamThread('Spam');
        $inbox = $this->thread('An ordinary conversation');

        $spamId  = $spam->id;
        $inboxId = $inbox->id;

        $this->post(['ids' => [$spamId, $inboxId]]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $inboxId), 'inbox mail is never deleted for good');
        self::assertNotNull(
            $this->em->find(MessageThread::class, $spamId),
            'and the spam beside it is not deleted either: the request is refused, not trimmed',
        );
    }

    public function testSomebodyElsesSpamIsRefused(): void
    {
        $mine = $this->spamThread('Mine');

        $owner         = $this->user;
        $ownerAccount  = $this->account;
        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();

        $theirs = $this->spamThread('Theirs');

        $this->user    = $owner;
        $this->account = $ownerAccount;

        $mineId   = $mine->id;
        $theirsId = $theirs->id;

        $this->post(['ids' => [$mineId, $theirsId]]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $theirsId), 'their conversation survives');
        self::assertNotNull($this->em->find(MessageThread::class, $mineId), 'and mine is not deleted around the refusal');
    }

    /**
     * "Select all N" has no caller here: a whole view goes to a worker, and
     * deleting for good from one is a decision this endpoint does not make.
     */
    public function testAWholeViewSelectionIsRefused(): void
    {
        $spam   = $this->spamThread('Spam');
        $spamId = $spam->id;

        $this->post(['all' => true, 'scope' => 'spam', 'value' => '']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $spamId));
    }

    /** One conversation of one message, carrying Spam and not the Inbox. */
    private function spamThread(string $subject): MessageThread
    {
        $thread = $this->thread($subject);

        $thread->removeLabel($this->inbox);
        $thread->addLabel($this->spam);

        foreach ($thread->messages as $message) {
            $message->addLabel($this->spam);
        }

        $this->em->flush();

        return $thread;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): void
    {
        // The `ajax` token, read the way the real caller reads it — from the
        // layout's meta tag. See BulkMoveGuardTest::post().
        $token = (string) $this->client->request('GET', '/mail/inbox')
            ->filter('meta[name="csrf-token"]')
            ->attr('content');

        $this->client->request(
            'POST',
            '/status/bulk/purge',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            (string) json_encode($body),
        );
    }
}
