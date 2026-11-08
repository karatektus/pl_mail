<?php

declare(strict_types=1);

namespace App\Tests\Entity\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Message::$bodyHtmlText follows the bodies on every flush that changed one,
 * and only then (#477).
 */
final class MessageBodyHtmlTextTest extends KernelTestCase
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

    public function testAnHtmlOnlyMessageIsStoredWithItsWords(): void
    {
        $id = $this->store('', '<p>Out for <b>delivery</b></p><style>p{}</style>');

        self::assertSame('Out for delivery', $this->column($id));
    }

    /** Mail with a text part costs nothing: its words are in body_text. */
    public function testAMessageWithATextPartStoresNothing(): void
    {
        $id = $this->store('Out for delivery', '<p>Out for delivery</p>');

        self::assertNull($this->column($id));
    }

    /** Graph's bodyText is a 255-character preview, so its HTML is read regardless. */
    public function testAGraphMessageIsReadFromItsHtml(): void
    {
        $id = $this->store('Preview only…', '<p>The whole mail, tracking 1Z999</p>', graphId: 'AAMk-1');

        self::assertSame('The whole mail, tracking 1Z999', $this->column($id));
    }

    /** The sanitised copy is built after the row, and is the one read once it exists. */
    public function testALaterBodyChangeIsFollowed(): void
    {
        $message = $this->message('', '<p>Hello <span style="display:none">hidden preheader</span></p>');
        $this->em->persist($message);
        $this->em->flush();

        $message->bodyHtmlSafe = '<p>Hello</p>';
        $this->em->flush();

        self::assertSame('Hello', $this->column((int) $message->id));
    }

    /** An encrypted body is blanked before the row is written, and so are its words. */
    public function testABlankedBodyHasNoWords(): void
    {
        $message = $this->message('', '<p>secret</p>');
        $this->em->persist($message);
        $this->em->flush();

        $message->bodyHtml = '';
        $this->em->flush();

        self::assertNull($this->column((int) $message->id));
    }

    /**
     * A row loaded from before the column existed is left to the backfill:
     * an unrelated flush does not rewrite it.
     */
    public function testALoadedRowIsNotRewrittenByAnUnrelatedFlush(): void
    {
        $id = $this->store('', '<p>Old mail</p>');
        $this->connection->executeStatement('UPDATE message SET body_html_text = NULL WHERE id = ?', [$id]);
        $this->em->clear();

        $message = $this->em->getRepository(Message::class)->find($id);
        self::assertInstanceOf(Message::class, $message);

        $message->subject = 'Renamed';
        $this->em->flush();

        self::assertNull($this->column($id));
    }

    private function store(string $text, string $html, ?string $graphId = null): int
    {
        $message          = $this->message($text, $html);
        $message->graphId = $graphId;

        $this->em->persist($message);
        $this->em->flush();

        return (int) $message->id;
    }

    private function column(int $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT body_html_text FROM message WHERE id = ?', [$id]);

        return false === $value ? null : $value;
    }

    private function message(string $text, string $html): Message
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

        return $message;
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
