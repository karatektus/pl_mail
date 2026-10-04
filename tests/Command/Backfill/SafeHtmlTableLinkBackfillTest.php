<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlTableLinkBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How mail whose cards stopped being links gets them back.
 *
 * The stored copy is what is wrong, not the mail: bodyHtml still has the link
 * around its table, and bodyHtmlSafe has an empty link beside it, written by a
 * parser that could not keep the two together. Sanitising again repairs it,
 * with no mail server involved, now that the inliner parses as a browser does.
 */
final class SafeHtmlTableLinkBackfillTest extends KernelTestCase
{
    private const string CARD = '<html><body><a href="https://jobs.example.test/1"><table><tr><td>Senior System Engineer</td></tr></table></a></body></html>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlTableLinkBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlTableLinkBackfillTask::class);
        $this->sanitizer  = $container->get(MailBodySanitizer::class);

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

    public function testItPutsTheCardBackInsideItsLink(): void
    {
        $message = $this->message(
            self::CARD,
            // What the sanitizer used to write: the link, closed before its table.
            '<a href="https://jobs.example.test/1" target="_blank" rel="noopener noreferrer"></a><table><tbody><tr><td>Senior System Engineer</td></tr></tbody></table>',
        );
        $id = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        $link = HTMLDocument::createFromString((string) $this->reload($id)->bodyHtmlSafe, LIBXML_NOERROR, 'UTF-8')->querySelector('a[href]');

        self::assertNotNull($link);
        self::assertStringContainsString('Senior System Engineer', (string) $link->textContent);
    }

    /** The sender's own copy is what the repair reads, and stays as it was. */
    public function testItLeavesTheRawBodyAlone(): void
    {
        $message = $this->message(self::CARD, '');
        $id      = (int) $message->id;

        $this->runTask();

        self::assertSame(self::CARD, $this->reload($id)->bodyHtml);
    }

    /**
     * The walk is over every body with a table after a link, which is far more
     * mail than was ever damaged — so it has to be a no-op on the rest.
     */
    public function testItLeavesAnUnaffectedBodyUnchanged(): void
    {
        $message = $this->message(
            '<html><body><a href="https://example.test/">Hier</a><table><tr><td>daneben</td></tr></table></body></html>',
            '',
        );

        $this->sanitizer->sanitize($message);
        $this->em->flush();

        $id     = (int) $message->id;
        $before = (string) $message->bodyHtmlSafe;

        $this->runTask();

        self::assertSame($before, (string) $this->reload($id)->bodyHtmlSafe);
    }

    private function runTask(): int
    {
        return $this->task->run(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }

    private function reload(int $id): Message
    {
        $message = $this->em->getRepository(Message::class)->find($id);

        self::assertInstanceOf(Message::class, $message);

        return $message;
    }

    private function message(string $bodyHtml, string $bodyHtmlSafe): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->subject        = 'Neue Jobs';
        $message->fromAddress    = 'sender@example.test';
        $message->receivedAt     = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->messageId      = sprintf('<%s@example.test>', uniqid('', true));
        $message->bodyHtml       = $bodyHtml;
        $message->bodyHtmlSafe   = $bodyHtmlSafe;

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'tablelink-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Tablelink';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Tablelink Fixture';
        $account->username       = 'tablelink-fixture@example.test';
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
