<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Job\BackgroundJob;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private Label $trash;

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

        // Deleting for good is the user's switch and is off by default
        // (DeleteNowSettingTest pins that). This class is about what the route
        // does once somebody has turned it on.
        $this->user->deleteNowButtons = true;
        $this->em->flush();

        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->spam    = $this->seedLabel('Spam', LabelRole::Spam);
        $this->trash   = $this->seedLabel('Trash', LabelRole::Trash);

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
     * The bin is a place mail is deleted for good from, exactly as Spam is.
     *
     * The toolbar above a selection in the bin offers Delete forever for the
     * same reason it does in Spam: its trash Delete posts a trash for mail that
     * already carries Trash, which changes nothing on the server and still
     * tells the page to drop the rows. That only works while this route accepts
     * the bin, which is what this pins — and with a Spam thread beside it, so a
     * guard narrowed to "only the bin" would fail the other test and one
     * narrowed to "only Spam" would fail this one.
     */
    public function testASelectionInTheBinIsDeletedForGood(): void
    {
        $binned = $this->binThread('In the bin');
        $kept   = $this->binThread('In the bin, not ticked');

        $binnedId  = $binned->id;
        $keptId    = $kept->id;
        $messageId = $binned->messages->first()->id;

        $this->post(['ids' => [$binnedId]]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        self::assertNull($this->em->find(MessageThread::class, $binnedId), 'the ticked conversation is gone');
        self::assertNull($this->em->find(Message::class, $messageId), 'with its messages');
        self::assertNotNull(
            $this->em->find(MessageThread::class, $keptId),
            'while what was not ticked stays in the bin — the presence companion',
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
     * "Select all N" in Spam is how Spam is emptied: the request starts a job
     * and deletes nothing itself. What the job then does is
     * RunBulkStatusHandlerTest's.
     */
    public function testAWholeViewOfSpamStartsAJobAndDeletesNothingInTheRequest(): void
    {
        $spam   = $this->spamThread('Spam');
        $spamId = $spam->id;

        $this->post(['all' => true, 'scope' => 'spam', 'value' => '']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $job = $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user]);

        self::assertNotNull($job, 'no job was started');
        self::assertSame(JobKind::Purge, $job->kind);
        self::assertSame('spam', $job->view['scope']);

        $this->em->clear();

        self::assertNotNull($this->em->find(MessageThread::class, $spamId), 'the request itself deletes nothing');
    }

    /**
     * Only Spam and the bin can be emptied. Every other view can hold mail
     * that was never thrown away, and "everything in it" has no list of ids
     * for the guard to read — so it is refused before a job exists.
     */
    #[DataProvider('viewsThatCannotBeEmptied')]
    public function testAWholeViewOfAnythingElseIsRefused(string $scope, string $value): void
    {
        $this->post(['all' => true, 'scope' => $scope, 'value' => $value]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNull(
            $this->em->getRepository(BackgroundJob::class)->findOneBy(['usr' => $this->user]),
            'a job was started for a view that must not be emptied',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function viewsThatCannotBeEmptied(): iterable
    {
        yield 'the inbox' => ['inbox', 'primary'];
        yield 'the archive' => ['archive', ''];
        yield 'a search' => ['search', 'spam'];
        yield 'no view named' => ['', ''];
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

    /** One conversation of one message, carrying Trash and not the Inbox. */
    private function binThread(string $subject): MessageThread
    {
        $thread = $this->thread($subject);

        $thread->removeLabel($this->inbox);
        $thread->addLabel($this->trash);

        foreach ($thread->messages as $message) {
            $message->addLabel($this->trash);
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
