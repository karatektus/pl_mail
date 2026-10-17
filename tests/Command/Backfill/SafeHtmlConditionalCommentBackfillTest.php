<?php

declare(strict_types=1);

namespace App\Tests\Command\Backfill;

use App\Command\Backfill\SafeHtmlConditionalCommentBackfillTask;
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
 * How mail that shows `<![endif]-->` as text gets rid of it.
 *
 * The stored copy is what is wrong, not the mail: bodyHtml still has the
 * conditional comment as the sender wrote it, and bodyHtmlSafe has its closing
 * marker as escaped text, written after the inliner fell back to the HTML 4
 * parser (#175). Sanitising again repairs it, with no mail server involved.
 */
final class SafeHtmlConditionalCommentBackfillTest extends KernelTestCase
{
    private const string PARCEL = '<html><body><p style="margin: 0 !important"><!--[if !mso]><!-->Je pakje komt eraan<![endif]--></p></body></html>';

    private EntityManagerInterface $em;
    private Connection $connection;
    private SafeHtmlConditionalCommentBackfillTask $task;
    private MailBodySanitizer $sanitizer;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->task       = $container->get(SafeHtmlConditionalCommentBackfillTask::class);
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

    public function testItTakesTheMarkerOutOfTheStoredBody(): void
    {
        $message = $this->message(
            self::PARCEL,
            // What the sanitizer used to write.
            '<p style="margin: 0 !important">Je pakje komt eraan&lt;![endif]--&gt;</p>',
        );
        $id = (int) $message->id;

        self::assertSame(Command::SUCCESS, $this->runTask());

        $safe = (string) $this->reload($id)->bodyHtmlSafe;

        self::assertStringNotContainsString('endif', $safe);
        self::assertStringContainsString('Je pakje komt eraan', $safe);
    }

    /**
     * The walk is chosen by what the damage looks like, so a body showing
     * only the opening half of the marker is in it too.
     */
    public function testItFindsTheOpeningMarkerAlone(): void
    {
        $message = $this->message(
            '<html><body><p><![if !mso]>Ruimte</p></body></html>',
            '<p>![if !mso]&gt;Ruimte</p>',
        );
        $id = (int) $message->id;

        $this->runTask();

        self::assertStringNotContainsString('[if', (string) $this->reload($id)->bodyHtmlSafe);
    }

    /** The sender's own copy is what the repair reads, and stays as it was. */
    public function testItLeavesTheRawBodyAlone(): void
    {
        $message = $this->message(self::PARCEL, '&lt;![endif]--&gt;');
        $id      = (int) $message->id;

        $this->runTask();

        self::assertSame(self::PARCEL, $this->reload($id)->bodyHtml);
    }

    /** A body that was rendered right comes out of the repair as it went in. */
    public function testItLeavesAnUnaffectedBodyUnchanged(): void
    {
        $message = $this->message(self::PARCEL, '');

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
        $message->subject        = 'We leveren je pakje op 16-10-2026';
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
        $user->email     = 'condcomment-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Condcomment';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'Condcomment Fixture';
        $account->username       = 'condcomment-fixture@example.test';
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
