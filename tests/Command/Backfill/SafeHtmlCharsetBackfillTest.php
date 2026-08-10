<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlCharsetBackfillTask;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Mail\MailBodySanitizer;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How mail already mangled on screen gets its umlauts back.
 *
 * This is the question the previous encoding fix could not answer well: there,
 * the bytes had been converted wrongly before the app ever saw them, and a
 * resync only ran the same wrong conversion again. Here the damage is entirely
 * local. bodyHtml was stored correctly and only the derived bodyHtmlSafe is
 * wrong, so the repair needs no mail server and no re-fetch — it is the
 * sanitizer, run again, now that it re-tags the declaration first.
 */
final class SafeHtmlCharsetBackfillTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlCharsetBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlCharsetBackfillTask::class);
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

    public function testItRepairsABodyMangledByTheDocumentsOwnCharset(): void
    {
        $message = $this->message(
            '<html><head><meta charset="iso-8859-1"></head><body><p>Grüße</p></body></html>',
            // What the sanitizer used to write, byte for byte.
            '<p>GrÃ¼ÃŸe</p>',
        );

        $id = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        $repaired = $this->reload($id);

        self::assertStringContainsString('Grüße', (string) $repaired->bodyHtmlSafe);
        self::assertStringNotContainsString('GrÃ¼ÃŸe', (string) $repaired->bodyHtmlSafe);
    }

    /** The sender's own copy is the extractor's input and stays as it was. */
    public function testItLeavesTheRawBodyAlone(): void
    {
        $html    = '<html><head><meta charset="iso-8859-1"></head><body><p>Grüße</p></body></html>';
        $message = $this->message($html, '<p>GrÃ¼ÃŸe</p>');

        $id = (int) $message->id;

        $this->runTask();

        self::assertSame($html, $this->reload($id)->bodyHtml);
    }

    /**
     * The walk is over every body that declares a charset, because no query
     * can pick out the damaged ones — so it has to be safe on the rest.
     */
    public function testItLeavesAnAlreadyCorrectBodyUnchanged(): void
    {
        $message = $this->message(
            '<html><head><meta charset="utf-8"></head><body><p>Grüße</p></body></html>',
            '',
        );

        // Seeded by the sanitizer itself, so "unchanged" means byte for byte
        // rather than merely still readable.
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
        $message->subject        = 'Grüße';
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
        $user->email     = 'retag-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Retag';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Retag Fixture';
        $account->username       = 'retag-fixture@example.test';
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
