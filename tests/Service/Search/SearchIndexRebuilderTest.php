<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Search\SearchIndexRebuilder;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * search_vector is kept by a trigger and rebuilt in the background
 * (Version20261105120000, #477): what the trigger does and does not do, and
 * the order the rebuild takes the waiting rows in.
 */
final class SearchIndexRebuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

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

    public function testNewMailIsIndexedAsItIsWritten(): void
    {
        $id = $this->store('The pelican audit is attached.');

        self::assertTrue($this->indexed($id, 'pelican'));
    }

    public function testAnIndexedRowFollowsAChangedBody(): void
    {
        $id = $this->store('The pelican audit is attached.');

        $this->connection->executeStatement("UPDATE message SET body_text = 'A flamingo instead.' WHERE id = ?", [$id]);

        self::assertTrue($this->indexed($id, 'flamingo'));
        self::assertFalse($this->indexed($id, 'pelican'));
    }

    /**
     * A row still waiting stays waiting: a vector built by an unrelated update
     * would be built before its body_html_text, and the rebuild would never
     * come back for it.
     */
    public function testAWaitingRowIsLeftToTheRebuild(): void
    {
        $id = $this->waiting('<p>Arriving tomorrow in Gravatt</p>');

        $this->connection->executeStatement("UPDATE message SET subject = 'Renamed', flags = '[]' WHERE id = ?", [$id]);

        self::assertNull($this->connection->fetchOne('SELECT search_vector FROM message WHERE id = ?', [$id]));

        new SearchIndexRebuilder($this->connection)->rebuild();

        self::assertTrue($this->indexed($id, 'gravatt'), 'the rebuild did not index the HTML body');
        self::assertTrue($this->indexed($id, 'renamed'));
    }

    /** Newest first: last week's mail is searchable before 2014's. */
    public function testTheRebuildTakesTheNewestFirst(): void
    {
        $older = $this->waiting('<p>older</p>');
        $newer = $this->waiting('<p>newer</p>');

        // One row per batch, and a budget that is spent after the first.
        $done = new SearchIndexRebuilder($this->connection, 1)->rebuild(0.000001);

        self::assertSame(1, $done['indexed']);
        self::assertTrue($this->indexed($newer, 'newer'));
        self::assertNull($this->connection->fetchOne('SELECT search_vector FROM message WHERE id = ?', [$older]));
    }

    private function indexed(int $id, string $word): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT search_vector @@ plainto_tsquery('english', ?) FROM message WHERE id = ?",
            [$word, $id],
        );
    }

    private function store(string $text, string $html = ''): int
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

        return (int) $message->id;
    }

    /** An HTML-only message as the migration left it: no words stored, not indexed. */
    private function waiting(string $html): int
    {
        $id = $this->store('', $html);

        $this->connection->executeStatement('UPDATE message SET body_html_text = NULL, search_vector = NULL WHERE id = ?', [$id]);

        return $id;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'rebuild-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rebuild';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Rebuild Fixture';
        $account->username       = 'rebuild-fixture@example.test';
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
