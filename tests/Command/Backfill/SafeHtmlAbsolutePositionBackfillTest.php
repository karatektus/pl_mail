<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlAbsolutePositionBackfillTask;
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
 * How a print-page mail stored before #626 stops covering its own text.
 *
 * bodyHtmlSafe still has the banner's `position: absolute`, which the
 * sanitizer kept in the reading pane until #626. Sanitising again from
 * bodyHtml repairs it, with no mail server involved.
 */
final class SafeHtmlAbsolutePositionBackfillTest extends KernelTestCase
{
    private const string BANNER = '<html><body><table style="position: absolute; width: 100%"><tr><td>'
        . '<div style="position: absolute; width: 595pt"><img src="https://media.example.test/banner.jpg" style="display: block"></div>'
        . '</td></tr></table><p>Beste,</p></body></html>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlAbsolutePositionBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlAbsolutePositionBackfillTask::class);
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

    public function testItTakesTheAbsolutePositionOut(): void
    {
        // What the sanitizer used to write: the sender's styles, absolute and all.
        $message = $this->message(self::BANNER, '<table style="position: absolute; width: 100%"><tr><td>'
            . '<div style="position: absolute; width: 595pt">banner</div></td></tr></table><p>Beste,</p>');
        $id      = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        $safe = (string) $this->reload($id)->bodyHtmlSafe;

        self::assertStringNotContainsString('absolute', $safe);
        self::assertStringContainsString('width: 595pt', $safe);
        self::assertStringContainsString('Beste,', $safe);
    }

    /** The sender's own copy is what the repair reads, and stays as it was. */
    public function testItLeavesTheRawBodyAlone(): void
    {
        $message = $this->message(self::BANNER, '<div style="position: absolute">x</div>');
        $id      = (int) $message->id;

        $this->runTask();

        self::assertSame(self::BANNER, $this->reload($id)->bodyHtml);
    }

    /**
     * The walk matches the word anywhere in the shown copy, the text included,
     * so it has to be a no-op on a body that was never positioned.
     */
    public function testItLeavesAnUnaffectedBodyUnchanged(): void
    {
        $message = $this->message('<html><body><p>That is absolutely fine.</p></body></html>', '');

        $this->sanitizer->sanitize($message);
        $this->em->flush();

        $id     = (int) $message->id;
        $before = (string) $message->bodyHtmlSafe;

        self::assertStringContainsString('absolute', $before);

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
        $message->subject        = 'Uw polis';
        $message->fromAddress    = 'no-reply@example.test';
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
        $user->email     = 'absolute-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Absolute';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Absolute Fixture';
        $account->username       = 'absolute-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;
    }
}
