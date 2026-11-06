<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\HtmlBodyTextBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mail stored before body_html_text existed gets its words, its search entry
 * and a fresh embedding (#477).
 */
final class HtmlBodyTextBackfillTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private HtmlBodyTextBackfillTask $task;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
        $this->task       = self::getContainer()->get(HtmlBodyTextBackfillTask::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnOldHtmlOnlyMessageBecomesSearchable(): void
    {
        $id = $this->legacy('', '<table><tr><td>Arriving tomorrow in Gravatt</td></tr></table>');

        self::assertFalse($this->indexed($id, 'gravatt'), 'the fixture is not the state the report describes');

        self::assertSame(Command::SUCCESS, $this->runTask());

        self::assertSame('Arriving tomorrow in Gravatt', $this->connection->fetchOne('SELECT body_html_text FROM message WHERE id = ?', [$id]));
        self::assertTrue($this->indexed($id, 'gravatt'));
    }

    /** It was made from the subject and sender alone, and nothing else would ever replace it. */
    public function testTheHeadersOnlyEmbeddingIsDropped(): void
    {
        $filled = $this->legacy('', '<p>Microsoft Teams meeting</p>');
        $plain  = $this->legacy('Microsoft Teams meeting', '<p>Microsoft Teams meeting</p>');

        foreach ([$filled, $plain] as $id) {
            $this->connection->executeStatement(
                "INSERT INTO message_embedding (message_id, embedding, dimensions, model, created_at) VALUES (?, '{0.1,0.2}', 2, 'test', now())",
                [$id],
            );
        }

        $this->runTask();

        $left = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT message_id FROM message_embedding WHERE message_id IN (?, ?)',
            [$filled, $plain],
        ));

        self::assertSame([$plain], $left, 'only the message whose words changed is embedded again');
    }

    public function testAMessageWithATextPartIsLeftAlone(): void
    {
        $id = $this->legacy('Arriving tomorrow', '<p>Arriving tomorrow</p>');

        $this->runTask();

        self::assertNull($this->connection->fetchOne('SELECT body_html_text FROM message WHERE id = ?', [$id]));
    }

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }

    private function indexed(int $id, string $word): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT search_vector @@ plainto_tsquery('english', ?) FROM message WHERE id = ?",
            [$word, $id],
        );
    }

    /**
     * A message as it was before Version20261105120000: no body_html_text (the
     * flush fills it, so it is emptied again) and waiting to be indexed.
     */
    private function legacy(string $text, string $html): int
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Out for delivery';
        $message->fromAddress    = 'shop@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<%s@example.test>', uniqid('', true));
        $message->bodyText       = $text;
        $message->bodyHtml       = $html;

        $this->em->persist($message);
        $this->em->flush();

        $id = (int) $message->id;
        $this->connection->executeStatement('UPDATE message SET body_html_text = NULL, search_vector = NULL WHERE id = ?', [$id]);

        return $id;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'htmltext-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Htmltext';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Htmltext Fixture';
        $account->username       = 'htmltext-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }
}
