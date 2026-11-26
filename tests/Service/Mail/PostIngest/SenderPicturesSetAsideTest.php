<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ResolveSenderPictureMessage;
use App\Repository\Mail\Message\MessageSenderPictureRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\Label\LabelResolver;
use App\Service\Mail\PostIngest\SenderPicturesStep;
use App\Tests\Support\Mail\SeedsFolderLabels;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Mail in Spam or Trash never starts a sender picture lookup (#639).
 *
 * A lookup is a request to the sender's own domain — its BIMI logo, its
 * website's icon — and for a spammer that request is the tracking pixel by
 * another route: proof the address is read, sent from a server the reader
 * never told to. Mail in the bin is mail nobody is going to look at. Both
 * ways a lookup starts are pinned: mail arriving (SenderPicturesStep) and the
 * backfill when a source is switched on (recentSenders()).
 *
 * Against the real container and database, rolled back: the backfill's
 * exclusion is a NOT EXISTS over the label join, which only Postgres can
 * answer.
 */
final class SenderPicturesSetAsideTest extends KernelTestCase
{
    use SeedsFolderLabels;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em            = self::getContainer()->get(EntityManagerInterface::class);
        $this->labelResolver = self::getContainer()->get(LabelResolver::class);
        $this->connection    = self::getContainer()->get(Connection::class);

        // Never committed, so the suite leaves nothing behind.
        $this->connection->beginTransaction();

        $this->seedFolderUser();

        $settings                 = self::getContainer()->get(SenderPictureSettingsRepository::class)->currentOrNew();
        $settings->lookupsEnabled = true;
        $this->em->persist($settings);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testArrivingMailInSpamOrTrashQueuesNoLookup(): void
    {
        $messages = $this->mailFromThreeSenders();

        self::getContainer()->get(SenderPicturesStep::class)
            ->afterCommit(new PostIngestResult(array_values($messages), [], [], true));

        $transport = self::getContainer()->get('messenger.transport.maintenance');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $subjects = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof ResolveSenderPictureMessage) {
                $subjects[] = $message->subject;
            }
        }

        self::assertContains('kept.example', $subjects, 'mail in the inbox is looked up');
        self::assertNotContains('spammer.example', $subjects, 'nothing may be asked of a domain whose mail is in Spam');
        self::assertNotContains('binned.example', $subjects, 'nothing is looked up for mail in Trash');
    }

    public function testTheBackfillSkipsSendersWhoseMailIsInSpamOrTrash(): void
    {
        $this->mailFromThreeSenders();

        $senders = self::getContainer()->get(MessageSenderPictureRepository::class)
            ->recentSenders($this->user, new \DateTimeImmutable('-1 day'), 100);

        self::assertSame(['news@kept.example'], $senders);
    }

    /** @return array<string, Message> one message each in Inbox, Spam and Trash */
    private function mailFromThreeSenders(): array
    {
        $messages = [];

        foreach (['kept' => LabelRole::Inbox, 'spammer' => LabelRole::Spam, 'binned' => LabelRole::Trash] as $name => $role) {
            $message              = $this->messageIn($this->systemLabel($role), $name);
            $message->fromAddress = sprintf('news@%s.example', $name);
            $messages[$name]      = $message;
        }

        $this->em->flush();

        return $messages;
    }
}
